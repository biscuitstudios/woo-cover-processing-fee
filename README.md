# Woo Cover Processing Fee

Adds a voluntary "cover the payment processing fee" checkbox to the WooCommerce
checkout.

Built and maintained by [Biscuit Studios](https://biscuitstudios.com/) for our
own client sites. Published because it may be useful to others, not because it
is a supported product. See [Support](#support).

## What it does

Customers can opt to cover the card processing cost so the organization receives
the full amount. The fee is **grossed up**, not simply added: if you configure
2.9% plus 30 cents, the amount charged is calculated so that what lands in the
account after the processor takes its cut is the original total, rather than the
original total minus fees.

- Configurable percentage and fixed component
- The checkout wording is fully editable
- Off by default, so nothing changes until a customer ticks the box

## Requirements

- WordPress 6.3 or later
- WooCommerce
- PHP 8.2 or later
- **The classic shortcode checkout.** See below.

## Classic checkout only

This plugin hooks the classic `[woocommerce_checkout]` shortcode checkout. It
does **not** support the newer checkout block.

That is a deliberate scope decision rather than an oversight. The block checkout
needs a different extension mechanism, and the sites this was written for all
run the classic checkout. If you are on the block checkout, this plugin will not
do anything for you.

## Installation

Download the zip from [Releases](https://github.com/biscuitstudios/woo-cover-processing-fee/releases),
then **Plugins → Add New → Upload Plugin**. Settings live under
**WooCommerce → Processing Fee**.

## A note on the sibling plugin

There is a companion plugin, Woo Checkout Donation + Fee, which adds a donation
selector alongside the same fee logic. **Do not run both.** They share a
constant prefix and class names by design, and the second one loaded will fatal.
Pick whichever fits the site.

## Support

None, in the usual sense. This is published as-is, and we make changes when our
own client work calls for them.

You are welcome to fork it. If you find a genuine security problem, please
report it privately using the **Security** tab on this repository rather than
opening it in public.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
