# HDWebmobile Request a Quote

Let customers request a custom price on any product -- only your own signed-off quote can ever become a cart price.

- **WordPress.org:** https://wordpress.org/plugins/hdwebmobile-request-a-quote/
- **Requires:** WordPress 6.9+, WooCommerce, PHP 7.4+
- **License:** GPLv2 or later

## Description

HDWebmobile Request a Quote adds a "Request a custom quote" form to every product page. The store reviews each request under WooCommerce > HDWebmobile > Request a Quote, sets a price, and the customer is emailed a link to view and accept it -- accepting adds the item to their cart at exactly the quoted price.

## Why this plugin exists

A competing "Request a Quote" plugin had an unauthenticated Missing Authorization vulnerability (CVE-2026-84238, CVSS 9.8, CWE-862): its quote-management endpoints performed no capability or ownership check at all, letting an unauthenticated attacker view or manipulate any customer's quote and pricing data. This plugin closes that exact vulnerability class by construction:

* Every quote is identified, on the customer-facing side, only by a 192-bit random token generated server-side at creation -- never by its plain, sequential database id. There is no customer-facing code path that can look a quote up by id.
* A logged-in customer's "My Quote Requests" list is scoped by `get_current_user_id()` at the database query itself, so it is structurally impossible for one customer to see another's quotes, regardless of what id or token they might guess.
* Every action that sets a price or changes a quote's status -- the only things an admin does -- explicitly checks `current_user_can('manage_woocommerce')` and a nonce in the handler itself, not just relying on the menu being hidden from lower-privileged users.
* The price actually charged is always re-read fresh from the database by the cart at checkout time; the cart itself only ever carries a token, so nothing a customer's browser sends can influence what they are charged.

## Features

* "Request a custom quote" form on every product page (quantity + optional message)
* Every request reviewed under WooCommerce > HDWebmobile > Request a Quote
* Set a per-unit quoted price and an optional note, or reject the request
* Customer is emailed a secure link to view the quote and accept it into their cart
* Logged-in customers also get a "Quote Requests" tab under My Account
* Honeypot spam protection, no third-party service required

## Development

Standard WordPress plugin structure:

```
hdwebmobile-request-a-quote.php    Bootstrap
includes/class-hdraq-activator.php
includes/class-hdraq-admin-list-table.php
includes/class-hdraq-admin.php
includes/class-hdraq-cart.php
includes/class-hdraq-core.php
includes/class-hdraq-frontend.php
includes/class-hdraq-hub.php
includes/class-hdraq-myaccount.php
includes/class-hdraq-repository.php
```

Part of the [HDWebmobile](https://hdwebmobile.com/plugins/) suite of focused, single-purpose WooCommerce plugins.

## License

GPLv2 or later. See [LICENSE](LICENSE).

