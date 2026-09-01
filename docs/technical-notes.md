# Technical Notes

## Scope

Version 1.0.1 targets classic WooCommerce Cart and Checkout templates. It does not declare compatibility with Cart and Checkout Blocks.

## Main hooks

| Hook | Purpose |
| --- | --- |
| `woocommerce_cart_calculate_fees` | Adds the eligible negative fee used as the payment-method discount. |
| `woocommerce_checkout_update_order_review` | Persists the selected gateway in the WooCommerce session during checkout refreshes. |
| `woocommerce_package_rates` | Sets matched shipping rates and their taxes to zero after a threshold is reached. |
| `woocommerce_update_order_review_fragments` | Refreshes checkout reward messages. |
| `woocommerce_checkout_create_order` | Stores the selected gateway, discount amount, and tier through the WooCommerce order API. |

## Discount calculation

The eligible subtotal is the sum of cart line totals after excluding configured products and categories. A fixed reward is capped at the eligible subtotal; percentage rewards are capped at 100% during sanitization. The calculated amount is rounded using the WooCommerce price-decimal setting.

No reward is calculated or advertised until at least one eligible gateway has been configured.

## Shipping calculation

Rules inspect each returned rate's key, rate ID, method ID, and label. Matching is case-insensitive. Reaching a rule threshold sets both rate cost and rate taxes to zero. Rules do not create or delete WooCommerce shipping methods.

Progress notices describe configured threshold state. The actual rate becomes free only when a matching method is available for the customer's current shipping package.

## Data storage

- Settings option: `scrw_settings`
- Order metadata: `_scrw_payment_gateway`, `_scrw_discount_amount`, `_scrw_discount_tier`

## Extension points

- `scrw_has_conflicting_discount`
- `scrw_eligible_subtotal`
- `scrw_shipping_subtotal`

## Known limitations

- Exactly five discount tiers are exposed in version 1.0.0.
- Up to five shipping-rule rows are exposed in the settings UI.
- Rule testing must include the store's actual gateway and shipping plugins.
- Taxes, multi-currency plugins, subscriptions, deposits, and composite products can change checkout semantics and require integration testing.
