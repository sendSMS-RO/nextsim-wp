=== nextSIM for WooCommerce ===
Contributors: nextsim
Tags: esim, woocommerce, travel, sim, mobile-data
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 8.0
WC tested up to: 11.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell eSIM data plans on WooCommerce: import plans, deliver the QR automatically, and offer top-ups and data-usage checks.

== Description ==

nextSIM for WooCommerce turns your store into an eSIM reseller front-end. It imports eSIM
plans as WooCommerce products, delivers the eSIM QR code automatically after payment, and
supports top-ups and data-consumption checks — so you can sell travel and mobile data
without holding any inventory.

**A reseller account and an API token are required.** Don't have one yet? Become a reseller
and get your API access here: https://legal.sendsms.ro/register

= Features =

* Import eSIM plans as WooCommerce products on a schedule, with route/country/region filters.
* Efficient incremental sync: between full imports the plugin pulls only the day's additions,
  price changes and retirements, so repriced and withdrawn plans update within one cycle
  instead of waiting for a full re-import.
* Flexible pricing: a global percentage markup over your reseller price, or a manual price
  per product that the importer never overwrites.
* Non-EUR stores: EUR reseller costs are converted automatically (ECB daily reference rate)
  or with a fixed exchange rate before your markup is applied.
* Automatic fulfilment: the eSIM QR code and install links are delivered on the order page
  and by email once the eSIM is provisioned.
* Top-up: customers recharge an existing eSIM by entering its activation code. Compatibility
  is checked before payment, so incompatible or expired codes are rejected at add-to-cart.
* Multi-eSIM (Family) plans: sell a shared-data pack of several eSIMs in one order.
* Data-consumption checks: customers see their remaining data from the "My eSIMs" account area.
* Storefront ready: each plan shows the countries it covers, and plans can be filtered in the
  shop by data amount and validity (global product attributes).
* Built-in install guide: a short, device-agnostic "how to install your eSIM" appears next to
  the QR on the order page and in the account, cutting the most common support question.
* Admin-friendly sync: the catalog import runs as short background jobs that keep wp-admin
  responsive even on slow hosting, with a live progress bar, counters and a Cancel button
  under WooCommerce > Settings > nextSIM > Sync.

= Become a reseller =

This plugin connects your store to an eSIM reseller platform. To obtain a reseller account
and the API token the plugin needs, register at https://legal.sendsms.ro/register

== External services ==

This plugin connects to external services. It works only after you enter your own reseller
API host and token, and it does not send any data anywhere until you do.

**1. The eSIM reseller API (host you configure, e.g. https://nextsim.eu)**

Used to run the store's core features. The plugin contacts this API to:

* fetch the catalogue of eSIM plans and their changes (import/sync);
* read your reseller credit balance (admin dashboard widget);
* create an eSIM order or top-up when a customer's order is paid, and poll its status until
  the eSIM is provisioned;
* check whether a top-up activation code is compatible with the chosen plan;
* read data-consumption information when a customer requests it.

Data sent to this service: your reseller API token (for authentication), the requested
package identifiers, a customer-entered top-up activation code (only when the customer types
one), the order quantity, and a callback URL on your own site. No customer names, emails or
payment details are sent to this API by the plugin.

By using this service you agree to its terms and privacy policy. Terms of service and privacy
policy: https://legal.sendsms.ro/ — reseller registration: https://legal.sendsms.ro/register

**2. European Central Bank — daily euro reference rates (https://www.ecb.europa.eu)**

Used only when your store currency is not EUR and automatic currency conversion is enabled.
The plugin downloads the ECB's public daily reference-rate XML
(https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml) to convert EUR plan costs to
your store currency. This is a plain public data file: no account, no authentication, and no
data about your store, your customers or you is sent to the ECB. If you use a fixed exchange
rate or your store is in EUR, this service is never contacted. ECB legal / copyright notice:
https://www.ecb.europa.eu/services/disclaimer/html/index.en.html

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin ZIP under Plugins > Add New > Upload Plugin, then activate it.
3. Go to WooCommerce > Settings > nextSIM and enter your API host and token. Don't have a
   reseller account yet? Register at https://legal.sendsms.ro/register
4. On the Pricing tab, choose your markup (and, for non-EUR stores, the exchange-rate mode).
5. On the Sync tab, set the import filters and frequency, then click "Sync now".
6. Place a test order to confirm the eSIM QR code is delivered on the order page and by email.

== Frequently Asked Questions ==

= Do I need a reseller account? =

Yes. The plugin needs a reseller API host and token to work. Register at
https://legal.sendsms.ro/register to get one.

= My store is in RON / USD / another non-EUR currency. Will prices be correct? =

Yes. Reseller prices come from the API in EUR. On the Pricing tab you can convert them
automatically using the ECB daily reference rate, or enter a fixed exchange rate. Your markup
is applied after the conversion. If you prefer, you can also set prices manually per product —
the importer never overwrites a manual price.

= How is the eSIM delivered to the customer? =

Once payment is received, the plugin orders the eSIM and, when it is provisioned, shows the
QR code and install links on the order page and emails them to the customer.

= Can customers top up an existing eSIM? =

Yes. On a plan that supports top-up, the customer enters their activation code at add-to-cart.
The code's compatibility is verified before payment, so incompatible or expired codes are
rejected up front rather than after the customer has paid.

= Where do customers see their remaining data? =

Under My Account > My eSIMs, where they can check data consumption for each eSIM they bought.

= Why are QR codes not showing? =

The QR image is generated on your server. If your host is missing the required library, run
"composer install" in the plugin directory, or contact your host. eSIM delivery still works
through the activation code and install links even without QR images.

== Screenshots ==

1. The nextSIM settings tab in WooCommerce: API connection and "Test connection".
2. Pricing settings: markup and EUR exchange-rate mode.
3. Sync settings: import filters, frequency and "Sync now".
4. An imported eSIM plan shown as a WooCommerce product.
5. The delivered eSIM QR code and install links on the order page.
6. The "My eSIMs" account area with a data-usage check.

== Changelog ==

= 0.1.0 =
* Initial release: plan import with incremental sync, flexible pricing with EUR conversion,
  automatic QR delivery by order page and email, top-up with pre-payment compatibility check,
  Multi-eSIM (Family) support, and customer data-consumption checks.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
