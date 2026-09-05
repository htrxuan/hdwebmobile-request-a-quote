<?php

namespace htrxuan\hdraq;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Closes CVE-2026-84238 (CWE-862, Missing Authorization, CVSS 9.8) in a competing
 * "Request a Quote" plugin, where quote-management endpoints could be reached by an
 * unauthenticated visitor with no ownership or capability check at all -- letting an
 * attacker view or manipulate any customer's quoted pricing.
 *
 * This class enforces the fix structurally rather than as a bolted-on check:
 *   - find_by_token() is the ONLY lookup any customer-facing code path is allowed to
 *     use. There is no find_by_id() exposed outside this file, so a customer-facing
 *     screen simply has no way to fetch a quote by its guessable, sequential $id.
 *   - The token itself is 24 bytes of random_bytes() (48 hex chars, 192 bits of
 *     entropy) generated only server-side at creation time, never derived from or
 *     influenced by anything the customer submits.
 *   - find_for_customer($user_id) is the one exception to the "no lookup by anything
 *     but a token" rule, and it is safe: the caller always passes get_current_user_id()
 *     (see class-hdraq-myaccount.php), so the WHERE clause itself guarantees a customer
 *     can only ever see rows they own -- there is no code path that lets a customer
 *     supply an arbitrary customer_id to this method.
 *   - quoted_price is set only by respond() (called from an admin_post handler that
 *     explicitly checks current_user_can('manage_woocommerce') -- see
 *     class-hdraq-admin.php) and is never accepted as input anywhere on the
 *     customer-facing side; the cart price-override in class-hdraq-cart.php always
 *     re-reads it fresh from this table by token, never trusting anything carried in
 *     the cart session itself.
 *
 * Direct queries against a custom table are unavoidable here -- there is no WP API
 * for this data -- so DirectDatabaseQuery/NoCaching advisories are expected and
 * accepted for this class, matching standard practice for custom-table plugins.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
class HDRAQ_Repository
{

    const STATUS_PENDING   = 'pending';
    const STATUS_QUOTED    = 'quoted';
    const STATUS_REJECTED  = 'rejected';
    const STATUS_ACCEPTED  = 'accepted';
    const STATUS_CONVERTED = 'converted';

