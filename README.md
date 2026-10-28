# NodeThemes Webhooks

**Free WooCommerce webhooks plugin for n8n, Make and Zapier.**

NodeThemes Webhooks is a free WooCommerce webhooks plugin. It sends WooCommerce orders to n8n, Make, Zapier or any URL in the background, with automatic retries, HMAC signatures and a delivery log, so no order is lost when your automation server is down.

- Website: [NodeThemes Webhooks on nodethemes.com](https://nodethemes.com/product/nodethemes-webhooks-pro/)
- All free plugins: [Free WordPress plugins from NodeThemes](https://nodethemes.com/free-plugins/)

## Features (free version)

- Events: order created, order paid (once per order), status changed, completed, cancelled
- Sent in the background (Action Scheduler), so checkout never waits
- Automatic retries (3 tries in about 15 minutes); an endpoint is never switched off by itself
- HMAC-SHA256 signature on every request, plus an optional authentication header
- Delivery log with the exact body, the answer and the error; one-click resend
- "Send test" button, health status and a dashboard warning
- HPOS and block checkout compatible
- Up to 3 endpoints, 7 days of history

## NodeThemes Webhooks Pro

The free plugin is complete on its own. The Pro add-on, sold at [nodethemes.com](https://nodethemes.com/product/nodethemes-webhooks-pro/) as a yearly license with updates and support, adds:

- Unlimited endpoints
- Conditions (total, status, payment method, country, product, coupon)
- Custom payload builder and extra headers
- Retries for up to 24 hours and e-mail alerts
- Bulk resend and "Send again" on any order
- Refund, new customer and stock events
- Inbound webhook for n8n and Make, ready-made n8n workflows and up to 90 days of history

Pro is installed next to the free plugin; your settings and data stay when you upgrade. See the full **Free vs Pro** comparison table on the [product page](https://nodethemes.com/product/nodethemes-webhooks-pro/).

## Installation

1. Download the latest version from [nodethemes.com/free-plugins](https://nodethemes.com/free-plugins/) or from the **Releases** of this repository.
2. In WordPress go to **Plugins → Add New Plugin → Upload Plugin**, choose the zip and click **Install Now**, then **Activate**.
3. New versions then appear in **Dashboard → Updates** like any other plugin.

Requirements: see the plugin header and `readme.txt`.

## Guides

- [WooCommerce webhooks: complete guide for store owners](https://nodethemes.com/woocommerce-webhooks-complete-guide-for-store-owners/)
- [WooCommerce n8n integration: step-by-step guide](https://nodethemes.com/woocommerce-n8n-integration-step-by-step-guide/)
- [WooCommerce order automation: a practical guide](https://nodethemes.com/woocommerce-order-automation-a-practical-guide/)

## Support

- E-mail: nodethemes.com@gmail.com
- Telegram: [t.me/nodethemes](https://t.me/nodethemes)
- Contact page: [nodethemes.com/contact](https://nodethemes.com/contact/)

## License

GPL-2.0-or-later. Made by [NodeThemes](https://nodethemes.com/) – WordPress themes and AI plugins.
