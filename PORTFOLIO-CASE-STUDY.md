# WooCommerce Smart Discount and Shipping Rules Plugin

## Portfolio summary

I designed and developed a reusable WooCommerce plugin that converts store-specific promotion and shipping requirements into configurable business rules. Store managers can create tiered cart discounts, limit rewards to selected payment methods, exclude products or categories, and unlock free shipping for matching WooCommerce rates.

## The problem

Standard WooCommerce coupons and shipping settings do not cover every operational rule. A store may need automatic rewards based on cart value and payment method while keeping shipping thresholds separate for different delivery options. Hard-coded theme snippets are difficult to audit, reuse, and maintain.

## The solution

SmartCart Rules centralizes these policies in a capability-protected WooCommerce settings screen. It uses native cart, checkout, shipping, and order hooks instead of modifying WooCommerce core or theme files.

## Key engineering decisions

- Used WooCommerce fees for transparent automatic discounts.
- Recalculated gateway eligibility during checkout updates.
- Matched existing shipping rates without creating duplicate methods.
- Zeroed shipping taxes together with shipping cost.
- Used the WooCommerce order API and declared HPOS compatibility.
- Sanitized stored configuration and escaped customer-facing output.
- Kept all defaults independent of country, carrier, currency, and client branding.

## Deliverables

- Installable WooCommerce plugin
- English administration and customer messages
- Public-ready source repository
- Installation and architecture documentation
- Security notes and structured release test plan

## Technology

PHP, WordPress Settings API, WooCommerce hooks, WooCommerce sessions, HPOS order API, JavaScript, jQuery, CSS.

## Portfolio positioning

This project demonstrates custom WooCommerce plugin development, checkout logic, shipping-rate manipulation, payment-gateway integration, secure settings handling, and maintainable conversion of bespoke business requirements into a reusable product.

