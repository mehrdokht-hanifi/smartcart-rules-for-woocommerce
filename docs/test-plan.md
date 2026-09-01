# Release Test Plan

## Activation and settings

- Activate with WooCommerce active.
- Confirm the plugin remains inert if WooCommerce is unavailable.
- Confirm only users with `manage_woocommerce` can open the settings page.
- Save and reload every setting.
- Reject zero, duplicate, or descending tier thresholds while preserving previous valid values.

## Discount tiers

- Test immediately below, exactly at, and immediately above every threshold.
- Test percentage and fixed rewards.
- Confirm a fixed reward never exceeds the eligible subtotal.
- Confirm no reward is added below the minimum subtotal.
- Confirm eligible and ineligible payment methods.
- Confirm coupon stacking in both modes.
- Confirm product, variation, and category exclusions.

## Shipping

- Match by method ID, full rate ID, and partial visible label.
- Test every rule immediately below and exactly at its threshold.
- Confirm unmatched rates are unchanged.
- Confirm matched rate taxes become zero with the rate cost.
- Confirm before-discount and after-coupon subtotal bases.

## Checkout and orders

- Change payment methods repeatedly during AJAX checkout refreshes.
- Confirm notices refresh without duplication.
- Place test orders with and without an eligible discount.
- Confirm order metadata in both legacy and HPOS order storage.

## Front end

- Test cart, checkout, shop archives, and product pages.
- Test add-to-cart toast replacement through WooCommerce fragments.
- Check keyboard focus, close-button label, and live-region behavior.
- Check desktop and narrow mobile layouts in a default theme and one commercial theme.

