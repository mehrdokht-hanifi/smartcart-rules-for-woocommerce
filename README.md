# SmartCart Rules for WooCommerce

SmartCart Rules is a portfolio-grade WooCommerce extension for configurable payment-method discounts, tiered cart rewards, and free-shipping thresholds.

## Features

- Five cart-value discount tiers
- Percentage or fixed-amount rewards per tier
- Discounts limited to selected payment gateways
- Optional coupon stacking
- Product and category exclusions
- Free-shipping thresholds for existing WooCommerce shipping rates
- Shipping-rate matching by method ID, rate ID, or label
- Cart, checkout, and add-to-cart progress messages
- Discount metadata stored on the order
- WooCommerce High-Performance Order Storage (HPOS) compatibility declaration
- Sanitized settings and capability-protected administration

## Requirements

- WordPress 6.4 or later
- WooCommerce
- PHP 7.4 or later
- Classic WooCommerce Cart and Checkout pages

WooCommerce Cart and Checkout Blocks are intentionally not declared compatible in version 1.0.0.

## Installation

1. Upload `smartcart-rules-for-woocommerce.zip` in **Plugins > Add New > Upload Plugin**.
2. Activate the plugin.
3. Open **WooCommerce > SmartCart Rules**.
4. Select eligible gateways and configure discount tiers.
5. Configure free-shipping rules using an existing rate's method ID, rate ID, or visible label.
6. Test the rules on a staging store before production use.

## Example

A store can offer 2%, 3%, 5%, 7%, and 10% discounts as the eligible cart subtotal crosses four thresholds. The discount is applied only when the customer selects an allowed payment method. A separate shipping rule can make `flat_rate` free after a configured subtotal.

## Shipping matching

SmartCart Rules does not create shipping methods. It modifies matching rates returned by WooCommerce.

Useful match terms include:

- `flat_rate`
- a complete rate ID such as `flat_rate:3`
- part of a public shipping label such as `Express`

Multiple terms can be separated with commas.

## Architecture

The plugin uses native WooCommerce hooks to calculate discounts and filter package rates. Settings are stored in one WordPress option, while applied discount details are written through the WooCommerce order API for HPOS compatibility.

See [`docs/technical-notes.md`](docs/technical-notes.md) and [`docs/test-plan.md`](docs/test-plan.md) for implementation notes and release checks.

## Security

- The settings page requires `manage_woocommerce`.
- Settings are registered through the WordPress Settings API.
- Text, keys, IDs, and numeric values are sanitized before storage.
- Front-end output is escaped according to context.
- No credentials, customer data, analytics, or remote requests are included.

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE).

