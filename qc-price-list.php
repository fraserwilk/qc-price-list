<?php
/**
 * Plugin Name: QC Price List
 * Description: Generate a branded catalogue-style PDF price list (RRP + trade price) from live WooCommerce data. WooCommerce > Price List.
 * Version: 2.0
 * Author: Quality Components
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

const QC_PL_SHARED_THRESHOLD = 6; // Parts in more groupsets than this go into the shared "Accessories" section.

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Price List', 'Price List', 'manage_woocommerce', 'qc-price-list', 'qc_price_list_page');
});

/** @return array<int,string> B2BKing group ID => title */
function qc_price_list_groups() {
    $groups = [];
    $posts  = get_posts([
        'post_type'      => 'b2bking_group',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);
    foreach ($posts as $p) {
        $groups[$p->ID] = $p->post_title;
    }
    return $groups;
}

function qc_price_list_page() {
    $groups = qc_price_list_groups();
    $cats   = get_terms(['taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => true]);
    ?>
    <div class="wrap">
        <h1>Price List</h1>
        <p>Generates a branded catalogue PDF from the <strong>current</strong> product data (images, specs and prices). Change products in WooCommerce / B2BKing, then download a fresh copy here. Large catalogues can take up to a minute.</p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="qc_price_list">
            <?php wp_nonce_field('qc_price_list'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="qc_pl_title">Title</label></th>
                    <td><input type="text" id="qc_pl_title" name="title" class="regular-text" value="<?php echo esc_attr('Price List ' . wp_date('Y')); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="qc_pl_columns">Prices</label></th>
                    <td>
                        <select id="qc_pl_columns" name="columns">
                            <option value="both">RRP + Trade</option>
                            <option value="rrp">RRP only (public)</option>
                            <option value="trade">Trade only</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="qc_pl_group">Trade price group</label></th>
                    <td>
                        <select id="qc_pl_group" name="group">
                            <?php foreach ($groups as $id => $name) : ?>
                                <option value="<?php echo (int) $id; ?>"<?php selected(strtolower($name), 'retailer'); ?>><?php echo esc_html($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Products with no price set for this group show the RRP (same as B2BKing).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="qc_pl_cat">Category</label></th>
                    <td>
                        <select id="qc_pl_cat" name="category">
                            <option value="0">All categories</option>
                            <?php foreach ($cats as $c) : ?>
                                <option value="<?php echo (int) $c->term_id; ?>"><?php echo esc_html($c->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Stock</th>
                    <td><label><input type="checkbox" name="in_stock" value="1"> Only show items in stock</label></td>
                </tr>
                <tr>
                    <th scope="row"><label for="qc_pl_note">Footer note</label></th>
                    <td><input type="text" id="qc_pl_note" name="note" class="large-text" value="<?php echo esc_attr('All prices exclude GST. Prices subject to change without notice.'); ?>"></td>
                </tr>
            </table>
            <?php submit_button('Download PDF', 'primary', 'submit', true, ['id' => 'qc-pl-submit']); ?>
            <span id="qc-pl-status" style="margin-left:10px"></span>
        </form>
        <script>
        (function () {
            var form = document.querySelector('form input[name="action"][value="qc_price_list"]').form;
            var btn = document.getElementById('qc-pl-submit'), status = document.getElementById('qc-pl-status');
            var nonce = <?php echo wp_json_encode(wp_create_nonce('qc_price_list')); ?>;
            var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var ready = false;
            form.addEventListener('submit', function (e) {
                if (ready) return;
                e.preventDefault();
                btn.disabled = true;
                var first = null;
                (function step() {
                    var body = new URLSearchParams({action: 'qc_price_list_warm', _ajax_nonce: nonce});
                    fetch(ajax, {method: 'POST', credentials: 'same-origin', body: body})
                        .then(function (r) { return r.json(); })
                        .then(function (j) {
                            if (!j.success) throw new Error('image preparation failed');
                            if (first === null) first = j.data.remaining;
                            if (j.data.remaining > 0) {
                                status.textContent = 'Preparing images: ' + (first - j.data.remaining) + ' / ' + first + '...';
                                return step();
                            }
                            status.textContent = 'Building PDF (about 30 seconds)...';
                            ready = true;
                            form.submit();
                            setTimeout(function () { btn.disabled = false; status.textContent = ''; ready = false; }, 60000);
                        })
                        .catch(function (err) { status.textContent = 'Error: ' + err.message; btn.disabled = false; });
                })();
            });
        })();
        </script>
    </div>
    <?php
}

/**
 * AJAX: resize/cache product images in small batches so the PDF request itself is fast
 * (images may live on S3 and be slow to fetch; a single long request can hit host time limits).
 */
add_action('wp_ajax_qc_price_list_warm', function () {
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Unauthorised', 403);
    check_ajax_referer('qc_price_list');
    @set_time_limit(60);

    $ids = wc_get_products(['limit' => -1, 'status' => 'publish', 'return' => 'ids']);
    $todo = [];
    foreach ($ids as $id) {
        $thumb = get_post_thumbnail_id($id);
        $dest  = $thumb ? qc_pl_image_dest($thumb) : '';
        if ($dest && !file_exists($dest)) $todo[$thumb] = true;
    }
    $todo  = array_keys($todo);
    $start = microtime(true);
    $done  = 0;
    foreach ($todo as $thumb) {
        qc_pl_image($thumb);
        $done++;
        if (microtime(true) - $start > 12) break; // keep each request short
    }
    wp_send_json_success(['remaining' => max(0, count($todo) - $done), 'total' => count($ids)]);
});

add_action('admin_post_qc_price_list', function () {
    if (!current_user_can('manage_woocommerce')) wp_die('Unauthorised', 403);
    check_admin_referer('qc_price_list');

    // The catalogue is memory hungry (~200MB on top of WordPress); ask for plenty, and if the
    // process still dies, say so instead of returning an empty (zero byte) download.
    @ini_set('memory_limit', '1024M');
    @set_time_limit(300);
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_log('QC Price List fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo "QC Price List failed to generate.\n\n" . $err['message'] . "\n\nIf this mentions memory, raise PHP memory_limit (needs ~400MB) or try a single Category.";
        }
    });

    $columns = isset($_POST['columns']) ? sanitize_key(wp_unslash($_POST['columns'])) : 'both';
    if (!in_array($columns, ['both', 'rrp', 'trade'], true)) $columns = 'both';

    $group = isset($_POST['group']) ? (int) $_POST['group'] : 0;
    $names = qc_price_list_groups();
    if (!isset($names[$group])) $group = 0;
    if (!$group) $columns = 'rrp';

    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : 'Price List';
    $note  = isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : '';
    $cat   = isset($_POST['category']) ? (int) $_POST['category'] : 0;

    $tops = qc_price_list_build($group, $cat, !empty($_POST['in_stock']));
    try {
        $dompdf = qc_price_list_pdf($tops, $columns, $title, $note, $group ? $names[$group] : '');
    } catch (\Throwable $ex) {
        wp_die('QC Price List failed to generate: ' . esc_html($ex->getMessage()), 'Price List error', 500);
    }
    while (ob_get_level()) ob_end_clean(); // stray output would corrupt the PDF
    $dompdf->stream('QC-Price-List-' . wp_date('Y-m-d') . '.pdf', ['Attachment' => true]);
    exit;
});

/* ---------------------------------------------------------------- data */

/** Preferred display order of groupsets within each bike type; unknown ones follow alphabetically. */
function qc_price_list_order() {
    return [
        'ROAD'    => ['eR9', 'eR7', 'eRX', 'eRX-eTT', 'RX', 'R9', 'R7', 'R5', 'R3', 'R2'],
        'MTB'     => ['eTX', 'AX13', 'AX', 'A9', 'A7', 'A5', 'A3', 'A2', 'TX', 'T7'],
        'GRAVEL'  => ['eGR', 'GRT13', 'GRT', 'GR9', 'GR7', 'GR5'],
        'FOLDING' => [],
        'E-BIKE'  => [],
    ];
}

function qc_price_list_component_rank($name) {
    static $rank = null;
    if ($rank === null) {
        $rank = array_flip(['Groupset', 'Shift Lever', 'Brake/Shift Lever', 'Twist Shifter', 'Brake Lever', 'Brake Caliper', 'Rear Derailleur', 'Front Derailleur', 'Cassette', 'Brake Rotor', 'Seat tube battery']);
    }
    return $rank[$name] ?? 50;
}

/** Make text safe for dompdf's core Helvetica (Windows-1252 only). */
function qc_pl_txt($s) {
    $s = wp_strip_all_tags(html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8'));
    $s = strtr($s, ['≤' => '<=', '≥' => '>=', '≈' => '~', '’' => "'", '“' => '"', '”' => '"', '−' => '-']);
    $c = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
    $s = $c === false ? $s : mb_convert_encoding($c, 'UTF-8', 'Windows-1252');
    return trim(preg_replace('/\s+/', ' ', $s));
}

/**
 * Resize an attachment to a small JPEG (dompdf cannot read WebP, and big PNGs bloat the PDF).
 * Cached under uploads/qc-price-list-cache. Returns an absolute path or ''.
 */
function qc_pl_image_dest($attachment_id, $max = 360) {
    if (!$attachment_id) return '';
    $src = get_attached_file($attachment_id);
    if (!$src) return '';
    $up = wp_get_upload_dir();
    return $up['basedir'] . '/qc-price-list-cache/' . $attachment_id . '-' . $max . '-' . md5(get_post_field('post_modified_gmt', $attachment_id) . $src) . '.jpg';
}

function qc_pl_image($attachment_id, $max = 360, $bg = [244, 244, 241]) {
    $dest = qc_pl_image_dest($attachment_id, $max);
    if (!$dest) return '';
    $src = get_attached_file($attachment_id);
    if (file_exists($dest)) return $dest;
    wp_mkdir_p(dirname($dest));

    // Media may be offloaded (s3auto:// stream) and the stored mime can be wrong, so sniff the real format.
    $data = @file_get_contents($src);
    $im   = $data ? @imagecreatefromstring($data) : false;
    if (!$im) return '';

    $w = imagesx($im); $h = imagesy($im);
    $scale = min(1, $max / max($w, $h));
    $nw = max(1, (int) round($w * $scale)); $nh = max(1, (int) round($h * $scale));
    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, $bg[0], $bg[1], $bg[2]));
    imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagejpeg($out, $dest, 85);
    imagedestroy($im); imagedestroy($out);
    return $dest;
}

function qc_pl_row($product, $group, $bike_types) {
    $id  = $product->get_id();
    $rrp = $product->get_regular_price();
    if ($rrp === '') $rrp = $product->get_price();

    $trade = $group ? get_post_meta($id, 'b2bking_regular_product_price_group_' . $group, true) : '';
    if ($trade === '' || $trade === false) $trade = $rrp;

    $specs = [];
    foreach ($product->get_attributes() as $attr) {
        if ($attr->is_taxonomy()) continue;
        $val = qc_pl_txt(implode(', ', (array) $attr->get_options()));
        if ($val === '') continue;
        $specs[] = [qc_pl_txt($attr->get_name()), $val];
    }
    if ($product->get_weight() !== '') {
        $specs[] = ['Weight', $product->get_weight() . get_option('woocommerce_weight_unit')];
    }

    $comp = wp_get_post_terms($id, 'pa_component', ['fields' => 'names']);

    return [
        'name'  => qc_pl_txt($product->get_name()),
        'sku'   => qc_pl_txt($product->get_sku()),
        'stock' => (string) get_post_meta($id, defined('QC_STOCK_CODE_META_KEY') ? QC_STOCK_CODE_META_KEY : '_qc_stock_code', true),
        'comp'  => ($comp && !is_wp_error($comp)) ? qc_pl_txt($comp[0]) : 'Other',
        'img'   => qc_pl_image(get_post_thumbnail_id($id)),
        'rrp'   => $rrp,
        'trade' => $trade,
        'bikes' => $bike_types,
        'specs' => $specs,
    ];
}

/**
 * @return array<string,array{sets:array<string,array{desc:string,rows:array}>,general:array,shared:array}>
 *         bike type => groupsets etc.
 */
function qc_price_list_build($group, $cat, $in_stock = false) {
    $args = ['limit' => -1, 'status' => 'publish', 'orderby' => 'name', 'order' => 'ASC'];
    if ($in_stock) $args['stock_status'] = 'instock';
    if ($cat) {
        $term = get_term($cat, 'product_cat');
        if ($term && !is_wp_error($term)) $args['category'] = [$term->slug];
    }

    $tops = [];
    foreach (wc_get_products($args) as $product) {
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if (!$terms || is_wp_error($terms)) continue;

        $bikes = []; // bike type name => true
        $sets  = []; // [bike, groupset name, description]
        foreach ($terms as $t) {
            if ($t->slug === 'uncategorized') continue;
            if ($t->parent) {
                $par = get_term($t->parent, 'product_cat');
                if (!$par || is_wp_error($par)) continue;
                $bikes[qc_pl_txt($par->name)] = true;
                $sets[]                       = [qc_pl_txt($par->name), qc_pl_txt($t->name), qc_pl_txt($t->description)];
            } else {
                $bikes[qc_pl_txt($t->name)] = true;
            }
        }
        if (!$bikes) continue;

        $row    = qc_pl_row($product, $group, array_keys($bikes));
        $unique = [];
        foreach ($sets as $s) $unique[$s[0] . '|' . $s[1]] = $s;

        if (count($unique) > QC_PL_SHARED_THRESHOLD) {
            foreach (array_keys($bikes) as $b) $tops[$b]['shared'][] = $row;
        } elseif ($unique) {
            foreach ($unique as $s) {
                $tops[$s[0]]['sets'][$s[1]]['rows'][] = $row;
                $tops[$s[0]]['sets'][$s[1]]['desc']   = $s[2];
            }
        } else {
            foreach (array_keys($bikes) as $b) $tops[$b]['general'][] = $row;
        }
    }

    $cmp = function ($a, $b) {
        return [qc_price_list_component_rank($a['comp']), $a['comp'], $a['stock'], $a['sku']]
           <=> [qc_price_list_component_rank($b['comp']), $b['comp'], $b['stock'], $b['sku']];
    };

    // Order bike types, groupsets and rows.
    $order  = qc_price_list_order();
    $sorted = [];
    foreach (array_merge(array_keys($order), array_diff(array_keys($tops), array_keys($order))) as $b) {
        if (!isset($tops[$b])) continue;
        $t = $tops[$b];
        $pref = $order[$b] ?? [];
        $sets = $t['sets'] ?? [];
        uksort($sets, function ($x, $y) use ($pref) {
            $px = array_search($x, $pref, true); $py = array_search($y, $pref, true);
            $px = $px === false ? 999 : $px; $py = $py === false ? 999 : $py;
            return [$px, $x] <=> [$py, $y];
        });
        foreach ($sets as &$s) { usort($s['rows'], $cmp); $s['desc'] = $s['desc'] ?? ''; }
        unset($s);
        $general = $t['general'] ?? []; usort($general, $cmp);
        $shared  = $t['shared'] ?? [];  usort($shared, $cmp);
        $sorted[$b] = ['sets' => $sets, 'general' => $general, 'shared' => $shared];
    }
    return $sorted;
}

/* ---------------------------------------------------------------- render */

function qc_pl_money($v) {
    return ($v === '' || $v === null || !is_numeric($v)) ? 'POA' : '$' . number_format((float) $v, 2);
}

function qc_pl_logo($dark_bg) {
    $white = get_attached_file((int) get_theme_mod('custom_logo'));
    if (!$white) return '';
    if ($dark_bg) return $white;
    $dark = str_replace('-white-hor', '-horizontal', $white);
    return file_exists($dark) ? $dark : $white;
}

function qc_pl_badge_class($name) {
    $map = ['ROAD' => 'b-road', 'MTB' => 'b-mtb', 'GRAVEL' => 'b-gravel', 'FOLDING' => 'b-fold', 'E-BIKE' => 'b-ebike'];
    return $map[strtoupper($name)] ?? 'b-other';
}

function qc_pl_render_items($rows, $show_rrp, $show_trade) {
    $e = 'esc_html';
    $last = null;
    foreach ($rows as $r) :
        $head = '';
        if ($r['comp'] !== $last) {
            $last = $r['comp'];
            $head = '<div class="comp">' . esc_html(strtoupper($last)) . '</div>';
        }
        ?>
        <div class="item"><?php echo $head; // phpcs:ignore WordPress.Security.EscapeOutput ?><table class="it"><tr>
            <td class="c-img"><div class="imgbox"><?php if ($r['img']) : ?><img src="<?php echo $e($r['img']); ?>"><?php endif; ?></div></td>
            <td class="c-mid">
                <div class="pname"><?php echo $e($r['name']); ?></div>
                <div class="codes"><?php echo $e($r['sku']); ?><?php if ($r['stock']) : ?><br><span class="muted">Stock code </span><?php echo $e($r['stock']); ?><?php endif; ?></div>
                <div class="badges"><?php foreach ($r['bikes'] as $b) : ?><span class="badge <?php echo $e(qc_pl_badge_class($b)); ?>"><?php echo $e($b); ?></span> <?php endforeach; ?></div>
                <table class="prices"><tr>
                    <?php if ($show_rrp) : ?><td><span class="plabel">RRP</span><br><span class="pval"><?php echo $e(qc_pl_money($r['rrp'])); ?></span></td><?php endif; ?>
                    <?php if ($show_trade) : ?><td><span class="plabel">TRADE</span><br><span class="pval trade"><?php echo $e(qc_pl_money($r['trade'])); ?></span></td><?php endif; ?>
                </tr></table>
            </td>
            <td class="c-spec">
                <?php if ($r['specs']) : ?><table class="sp">
                    <?php foreach ($r['specs'] as $s) : ?><tr><td class="sl"><?php echo $e($s[0]); ?></td><td class="sv"><?php echo $e($s[1]); ?></td></tr><?php endforeach; ?>
                </table><?php endif; ?>
            </td>
        </tr></table></div>
    <?php endforeach;
}

/** Colour for each bike type (matches the badges). [fill, text] */
function qc_pl_top_colours($name) {
    $map = [
        'ROAD'    => ['#e0b400', '#202020'],
        'MTB'     => ['#ec7b1a', '#ffffff'],
        'GRAVEL'  => ['#5b3f94', '#ffffff'],
        'FOLDING' => ['#c2185b', '#ffffff'],
        'E-BIKE'  => ['#1e88e5', '#ffffff'],
    ];
    return $map[strtoupper($name)] ?? ['#686868', '#ffffff'];
}

function qc_pl_css() {
    return <<<'CSS'
    @page { margin: 40pt 58pt 50pt 32pt; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 8pt; color: #202020; }
    /* full-bleed dark pages */
    .dark-bg { position: absolute; z-index: -1; top: -40pt; left: -32pt; width: 595pt; height: 842pt; background: #202020; }
    .dark { height: 690pt; page-break-before: always; color: #fff; }
    .dark.cover { page-break-before: auto; }
    .accent { color: #ff9800; }
    .cover .t { font-size: 40pt; font-weight: bold; font-style: italic; margin: 40pt 0 8pt; line-height: 1.05; }
    .cover .rule { width: 90pt; height: 4pt; background: #ff9800; margin: 14pt 0; }
    .cover .m { font-size: 11pt; color: #cfcfcf; margin-top: 5pt; }
    .cover .n { font-size: 8.5pt; color: #9a9a9a; margin-top: 120pt; }
    .dv-top { font-size: 44pt; font-weight: bold; font-style: italic; margin: 0 0 4pt; }
    .dv-sub { font-size: 10pt; color: #cfcfcf; margin-bottom: 40pt; letter-spacing: 1pt; }
    .dv-set { margin-bottom: 20pt; }
    .dv-set .nm { font-size: 24pt; font-weight: bold; font-style: italic; }
    .dv-set .ds { font-size: 8.5pt; color: #ff9800; margin-top: 2pt; }
    .sethead { border-bottom: 1.5pt solid #202020; padding-bottom: 5pt; margin-bottom: 6pt; }
    .sethead .nm { font-size: 24pt; font-weight: bold; font-style: italic; }
    .sethead .ds { font-size: 8.5pt; color: #686868; }
    .comp { font-size: 8.5pt; font-weight: bold; letter-spacing: .5pt; border-bottom: .7pt solid #202020; margin: 10pt 0 5pt; padding-bottom: 2pt; }
    .item { page-break-inside: avoid; margin-bottom: 6pt; }
    table.it { width: 100%; border-collapse: collapse; }
    td.c-img { width: 100pt; vertical-align: top; }
    td.c-mid { width: 150pt; vertical-align: top; padding-right: 8pt; }
    td.c-spec { vertical-align: top; }
    .imgbox { width: 92pt; height: 92pt; background: #f4f4f1; text-align: center; }
    .imgbox img { max-width: 92pt; max-height: 92pt; }
    .pname { font-size: 9.5pt; font-weight: bold; line-height: 1.15; }
    .codes { font-size: 8pt; margin-top: 2pt; }
    .muted { color: #686868; }
    .badges { margin-top: 4pt; }
    .badge { font-size: 6.5pt; font-weight: bold; color: #fff; padding: 1.5pt 4pt; background: #686868; }
    .b-road { background: #e0b400; color: #202020; }
    .b-mtb { background: #ec7b1a; }
    .b-gravel { background: #5b3f94; }
    .b-fold { background: #c2185b; }
    .b-ebike { background: #1e88e5; }
    table.prices { margin-top: 6pt; border-collapse: collapse; }
    table.prices td { padding-right: 14pt; }
    .plabel { font-size: 6.5pt; color: #686868; letter-spacing: .5pt; }
    .pval { font-size: 11pt; font-weight: bold; }
    .pval.trade { color: #c77400; }
    table.sp { width: 100%; border-collapse: collapse; }
    table.sp td { padding: .6pt 0; font-size: 7pt; vertical-align: top; }
    td.sl { width: 48%; color: #686868; padding-right: 4pt; }
    td.sv { }
    #footer { position: fixed; bottom: -34pt; left: 0; right: 0; height: 14pt; font-size: 7pt; color: #8a8a8a; border-top: .5pt solid #8a8a8a; padding-top: 3pt; }
    #footer .pn { float: right; }
    #footer .pn:after { content: counter(page); }
CSS;
}

/** Wrap body markup in a full HTML document. */
function qc_pl_doc($body, $note) {
    ob_start(); ?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><style><?php echo qc_pl_css(); // phpcs:ignore WordPress.Security.EscapeOutput ?></style></head><body>
    <div id="footer"><span class="pn">Page </span><?php echo esc_html(get_bloginfo('name')); ?> &middot; <?php echo esc_html(qc_pl_txt($note)); ?></div>
    <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput ?>
</body></html>
    <?php
    return ob_get_clean();
}

/**
 * Split the catalogue into sections that each start on a fresh page.
 *
 * @return array<int,array{top:string,set:?string,html:string}>
 */
function qc_pl_sections($tops, $show_rrp, $show_trade) {
    $e   = 'esc_html';
    $out = [];

    foreach ($tops as $top => $data) {
        ob_start(); ?>
        <div class="dark"><div class="dark-bg"></div><div style="height:60pt"></div>
            <div class="dv-top"><?php echo $e($top); ?></div>
            <div class="dv-sub">L-TWOO GROUPSETS &amp; COMPONENTS</div>
            <?php foreach ($data['sets'] as $name => $s) : ?>
                <div class="dv-set"><div class="nm"><?php echo $e($name); ?></div><?php if ($s['desc']) : ?><div class="ds"><?php echo $e($s['desc']); ?></div><?php endif; ?></div>
            <?php endforeach; ?>
        </div>
        <?php
        $out[] = ['top' => $top, 'set' => null, 'html' => ob_get_clean()];

        $blocks = [];
        foreach ($data['sets'] as $name => $s) $blocks[] = [$name, $name, $s['desc'], $s['rows']];
        if ($data['general']) $blocks[] = ['Components', null, '', $data['general']];
        if ($data['shared'])  $blocks[] = ['Accessories & Universal Parts', 'ACC', '', $data['shared']];

        foreach ($blocks as [$label, $tab, $desc, $rows]) {
            ob_start(); ?>
            <div class="sethead" style="page-break-before:always"><span class="badge <?php echo $e(qc_pl_badge_class($top)); ?>"><?php echo $e($top); ?></span>
                <div class="nm"><?php echo $e($label); ?></div><?php if ($desc) : ?><div class="ds"><?php echo $e($desc); ?></div><?php endif; ?></div>
            <?php
            qc_pl_render_items($rows, $show_rrp, $show_trade);
            $out[] = ['top' => $top, 'set' => $tab, 'html' => ob_get_clean()];
        }
    }
    return $out;
}

function qc_pl_cover($title, $note, $group_name, $show_trade) {
    $e      = 'esc_html';
    $logo_w = qc_pl_logo(true);
    ob_start(); ?>
    <div class="dark cover"><div class="dark-bg"></div><div style="height:150pt"></div>
        <?php if ($logo_w) : ?><img src="<?php echo $e($logo_w); ?>" style="width:210pt"><?php endif; ?>
        <div class="t"><?php echo $e(qc_pl_txt($title)); ?></div>
        <div class="rule"></div>
        <div class="m">L-TWOO components &middot; Australian distributor</div>
        <div class="m">Effective <?php echo $e(wp_date('j F Y')); ?><?php if ($show_trade && $group_name) : ?> &middot; <?php echo $e(qc_pl_txt($group_name)); ?> pricing<?php endif; ?></div>
        <div class="n"><?php echo $e(qc_pl_txt($note)); ?></div>
    </div>
    <?php
    return ob_get_clean();
}

/** Page count of a fragment rendered on its own (images stripped: they never change layout). */
function qc_pl_count_pages($html, $note) {
    $html = preg_replace('/<img[^>]*>/', '', $html);
    // Standalone, the leading page-break would add a blank first page that the real document doesn't have.
    $html = str_replace('page-break-before:always', 'page-break-before:auto', $html);
    $html = '<style>.dark{page-break-before:auto}</style>' . $html;
    $d    = new Dompdf();
    $d->loadHtml(qc_pl_doc($html, $note), 'UTF-8');
    $d->setPaper('A4', 'portrait');
    $d->render();
    $n = $d->getCanvas()->get_page_count();
    unset($d);
    return max(1, $n);
}

/**
 * Right-edge index tabs: bike types down the edge (current one coloured), and the
 * current bike type's groupsets beside it (current groupset coloured).
 */
function qc_pl_draw_tabs($canvas, $fm, $state, $tops) {
    if (!$state) return;
    $bold = $fm->getFont('Helvetica', 'bold');
    $hex  = function ($h) {
        $h = ltrim($h, '#');
        return [hexdec(substr($h, 0, 2)) / 255, hexdec(substr($h, 2, 2)) / 255, hexdec(substr($h, 4, 2)) / 255];
    };
    $grey = $hex("#8f8f8f");
    $pw   = $canvas->get_width();
    $y    = 90;
    $mw   = 17;
    $mx   = $pw - $mw - 2;  // main tab left edge
    $sw   = 37;
    $sx   = $mx - $sw - 2;  // sub tab left edge
    $sh   = 16;
    $msize = 9;
    $ssize = 7.5;

    foreach ($tops as $top => $data) {
        $active = ($top === $state['top']);
        $subs   = array_keys($data['sets']);
        if ($data['shared']) $subs[] = 'ACC';
        $tw = $fm->getTextWidth(strtoupper($top), $bold, $msize);
        $base = max(80, $tw + 22);
        $h = $active ? max($base, count($subs) * ($sh + 1) + 1) : $base;

        list($fill, $txt) = qc_pl_top_colours($top);
        $canvas->filled_rectangle($mx, $y, $mw, $h - 2, $active ? $hex($fill) : $grey);
        $canvas->text($mx + $mw / 2 + $msize * 0.36, $y + ($h - 2 + $tw) / 2, strtoupper($top), $bold, $msize, $active ? $hex($txt) : [1, 1, 1], 0, 0, -90);

        if ($active) {
            $sy = $y;
            foreach ($subs as $s) {
                $on = ($s === $state['set']);
                $canvas->filled_rectangle($sx, $sy, $sw, $sh, $on ? $hex($fill) : $grey);
                $stw = $fm->getTextWidth($s, $bold, $ssize);
                $canvas->text($sx + ($sw - $stw) / 2, $sy + ($sh - $ssize) / 2, $s, $bold, $ssize, $on ? $hex($txt) : [1, 1, 1]);
                $sy += $sh + 1;
            }
        }
        $y += $h;
    }
}

/** Build and render the whole catalogue; returns the rendered Dompdf instance. */
function qc_price_list_pdf($tops, $columns, $title, $note, $group_name) {
    $show_rrp   = in_array($columns, ['both', 'rrp'], true);
    $show_trade = in_array($columns, ['both', 'trade'], true);

    $sections = qc_pl_sections($tops, $show_rrp, $show_trade);

    // Pass 1: learn which page each section lands on (cover = page 1).
    $page  = 2;
    $state = [];
    foreach ($sections as $s) {
        $n = qc_pl_count_pages($s['html'], $note);
        for ($i = 0; $i < $n; $i++) $state[$page + $i] = ['top' => $s['top'], 'set' => $s['set']];
        $page += $n;
    }

    $body = qc_pl_cover($title, $note, $group_name, $show_trade);
    foreach ($sections as $s) $body .= $s['html'];

    $uploads = wp_get_upload_dir();
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('chroot', [$uploads['basedir']]);
    $options->set('defaultFont', 'Helvetica');
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml(qc_pl_doc($body, $note), 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $dompdf->getCanvas()->page_script(function ($n, $count, $canvas, $fm) use ($state, $tops) {
        qc_pl_draw_tabs($canvas, $fm, $state[$n] ?? null, $tops);
    });
    return $dompdf;
}
