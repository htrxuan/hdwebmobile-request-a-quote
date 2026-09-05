=== HDWebmobile Request a Quote ===
Contributors: htrxuan
Donate link: https://paypal.me/htrxuan/20
Tags: woocommerce, request a quote, custom pricing, quote request, negotiate price
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let customers request a custom price on any product -- only your own signed-off quote can ever become a cart price.

== Description ==

HDWebmobile Request a Quote adds a "Request a custom quote" form to every product page. The store reviews each request under WooCommerce > HDWebmobile > Request a Quote, sets a price, and the customer is emailed a link to view and accept it -- accepting adds the item to their cart at exactly the quoted price.

= Why this plugin exists =
A competing "Request a Quote" plugin had an unauthenticated Missing Authorization vulnerability (CVE-2026-84238, CVSS 9.8, CWE-862): its quote-management endpoints performed no capability or ownership check at all, letting an unauthenticated attacker view or manipulate any customer's quote and pricing data. This plugin closes that exact vulnerability class by construction:

* Every quote is identified, on the customer-facing side, only by a 192-bit random token generated server-side at creation -- never by its plain, sequential database id. There is no customer-facing code path that can look a quote up by id.
* A logged-in customer's "My Quote Requests" list is scoped by `get_current_user_id()` at the database query itself, so it is structurally impossible for one customer to see another's quotes, regardless of what id or token they might guess.
* Every action that sets a price or changes a quote's status -- the only things an admin does -- explicitly checks `current_user_can('manage_woocommerce')` and a nonce in the handler itself, not just relying on the menu being hidden from lower-privileged users.
* The price actually charged is always re-read fresh from the database by the cart at checkout time; the cart itself only ever carries a token, so nothing a customer's browser sends can influence what they are charged.

= Key Features =
* "Request a custom quote" form on every product page (quantity + optional message)
* Every request reviewed under WooCommerce > HDWebmobile > Request a Quote
* Set a per-unit quoted price and an optional note, or reject the request
* Customer is emailed a secure link to view the quote and accept it into their cart
* Logged-in customers also get a "Quote Requests" tab under My Account
* Honeypot spam protection, no third-party service required

= Limitations (please read before installing) =
* One product per quote request -- no multi-item "quote basket" in this version
* No admin-configurable email templates; notifications are sent as plain text

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/hdwebmobile-request-a-quote` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress. WooCommerce must already be installed and active.
3. That's it -- the "Request a custom quote" form appears automatically on every product page.

== How to Use ==

= 1. Customer requests a quote =
On any product page, a customer picks a quantity, adds an optional message, and submits -- no account required.

= 2. You respond from wp-admin =
Under WooCommerce > HDWebmobile > Request a Quote, open the request, set a per-unit price and an optional note, then Send Quote (or Reject).

= 3. Customer accepts =
The customer receives an email with a secure link to view the quoted price. Accepting adds the item to their cart at exactly that price.

== Screenshots ==

1. The "Request a custom quote" form on a product page.
2. The Request a Quote list and response form under WooCommerce > HDWebmobile.

== Changelog ==

= 1.0.0 =
* Initial release: product-page quote request form, admin review/response screen, secure token-based customer view/accept flow, "Quote Requests" My Account tab, honeypot spam protection.
