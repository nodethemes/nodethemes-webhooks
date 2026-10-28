=== NodeThemes Webhooks - Reliable WooCommerce Webhooks for n8n, Make & Zapier ===
Contributors: nodethemes
Tags: woocommerce, webhooks, n8n, make, zapier
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WooCommerce orders to n8n, Make, Zapier or any URL — in the background, with retries, signatures and a delivery log, so no order gets lost.

== Description ==
WooCommerce's built-in webhooks send each event once. If your automation server is down for a moment the event is gone, and after a few errors WooCommerce switches the webhook off on its own.

NodeThemes Webhooks keeps every event:

* Events: order created, order paid (once per order), status changed, completed, cancelled.
* Sent in the background (Action Scheduler) — checkout never waits.
* Automatic retries; an endpoint is never switched off by itself.
* HMAC-SHA256 signature on every request, plus an optional authentication header (n8n "Header Auth").
* Delivery log with the exact body, the answer and the error; one-click resend.
* "Send test" button, health status and a dashboard warning when an endpoint keeps failing.
* "Webhooks" box on every order. HPOS and block checkout compatible.

== Free and Pro ==
The free plugin works on its own with up to 3 endpoints. NodeThemes Webhooks Pro (a separate add-on sold at nodethemes.com) adds: unlimited endpoints, conditions, custom payload builder and extra headers, retries for up to 24 hours, email alerts, bulk resend and "Send again" on orders, refund / new customer / stock events, an inbound webhook for n8n and Make, ready-made n8n workflows and 90-day history.
The free plugin only contacts nodethemes.com to check for new versions of itself, and your own endpoints.

== Setup ==
1. Upload and activate (WooCommerce must be active).
2. In n8n add a Webhook node (POST) — or a Make "Custom webhook" / Zapier "Catch Hook" — and copy its production URL.
3. NT Webhooks > Endpoints > Add endpoint: paste the URL, choose the events, save.
4. Press "Send test". Real orders are now sent automatically.

== Changelog ==
= 1.0.0 =
* First release.
