# Changelog

## 1.0.1 - 2026-09-01

- Replaced the manually constructed coupon-removal URL with WooCommerce's nonce-protected helper.
- Prevented discount calculations and promotional messages when no eligible gateway is configured.
- Added safe label fallbacks for shipping-progress messages.
- Clarified that reaching a threshold does not itself prove that a matching rate is available in the customer's shipping zone.

## 1.0.0 - 2026-09-01

- Initial public portfolio release.
- Added five configurable discount tiers.
- Added percentage and fixed-amount discounts.
- Added payment-gateway eligibility and coupon policy.
- Added product and category exclusions.
- Added configurable free-shipping rules for existing WooCommerce rates.
- Added cart, checkout, and add-to-cart progress messages.
- Added order metadata and HPOS compatibility declaration.
- Removed all client-specific branding, geography, currency conversion, carrier names, and operational defaults.
