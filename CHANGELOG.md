# 1.0.0
- initial plugin release

# 1.1.0
- Use productname from Shopware instead everysize-api
- Store additional PayPal-Transaction IDs to support payment details
- New Protocol-Screen in Administration Backend to view recent import and exports

# 1.1.1
- Improve support for variant products

# 1.1.2
- Added Order ID infront of log-message
- Minor Bugfixes

# 1.2.0
- Add Product-Covers to order-confirm email

# 1.2.1
- Minor Bugfixes and reduced logging

# 1.3.0
- Improve Error-Handling
- New ProtocolFeed

# 1.4.0
- New refund-functionality for paypal-payments via everysize
- Fix to display product-variant details in order emails

# 1.5.0
- Transmit retour-state to everysize

# 1.5.1
- Filter options for protocol-feed

# 1.6.0
- Cleanup for log entries

# 1.6.1
- Hide Edit-Button on Everysize Payment-Detail Page
- Support for older PayPal v5.x Plugins

# 1.6.2
- Fix compatibility with shopware markets plugin

# 2.0.0
- Shopware 6.5 compatibility

# 2.1.0
- Shopware 6.6 compatibility

# 2.2.0
- Shopware 6.7 compatibility (released in the Shopware Store only)

# 2.5.0
- One package for Shopware 6.6 (from 6.6.8) and 6.7 with the PayPal plugin 9 or 10
- New setting "Sales channel for imported orders": if several sales channels share the same access data (e.g. only set in "All sales channels"), orders land in the chosen channel instead of the channel processed first. Empty keeps the previous behaviour
- Import: guest customer, order and everysize link are saved in one database transaction; if the order cannot be saved, no guest customer without an order is left behind
- Import: the guest customer no longer receives a "Your sign-up with …" mail; Shopware treated creating the guest as a registration and sent it via the everysize forwarding address, even if the import failed afterwards
- Import: orders of 1,000 EUR and more are imported (everysize sends amounts with a thousands comma, "1,234.56")
- Import: the payment amount includes the shipping costs; taxes and net amounts are rounded to the cent like in the Shopware cart
- Fulfillment, cancellation and return are transmitted exactly once per order, also after editing an order in the administration; cancellation and return export check their own configured state
- Orders already revoked at everysize (e.g. by a PayPal refund) are no longer reported as shipped; the protocol shows an error instead
- Network or TLS errors towards the everysize API are reported as a readable error; access tokens are cached per API environment
- For test shops the API environment can be switched to an everysize test environment via the environment variable `EVERYSIZE_CHECKOUT_API_URL`; production stays the default and only known everysize URLs are accepted
- Removed: the incoming endpoints `GET /api/everysize/protocol` and `GET /api/everysize/productstocks` (only protected by the client ID, not called by everysize); the protocol in the administration stays
- The protocol only records runs that transferred something or failed

# 2.5.1
- Refund dialog on Shopware 6.7: the PayPal capture is shown; its selection list was empty on 6.7

# 3.0.0
- New Store listing by everysize GmbH: technical name `EverysizeCheckout`, previously `ITSEverysizeCheckout`. For Shopware it is a new plugin that replaces the previous one
- Takes over settings, order assignments, already reported states and the protocol of the previous plugin on installation and activation; its data stays unchanged
- Can only be activated while the previous plugin is deactivated, and prevents activating the previous plugin – both together would import orders twice
- If the previous plugin was already uninstalled without "keep data", the first import restores the order assignments from everysize (order number and customer email must match); also manually with `bin/console everysize:order:recover`
- "API Test" in the settings works on Shopware 6.7
- Test shops: `EVERYSIZE_CHECKOUT_API_URL` accepts the address of any everysize test environment (https on a host under everysize.com); any other value is still an error
- Contains the improvements of 2.5.0 and 2.5.1 that were never in the Store: one package for Shopware 6.6 (from 6.6.8) and 6.7; shipping, cancellation and return reported exactly once per order; orders from 1,000 €; payment amount includes shipping costs; no registration mail to the customer; setting "Sales channel for imported orders"; incoming endpoints removed
- Tracking codes entered or corrected after the shipping report are reported once more (up to 14 days after shipping, only while the order is completed at everysize). Shipments from before are compared with everysize once and only reported where the code is missing or different there
- Shows the customer's original e-mail in the administration once everysize sends it – for information only; the order and shop mails keep the everysize forwarding address

# 3.1.0
- Tab "everysize" of the order reorganised: everysize order (order number, import, order number reported, PayPal transaction, original e-mail), refund (paid, refunded, still open; refunds as a table; amount prefilled with the open amount) and reports to everysize (shipping, tracking codes, cancellation, return with result). The tab shows for every everysize order, amounts with currency, times in the user's time zone
- Shipping is reported also when the order was shipped and already cancelled or returned between two runs: first the shipping with tracking code, then the cancellation or return in the same run. Before, the shipping report and its tracking code were lost
- Refund through everysize like in all other everysize plugins: the tab no longer refunds through the shop's PayPal plugin but through everysize, which so knows every refund at once. The list also shows refunds made directly in PayPal; a double click refunds only once; a second partial refund no longer fails on the payment status
- The Shopware PayPal plugin is no longer required
- Available as a download in the everysize partner portal (Shop integration) instead of the Shopware Store

# 3.1.1
- Also installable with Composer: `composer require everysize/checkout-shopware6` (Packagist). The ZIP from everysize Business stays the way for shops without Composer; use one of the two
- README with installation, update, setup and switching from the previous plugin
- License: MIT
