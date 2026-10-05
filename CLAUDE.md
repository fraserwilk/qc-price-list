# QC Price List

Single-file plugin (`qc-price-list.php`) that renders a PDF price list from live WooCommerce data via bundled dompdf (`vendor/`, installed with `composer require dompdf/dompdf`; no build step).

- Admin UI: WooCommerce > Price List. Form POSTs to `admin_post_qc_price_list` (nonce `qc_price_list`, cap `manage_woocommerce`), which streams the PDF as a download. Nothing is stored on disk (trade prices must not be publicly reachable).
- Data: `_sku`, `_qc_stock_code`, `_regular_price` (RRP), trade price from `b2bking_regular_product_price_group_{groupID}` (falls back to RRP when empty, matching B2BKing). Grouped by top-level > sub category (primary Rank Math category, else first); grouped by term *name* so duplicate terms merge.
- dompdf only supports CSS 2.1 (no flex/grid). Edit layout in `qc_price_list_html()`.
- Prices are ex-GST (`woocommerce_prices_include_tax` = no); the footer note on the cover is editable in the form.
- Lint: `php -l qc-price-list.php`.
