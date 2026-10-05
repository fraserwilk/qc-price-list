# QC Price List

WordPress plugin that generates a branded, catalogue-style **PDF price list** (RRP + trade price) for the L-TWOO range directly from live WooCommerce data. Nothing to export or maintain by hand: change products or prices in WooCommerce / B2BKing, then download a fresh PDF.

## What it produces

- **Cover page** with the Quality Components logo, title, effective date and (for trade lists) the price group.
- **Divider page per bike type** (ROAD, MTB, GRAVEL, FOLDING, E-BIKE) listing its groupsets and descriptions.
- **Product pages per groupset** (e.g. eR9, A7, GRT), grouped under component headings (Shift Lever, Rear Derailleur, Brake Caliper, ...). Each product shows its image, name, SKU, stock code, bike-type badges, RRP / Trade price and all of its WooCommerce attributes as a spec list.
- **"Accessories & Universal Parts"** section per bike type for parts that belong to more than 6 groupsets (rotors, hose bolts, etc.), so they appear once instead of in every groupset.
- **"Components"** section for products that sit in a bike type but in no groupset.
- **Clickable navigation:** the side tabs are links. Click a bike-type tab to jump to its divider page, or a groupset / ACC tab to jump to that section (works in most PDF viewers).
- **Right-edge index tabs** on every page after the cover: bike types down the edge (current one coloured), and the current bike type's groupsets beside it (current groupset coloured). Shared-parts pages get an `ACC` tab.
- Footer with site name, note and page number.

## How to use it

1. WP admin > **WooCommerce > Price List** (needs the `manage_woocommerce` capability).
2. Choose:
   - **Title**: printed on the cover.
   - **Prices**: RRP + Trade, RRP only (safe to share publicly), or Trade only.
   - **Trade price group**: the B2BKing group whose price is shown as "Trade" (defaults to Retailer). Products with no price for that group show the RRP, as B2BKing does.
   - **Categories**: tick which top-level categories (ROAD, MTB, GRAVEL, ...) to include; all are ticked by default. Shared parts are still judged on all their groupsets, then shown only under the ticked categories.
   - **Only show items in stock**: limits the list to products WooCommerce marks as in stock; empty groupsets drop out.
   - **Footer note**: e.g. "All prices exclude GST."
3. Click **Download PDF**. The file downloads as `QC-Price-List-YYYY-MM-DD.pdf`. It is generated on demand and **never stored on the server**, so trade prices can't be reached by URL.

Notes:
- Generation takes ~25 s for the full catalogue (the layout is calculated twice, see below).
- Clicking **Download PDF** first **prepares images** in small batches (a progress counter shows next to the button), then builds the PDF. Product images live on S3, so the first run takes a couple of minutes; images are resized and cached to `wp-content/uploads/qc-price-list-cache/` (keyed by attachment and modified date, so changed images are picked up). Later runs skip straight to building. The folder is safe to delete.
- The build needs roughly **400MB of PHP memory** on top of WordPress. The plugin asks for 1GB with `ini_set`; if the host forbids that, raise `memory_limit` in PHP/hosting settings.
- **Zero-byte download / troubleshooting:** this used to happen when PHP ran out of memory or time (the process dies before any output). A failure now shows a plain-text error (and is written to the PHP error log as `QC Price List fatal`). If you see a memory error, raise the memory limit or build one Category at a time.
- "POA" is shown where a product has no price.

## Where the data comes from

| PDF element | Source |
|---|---|
| RRP | WooCommerce regular price (falls back to price) |
| Trade | `b2bking_regular_product_price_group_{groupID}` post meta (groups are `b2bking_group` posts) |
| Stock code | `_qc_stock_code` (from the `qc-stock-code` plugin) |
| Bike type / groupset | `product_cat`: top-level term = bike type, child term = groupset. Term description is the groupset blurb |
| Component heading | `pa_component` attribute taxonomy |
| Spec list | The product's custom (non-taxonomy) attributes, plus weight |
| Image | Featured image |
| Logo | Site Customizer logo (white version on dark pages) |

A product in several groupsets is listed under each one, unless it is in more than 6, in which case it goes in the shared section.

## Installation / deployment

- Copy or clone the repo into `wp-content/plugins/qc-price-list/` and activate it. Requires WooCommerce, B2BKing, PHP GD (with WebP) and mbstring.
- `vendor/` (dompdf) is **committed**, so no composer step is needed on the server.

## How to modify it

Everything is in `qc-price-list.php` (no build step). Lint with `php -l qc-price-list.php`.

| To change... | Edit |
|---|---|
| Admin form fields | `qc_price_list_page()` and the `admin_post_qc_price_list` handler (read `$_POST`) |
| Which products / filters (stock, category) | `qc_price_list_build()` (`wc_get_products` args) |
| Grouping logic, shared-parts threshold | `qc_price_list_build()` and the `QC_PL_SHARED_THRESHOLD` constant |
| Order of bike types / groupsets | `qc_price_list_order()` (unlisted groupsets follow alphabetically) |
| Order of component headings | `qc_price_list_component_rank()` |
| Which specs show / hide / rename | `qc_pl_row()` (the `$specs` loop); filter or relabel attributes here |
| Badges and bike-type colours | `qc_pl_badge_class()`, `qc_pl_top_colours()`, and the `.b-*` CSS |
| Fonts, sizes, colours, page margins | `qc_pl_css()` |
| Product block layout | `qc_pl_render_items()` + CSS |
| Cover page | `qc_pl_cover()` |
| Divider / section headings | `qc_pl_sections()` |
| Side tabs (size, colours, position) | `qc_pl_draw_tabs()` |
| Image size / background | `qc_pl_image()` ($max px, background colour) |

How it works: `qc_price_list_build()` returns `bike type => [sets, general, shared]`. `qc_pl_sections()` turns that into HTML sections that each start on a new page. Because the tabs depend on which page a section lands on, each section is first rendered alone (images stripped) to count its pages, then the whole document is rendered and the tabs are drawn per page by a `page_script` callback (`qc_price_list_pdf()`).

### dompdf gotchas

- CSS 2.1 only: no flexbox, grid or `:has()`. Use tables and floats.
- Core Helvetica is Windows-1252 only, so text goes through `qc_pl_txt()` (maps e.g. `≤` to `<=`). Don't pass raw UTF-8 symbols.
- dompdf can't read WebP; images are converted to JPEG in `qc_pl_image()`.
- Dark pages use an absolutely positioned `.dark-bg` inside a fixed-height `.dark` block. Keep that height below the printable page height (690pt) or the page overflows. Don't add padding or top margin to `.dark` (it adds to the height).
- Don't give a section `page-break-before` *and* the previous block `page-break-after`; dompdf then emits a blank page.
- If you change margins or fonts and the tabs end up on the wrong pages, check `qc_pl_count_pages()` still matches the real page count.
- Text drawn with `$canvas->text()` takes the top of the text as its y, and rotated (-90) text anchors at its end, which is why the tab label offsets look odd.

## Known limitations

- Products with no groupset category end up in the large "Components" section; tidy their categories for a better list.
- WooCommerce's default product category (normally "Uncategorized") is **GRAVEL** on this site. New products with no category land in Gravel.
- B2BKing per-user prices and dynamic rules are not applied; only group prices are read.
- Prices are shown as entered (ex-GST on this site).

## Repo

`github.com/fraserwilk/qc-price-list` (main). Same pattern as the other `qc-*` plugins.