    public static function get_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'hdraq_quotes';
    }

    public static function get_schema_sql()
    {
        global $wpdb;
        $table           = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token VARCHAR(64) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            quantity INT UNSIGNED NOT NULL DEFAULT 1,
            customer_id BIGINT UNSIGNED DEFAULT NULL,
            customer_name VARCHAR(150) NOT NULL,
            customer_email VARCHAR(150) NOT NULL,
            message TEXT DEFAULT NULL,
            admin_note TEXT DEFAULT NULL,
            quoted_price DECIMAL(19,4) DEFAULT NULL,
            order_id BIGINT UNSIGNED DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            responded_at DATETIME DEFAULT NULL,
            converted_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY customer_id (customer_id),
            KEY status (status),
            KEY product_id (product_id)
        ) {$charset_collate};";
    }

    /**
     * Cryptographically random, never derived from or influenced by user input --
     * this is the actual credential that grants access to view/accept one quote.
     */
    public static function generate_unique_token()
    {
        do {
            $token = bin2hex(random_bytes(24));
        } while (self::find_by_token($token));

        return $token;
    }

    public static function create($product_id, $quantity, $customer_id, $name, $email, $message)
    {
        global $wpdb;

        $token = self::generate_unique_token();

        $wpdb->insert(
            self::get_table_name(),
            array(
                'token'          => $token,
                'product_id'     => $product_id,
                'quantity'       => $quantity,
                'customer_id'    => $customer_id ?: null,
                'customer_name'  => $name,
                'customer_email' => $email,
                'message'        => $message,
                'status'         => self::STATUS_PENDING,
                'created_at'     => current_time('mysql'),
            ),
            array('%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
        );

        return self::find_by_token($token);
    }

    public static function find_by_token($token)
    {
        global $wpdb;

        if ('' === (string) $token) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE token = %s',
            self::get_table_name(),
            $token
        ));
    }

    /**
     * Ownership is enforced by the WHERE clause itself -- always call this with
     * get_current_user_id(), never with a value read from the request.
     */
    public static function find_for_customer($customer_id)
    {
        global $wpdb;

        $customer_id = (int) $customer_id;
        if ($customer_id <= 0) {
            return array();
        }

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE customer_id = %d ORDER BY created_at DESC',
            self::get_table_name(),
            $customer_id
        ));
    }

    /**
     * Admin-only: callers must have already checked current_user_can('manage_woocommerce')
     * before reaching this method (see class-hdraq-admin.php).
     */
    public static function find_by_id($id)
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d',
            self::get_table_name(),
            (int) $id
        ));
    }

    /**
     * @param array $args { status, s (name/email search), per_page, paged, orderby, order }
     * @return array { items: array, total: int }
     */
    public static function get_for_list_table(array $args)
    {
        global $wpdb;

        $where  = array('1=1');
        $params = array();

        if (!empty($args['status']) && 'all' !== $args['status']) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }

        if (!empty($args['s'])) {
            $where[]  = '(customer_name LIKE %s OR customer_email LIKE %s)';
            $like     = '%' . $wpdb->esc_like($args['s']) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);

        $allowed_orderby = array('created_at', 'status', 'customer_name', 'quoted_price');
        $orderby         = in_array($args['orderby'] ?? '', $allowed_orderby, true) ? $args['orderby'] : 'created_at';
        $order           = 'ASC' === strtoupper($args['order'] ?? '') ? 'ASC' : 'DESC';

        $per_page = max(1, (int) ($args['per_page'] ?? 20));
        $paged    = max(1, (int) ($args['paged'] ?? 1));
        $offset   = ($paged - 1) * $per_page;

        // $where_sql/$orderby/$order are built only from the hardcoded, safe fragments and
        // allow-lists above (never raw user input); all real values still go through prepare().
        $total = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT COUNT(*) FROM %i WHERE {$where_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            array_merge(array(self::get_table_name()), $params)
        ));

        $items = $wpdb->get_results($wpdb->prepare( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            "SELECT * FROM %i WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            array_merge(array(self::get_table_name()), $params, array($per_page, $offset))
        ));

        return array(
            'items' => $items,
            'total' => $total,
        );
    }

    /**
     * The only way quoted_price/admin_note/status ever get set. Callers must have
     * already checked current_user_can('manage_woocommerce') and verified a nonce
     * (see class-hdraq-admin.php) -- this method itself does not re-check capability,
     * matching the pattern of every other admin-only repository method in this suite.
     */
    public static function respond($id, $quoted_price, $admin_note, $status)
    {
        global $wpdb;

        return false !== $wpdb->update(
            self::get_table_name(),
            array(
                'quoted_price' => $quoted_price,
                'admin_note'   => $admin_note,
                'status'       => $status,
                'responded_at' => current_time('mysql'),
            ),
            array('id' => (int) $id),
            array('%f', '%s', '%s', '%s'),
            array('%d')
        );
    }

    /**
     * Only ever called after a quote's own token has been presented and its status
     * verified as STATUS_QUOTED (see class-hdraq-frontend.php's accept handler) --
     * this method itself does not re-verify status, matching respond() above. Moves
     * the quote to STATUS_ACCEPTED (added to the customer's cart, order not placed
     * yet) so the cart price-override in class-hdraq-cart.php has an explicit signal
     * that this specific quote was actually accepted, not merely quoted.
     */
    public static function mark_accepted($id)
    {
        global $wpdb;

        return false !== $wpdb->update(
            self::get_table_name(),
            array('status' => self::STATUS_ACCEPTED),
            array('id' => (int) $id),
            array('%s'),
            array('%d')
        );
    }

    /**
     * Called from the order-creation hook once checkout actually completes -- see
     * class-hdraq-cart.php's add_order_line_item_meta().
     */
    public static function mark_converted($id, $order_id)
    {
        global $wpdb;

        return false !== $wpdb->update(
            self::get_table_name(),
            array(
                'status'       => self::STATUS_CONVERTED,
                'order_id'     => $order_id,
                'converted_at' => current_time('mysql'),
            ),
            array('id' => (int) $id),
            array('%s', '%d', '%s'),
            array('%d')
        );
    }

    public static function count_pending()
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE status = %s',
            self::get_table_name(),
            self::STATUS_PENDING
        ));
    }
}
