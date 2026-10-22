<?php
/**
 * Plugin Name: Agrotek Maskinkatalog Pro
 * Description: Version 3.1 - AUTOMATIC SKIP. Springer automatisk kategorioversigten over, hvis der kun findes én kategori. Popup er optimeret til lange tekster.
 * Version: 3.1.0
 * Author: Emilia & Niklas
 * Update URI: https://github.com/Niklas-Terttu/maskin-produkt-wordpress
 */

if (!defined('ABSPATH')) exit;

// 1. Opret "Maskinkatalog" som a Custom Post Type
add_action('init', function() {
    register_post_type('maskine', array(
        'labels'             => array('name' => 'Maskinkatalog', 'singular_name' => 'Maskine', 'menu_name' => 'Maskinkatalog', 'add_new_item' => 'Tilføj ny maskine'),
        'public'             => true,
        'has_archive'        => false,
        'menu_icon'          => 'dashicons-hammer', 
        'supports'           => array('title'),
        'show_in_rest'       => false,
    ));
});

// 2. Opret Mærker og Kategorier
add_action('init', function() {
    register_taxonomy('maerke', 'maskine', array('label' => 'Mærker', 'rewrite' => array('slug' => 'maerke'), 'hierarchical' => true));
    register_taxonomy('maskin-kategori', 'maskine', array('label' => 'Kategorier', 'rewrite' => array('slug' => 'maskin-kategori'), 'hierarchical' => true));
});

// 3. Tilføj Custom Fields
add_action('add_meta_boxes', function() {
    add_meta_box('maskin_details', 'Maskininformation', 'agrotek_render_maskin_fields', 'maskine', 'normal', 'high');
});

function agrotek_render_maskin_fields($post) {
    $price = get_post_meta($post->ID, '_maskin_price', true);
    $img_url = get_post_meta($post->ID, '_maskin_img_url', true);
    $gallery = get_post_meta($post->ID, '_maskin_gallery', true);
    $desc = get_post_meta($post->ID, '_maskin_desc', true);
    wp_nonce_field('agrotek_save_maskin_fields', 'agrotek_maskin_nonce');
    ?>
    <p><label style="font-weight:bold;">Pris:</label><input type="text" name="maskin_price" value="<?php echo esc_attr($price); ?>" class="large-text" /></p>
    <p><label style="font-weight:bold;">Produktbeskrivelse:</label><textarea name="maskin_desc" class="large-text" rows="5"><?php echo esc_textarea($desc); ?></textarea></p>
    <p><label style="font-weight:bold;">Primær Billed-URL:</label><input type="url" name="maskin_img_url" value="<?php echo esc_url($img_url); ?>" class="large-text" /></p>
    <p><label style="font-weight:bold;">Galleri (Kommasepareret):</label><textarea name="maskin_gallery" class="large-text" rows="2"><?php echo esc_textarea($gallery); ?></textarea></p>
    <?php
}

add_action('save_post', function($post_id) {
    if (!isset($_POST['agrotek_maskin_nonce']) || !wp_verify_nonce($_POST['agrotek_maskin_nonce'], 'agrotek_save_maskin_fields')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (isset($_POST['maskin_price'])) update_post_meta($post_id, '_maskin_price', sanitize_text_field($_POST['maskin_price']));
    if (isset($_POST['maskin_desc'])) update_post_meta($post_id, '_maskin_desc', wp_kses_post($_POST['maskin_desc']));
    if (isset($_POST['maskin_img_url'])) update_post_meta($post_id, '_maskin_img_url', esc_url_raw($_POST['maskin_img_url']));
    if (isset($_POST['maskin_gallery'])) update_post_meta($post_id, '_maskin_gallery', sanitize_textarea_field($_POST['maskin_gallery']));
});

// 4. CSV Importør side
add_action('admin_menu', function() {
    add_submenu_page('edit.php?post_type=maskine', 'CSV Importør', 'CSV Importør', 'manage_options', 'agrotek-csv-importer', 'agrotek_render_csv_importer_page');
});

function agrotek_render_csv_importer_page() {
    if (isset($_POST['agrotek_import_submit'])) {
        check_admin_referer('agrotek_csv_import_verify', 'agrotek_csv_nonce_field');

        if (!empty($_FILES['csv_file']['tmp_name'])) {
            @ini_set('max_execution_time', 900);
            @ini_set('memory_limit', '768M');

            $file = $_FILES['csv_file']['tmp_name'];
            $maerke = sanitize_text_field($_POST['maerke_select']);
            $forced_kategori = sanitize_text_field($_POST['kategori_forced']);

            if (empty($forced_kategori)) {
                echo "<div class='notice notice-error'><p>Fejl: Du skal skrive et kategorinavn før import!</p></div>";
                return;
            }

            $file_content = file_get_contents($file);
            
            if (substr($file_content, 0, 3) == pack("CCC", 0xef, 0xbb, 0xbf)) {
                $file_content = substr($file_content, 3);
            }
            
            $encoding = mb_detect_encoding($file_content, array('UTF-8', 'ISO-8859-1', 'windows-1252'));
            if ($encoding !== 'UTF-8') {
                $file_content = mb_convert_encoding($file_content, 'UTF-8', $encoding);
            }

            $temp_handle = tmpfile();
            fwrite($temp_handle, $file_content);
            fseek($temp_handle, 0);

            $headers = fgetcsv($temp_handle, 20000, ";");
            
            if ($headers !== FALSE && is_array($headers)) {
                $idx_title = false;
                $idx_price = false;
                $idx_desc = false;
                $idx_images = [];

                foreach ($headers as $index => $header_name) {
                    $clean_header = trim(str_replace(array("\r", "\n", "\t"), '', $header_name));
                    if ($clean_header === 'Produktnavn (kort)' || $clean_header === 'Produktnavn (lang)') { $idx_title = $index; }
                    if ($clean_header === 'Vejl. pris incl. moms') { $idx_price = $index; }
                    if ($clean_header === 'Produktbeskrivelse') { $idx_desc = $index; }
                    for ($i = 1; $i <= 9; $i++) {
                        if ($clean_header === "Image $i") { $idx_images[$i] = $index; }
                    }
                }

                if ($idx_title === false) {
                    foreach($headers as $index => $h) { if (strpos($h, 'Produktnavn') !== false) { $idx_title = $index; break; } }
                }
                if ($idx_price === false) {
                    foreach($headers as $index => $h) { if (strpos($h, 'pris') !== false) { $idx_price = $index; break; } }
                }
                if ($idx_desc === false) {
                    foreach($headers as $index => $h) { if (strpos($h, 'beskrivelse') !== false) { $idx_desc = $index; break; } }
                }

                $imported_count = 0;

                while (($row = fgetcsv($temp_handle, 20000, ";")) !== FALSE) {
                    if (empty($row) || !isset($row[$idx_title]) || empty($row[$idx_title])) continue;

                    $title = sanitize_text_field($row[$idx_title]);
                    $price = ($idx_price !== false && isset($row[$idx_price])) ? sanitize_text_field($row[$idx_price]) : '';
                    
                    $desc_raw = ($idx_desc !== false && isset($row[$idx_desc])) ? $row[$idx_desc] : '';
                    $desc_clean = str_replace(array('_x000D_', '\\n'), "\n", $desc_raw);
                    $desc = wp_kses_post($desc_clean);

                    $row_images = [];
                    foreach ($idx_images as $i => $img_idx) {
                        if (isset($row[$img_idx]) && !empty($row[$img_idx])) { $row_images[] = esc_url_raw($row[$img_idx]); }
                    }
                    $primary_img = !empty($row_images) ? $row_images[0] : '';
                    $gallery_imgs = count($row_images) > 1 ? implode(',', array_slice($row_images, 1)) : '';

                    $existing_post = get_page_by_title($title, OBJECT, 'maskine');
                    
                    $post_data = array('post_title' => $title, 'post_status' => 'publish', 'post_type' => 'maskine');
                    if ($existing_post instanceof WP_Post) {
                        $post_data['ID'] = $existing_post->ID;
                        $post_id = wp_update_post($post_data);
                    } else {
                        $post_id = wp_insert_post($post_data);
                    }

                    if ($post_id && !is_wp_error($post_id)) {
                        update_post_meta($post_id, '_maskin_price', $price);
                        update_post_meta($post_id, '_maskin_desc', $desc);
                        update_post_meta($post_id, '_maskin_img_url', $primary_img);
                        update_post_meta($post_id, '_maskin_gallery', $gallery_imgs);

                        wp_set_object_terms($post_id, $maerke, 'maerke');
                        wp_set_object_terms($post_id, $forced_kategori, 'maskin-kategori');

                        $imported_count++;
                    }
                }
                fclose($temp_handle);
                echo "<div class='notice notice-success is-dismissible'><p>Succes! $imported_count maskiner lagt ind under kategorien '$forced_kategori'.</p></div>";
            } else {
                echo "<div class='notice notice-error'><p>Fejl ved læsning af CSV filens formatering.</p></div>";
            }
        }
    }
    ?>
    <div class="wrap">
        <h1>Agrotek Maskinkatalog - Smart CSV Importør (v3.0.0)</h1>
        <form method="post" enctype="multipart/form-data" style="background:#fff; padding:20px; border-radius:5px; max-width:600px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
            <?php wp_nonce_field('agrotek_csv_import_verify', 'agrotek_csv_nonce_field'); ?>
            <table class="form-table">
                <tr><th>Fil:</th><td><input type="file" name="csv_file" accept=".csv" required /></td></tr>
                <tr><th>Mærke:</th><td><select name="maerke_select"><option value="Husqvarna">Husqvarna</option><option value="Stihl">Stihl</option><option value="Gardena">Gardena</option></select></td></tr>
                <tr><th>Skriv Kategori:</th><td><input type="text" name="kategori_forced" placeholder="f.eks. Frontridere" class="regular-text" required /></td></tr>
            </table>
            <?php submit_button('Start Import', 'primary', 'agrotek_import_submit'); ?>
        </form>
    </div>
    <?php
}

// 5. Shortcode til Elementor
add_shortcode('maskinkatalog', function($atts) {
    $atts = shortcode_atts(array('maerke' => 'husqvarna'), $atts, 'maskinkatalog');
    $maerke_slug = sanitize_text_field($atts['maerke']);

    $args = array('post_type' => 'maskine', 'posts_per_page' => -1, 'orderby' => array('menu_order' => 'ASC', 'title' => 'ASC'), 'tax_query' => array(array('taxonomy' => 'maerke', 'field' => 'slug', 'terms' => $maerke_slug)));
    $query = new WP_Query($args);
    if (!$query->have_posts()) return '<p>Ingen maskiner fundet.</p>';

    $categories = array();
    $all_products_html = '';

    while ($query->have_posts()) {
        $query->the_post();
        $id = get_the_ID();
        
        $terms = get_the_terms($id, 'maskin-kategori');
        $cat_name = $terms ? $terms[0]->name : 'Andet';
        $cat_slug = $terms ? $terms[0]->slug : 'andet';

        $img = get_post_meta($id, '_maskin_img_url', true);
        if (empty($img)) $img = 'https://via.placeholder.com/250x180';

        $raw_price = get_post_meta($id, '_maskin_price', true);
        $clean_price = preg_replace('/[^0-9]/', '', $raw_price);
        $price_html = !empty($clean_price) ? number_format((float)$clean_price, 0, ',', '.') . ' kr.' : 'Ring for pris';

        $raw_desc = get_post_meta($id, '_maskin_desc', true);
        $desc = '<p>' . nl2br(esc_html($raw_desc)) . '</p>';
        
        $gallery_raw = get_post_meta($id, '_maskin_gallery', true);
        $gallery = !empty($gallery_raw) ? explode(',', $gallery_raw) : [];

        if (!isset($categories[$cat_slug])) {
            $categories[$cat_slug] = array('name' => $cat_name, 'img' => $img, 'count' => 0);
        }
        $categories[$cat_slug]['count']++;

        $popup_id = 'pop-' . $id;

        $thumbs_html = '';
        if (!empty($gallery)) {
            $thumbs_html .= '<img class="t-img active" src="'.esc_url($img).'" onclick="document.getElementById(\'main-img-'.$id.'\').src=\''.esc_url($img).'\'">';
            foreach ($gallery as $g_img) {
                if(!empty($g_img)) {
                    $thumbs_html .= '<img class="t-img" src="'.esc_url($g_img).'" onclick="document.getElementById(\'main-img-'.$id.'\').src=\''.esc_url($g_img).'\'">';
                }
            }
        }

        $all_products_html .= '
        <div class="p-card item-cat-'.$cat_slug.'" style="display:none;">
            <img src="'.esc_url($img).'">
            <h4>'.get_the_title().'</h4>
            <div class="p-price">'.$price_html.'</div>
            <a href="#'.$popup_id.'" class="p-btn">Læs mere</a>
        </div>

        <div class="m-modal" id="'.$popup_id.'">
            <a href="#close" class="m-backdrop"></a>
            <div class="m-wrapper">
                <a href="#close" class="m-close">&times;</a>
                <div class="m-body">
                    <div class="m-left">
                        <div class="m-img-wrap"><img id="main-img-'.$id.'" src="'.esc_url($img).'"></div>
                        <div class="m-thumbs">'.$thumbs_html.'</div>
                    </div>
                    <div class="m-right">
                        <h3>'.get_the_title().'</h3>
                        <div class="m-modal-price">'.$price_html.'</div>
                        <div class="m-desc-container">
                            <div class="m-desc">'.$desc.'</div>
                        </div>
                        <a href="mailto:info@agrotek.dk?subject=Forespørgsel: '.rawurlencode(get_the_title()).'" class="m-action-btn">Kontakt os om denne maskine</a>
                    </div>
                </div>
            </div>
        </div>';
    }
    wp_reset_postdata();

    $num_categories = count($categories);
    $only_cat_slug = ($num_categories === 1) ? array_key_first($categories) : '';
    $only_cat_name = ($num_categories === 1) ? $categories[$only_cat_slug]['name'] : '';

    ob_start();
    ?>
    <div class="agrotek-hub" data-mode="<?php echo ($num_categories === 1) ? 'single' : 'multi'; ?>">
        
        <div class="cat-section-view" style="<?php echo ($num_categories === 1) ? 'display:none;' : ''; ?>">
            <div class="hub-header">
                <h2><?php echo ucfirst($maerke_slug); ?> Maskiner</h2>
                <p>Vælg en kategori for at se vores modeller</p>
            </div>
            <div class="c-grid">
                <?php foreach ($categories as $slug => $cat): ?>
                    <div class="c-card" onclick="agrotekShowCategory('<?php echo $slug; ?>', '<?php echo esc_js($cat['name']); ?>')">
                        <img src="<?php echo esc_url($cat['img']); ?>">
                        <div class="c-content">
                            <h3><?php echo esc_html($cat['name']); ?></h3>
                            <span class="c-btn">Se maskiner</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="prod-section-view" style="<?php echo ($num_categories === 1) ? 'display:block;' : 'display:none;'; ?>">
            <?php if ($num_categories > 1): ?>
                <div class="back-nav"><button onclick="agrotekShowCategories()" class="b-btn">&#8592; Tilbage til kategorier</button></div>
            <?php endif; ?>
            <div class="hub-header">
                <h2 id="agrotek-cat-title"><?php echo ($num_categories === 1) ? esc_html($only_cat_name) : 'Kategori'; ?></h2>
            </div>
            <div class="p-grid">
                <?php echo $all_products_html; ?>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // Hvis der kun er 1 kategori, tvinger vi produkterne til at blive synlige med det samme
        if ($('.agrotek-hub').data('mode') === 'single') {
            $('.p-card').show();
        }
    });

    function agrotekShowCategory(slug, name) {
        jQuery('.cat-section-view').hide();
        jQuery('.prod-section-view').show();
        jQuery('#agrotek-cat-title').text(name);
        jQuery('.p-card').hide();
        jQuery('.item-cat-' + slug).show();
        window.scrollTo({top: jQuery('.agrotek-hub').offset().top - 50, behavior: 'smooth'});
    }
    function agrotekShowCategories() {
        jQuery('.prod-section-view').hide();
        jQuery('.cat-section-view').show();
    }
    </script>
    <?php
    return ob_get_clean();
});

// 6. CSS til Layout og det højde-optimerede Popup-panel
add_action('wp_head', function() {
    ?>
    <style>
    .agrotek-hub { max-width: 1200px; margin: 0 auto; padding: 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; }
    .hub-header { text-align: center; margin-bottom: 40px; }
    .hub-header h2 { font-size: 2.2rem; color: #0c1c24; font-weight: 700; margin-bottom: 10px; }
    .c-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; }
    .c-card { background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); cursor: pointer; border: 1px solid #eee; display: flex; flex-direction: column; transition: transform 0.2s; }
    .c-card:hover { transform: translateY(-4px); }
    .c-card img { width: 100%; height: 220px; object-fit: cover; background: #f9f9f9; }
    .c-content { padding: 20px; display: flex; flex-direction: column; }
    .c-content h3 { font-size: 1.3rem; margin: 0 0 15px 0; color: #0c1c24; }
    .c-btn { padding: 10px 15px; background: #ff6f00; color: #fff; text-align: center; border-radius: 4px; font-weight: 600; font-size: 0.9rem; align-self: flex-start; }
    .b-btn { background: none; border: 1px solid #ccc; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: 500; margin-bottom: 20px; }
    .p-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 25px; }
    .p-card { background: #fff; border: 1px solid #eee; border-radius: 6px; padding: 15px; text-align: center; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 2px 8px rgba(0,0,0,0.02); }
    .p-card img { max-width: 100%; height: 160px; object-fit: contain; margin-bottom: 15px; display: inline-block; }
    .p-card h4 { font-size: 1.1rem; color: #0c1c24; margin: 10px 0; min-height: 44px; display: flex; align-items: center; justify-content: center; }
    .p-price { font-weight: 700; color: #ff6f00; font-size: 1.2rem; margin-bottom: 15px; }
    .p-btn { display: block; width: 100%; padding: 10px 15px; background: #0c1c24; color: #fff; text-decoration: none; border-radius: 4px; font-size: 0.9rem; font-weight: 500; text-align: center; box-sizing: border-box; }
    .p-btn:hover { background: #ff6f00; }

    /* SKUDSIKKER POPUP MED FAST PANEL SCROLL */
    .m-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; visibility: hidden; z-index: 999999; display: flex; align-items: center; justify-content: center; padding: 20px; transition: all 0.25s ease; }
    .m-modal:target { opacity: 1; visibility: visible; }
    .m-backdrop { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(2px); cursor: default; }
    .m-wrapper { background: #fff; width: 100%; max-width: 850px; border-radius: 12px; position: relative; z-index: 10; padding: 35px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); box-sizing: border-box; max-height: 80vh; display: flex; flex-direction: column; }
    .m-close { position: absolute; top: 15px; right: 20px; text-decoration: none; color: #aaa; font-size: 2rem; line-height: 1; z-index: 11; }
    .m-body { display: grid; grid-template-columns: 1.1fr 1fr; gap: 35px; text-align: left; overflow: hidden; width: 100%; }
    @media (max-width: 768px) { 
        .m-body { grid-template-columns: 1fr; overflow-y: auto; } 
        .m-wrapper { max-height: 90vh; }
    }
    .m-left { display: flex; flex-direction: column; }
    .m-img-wrap { width: 100%; height: 280px; display: flex; align-items: center; justify-content: center; border: 1px solid #eee; background: #fff; border-radius: 6px; }
    .m-img-wrap img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .m-thumbs { display: flex; gap: 10px; overflow-x: auto; margin-top: 12px; padding-bottom: 6px; }
    .t-img { width: 50px; height: 50px; object-fit: contain; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; background: #fff; flex-shrink: 0; }
    .t-img:hover { border-color: #ff6f00; }
    .m-right { display: flex; flex-direction: column; overflow: hidden; height: 100%; }
    .m-right h3 { font-size: 1.7rem; color: #0c1c24; margin: 0 0 8px 0; font-weight: 700; line-height: 1.2; }
    .m-modal-price { font-size: 1.5rem; font-weight: 700; color: #ff6f00; margin-bottom: 15px; }
    .m-desc-container { flex-grow: 1; overflow-y: auto; padding-right: 15px; margin-bottom: 20px; border-bottom: 1px solid #f5f5f5; border-top: 1px solid #f5f5f5; padding-top: 10px; padding-bottom: 10px; }
    .m-desc { font-size: 0.95rem; color: #555; line-height: 1.6; }
    .m-action-btn { display: block; padding: 14px 20px; background: #ff6f00; color: #fff; text-decoration: none; text-align: center; border-radius: 6px; font-weight: 600; font-size: 1rem; flex-shrink: 0; box-shadow: 0 2px 8px rgba(255,111,0,0.2); }
    .m-action-btn:hover { background: #0c1c24; }
    </style>
    <?php
});
// GitHub updates - public repository; use tagged releases with a ZIP asset.
function agrotek_github_release($force = false) {
    $key = 'agrotek_github_release_v3';
    if (!$force) {
        $cached = get_transient($key);
        if ($cached !== false) return $cached;
    }
    $response = wp_remote_get('https://api.github.com/repos/Niklas-Terttu/maskin-produkt-wordpress/releases/latest', array(
        'timeout' => 12, 'headers' => array('Accept' => 'application/vnd.github+json', 'User-Agent' => 'Agrotek-Maskinkatalog-WordPress')
    ));
    if (is_wp_error($response)) return $response;
    if (wp_remote_retrieve_response_code($response) !== 200) return new WP_Error('agrotek_github_http', 'GitHub kunne ikke levere seneste release (HTTP ' . wp_remote_retrieve_response_code($response) . ').');
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data) || empty($data['tag_name'])) return new WP_Error('agrotek_github_json', 'Ugyldigt svar fra GitHub.');
    $version = ltrim($data['tag_name'], 'vV');
    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) return new WP_Error('agrotek_github_version', 'Ugyldigt versionsnummer.');
    $zip = '';
    foreach (($data['assets'] ?? array()) as $asset) {
        if (($asset['name'] ?? '') === 'agrotek-maskinkatalogv4.zip') $zip = $asset['browser_download_url'] ?? '';
    }
    if (!$zip) return new WP_Error('agrotek_github_asset', 'Releasen mangler agrotek-maskinkatalogv4.zip.');
    $result = array('version' => $version, 'package' => esc_url_raw($zip), 'url' => 'https://github.com/Niklas-Terttu/maskin-produkt-wordpress/releases/tag/' . rawurlencode($data['tag_name']));
    set_transient($key, $result, HOUR_IN_SECONDS);
    return $result;
}

add_filter('update_plugins_github.com', function($update, $plugin_data, $plugin_file, $locales) {
    if ($plugin_file !== plugin_basename(__FILE__)) return $update;
    $release = agrotek_github_release();
    if (is_wp_error($release)) return false;
    return array('version' => $release['version'], 'package' => $release['package'], 'url' => $release['url'], 'requires' => '6.0');
}, 10, 4);

add_action('admin_menu', function() {
    add_submenu_page('edit.php?post_type=maskine', 'Plugin-opdateringer', 'Opdateringer', 'manage_options', 'agrotek-updates', 'agrotek_updates_page');
});
function agrotek_updates_page() {
    if (!current_user_can('manage_options')) return;
    $forced = isset($_POST['agrotek_force_update_check']);
    if ($forced) {
        check_admin_referer('agrotek_force_update_check');
        delete_transient('agrotek_github_release_v3');
        delete_site_transient('update_plugins');
        if (function_exists('wp_clean_plugins_cache')) wp_clean_plugins_cache(true);
    }
    $release = agrotek_github_release($forced);
    if ($forced && !is_wp_error($release)) wp_update_plugins();
    if (!function_exists('get_plugin_data')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $installed = get_plugin_data(__FILE__)['Version'];
    echo '<div class="wrap"><h1>Agrotek Maskinkatalog – Opdateringer</h1><p>Installeret version: <strong>' . esc_html($installed) . '</strong></p>';
    if (is_wp_error($release)) {
        echo '<div class="notice notice-warning"><p>' . esc_html($release->get_error_message()) . '</p></div>';
    } else {
        echo '<p>Seneste GitHub-version: <strong>' . esc_html($release['version']) . '</strong></p>';
        echo '<p>' . (version_compare($release['version'], $installed, '>') ? 'Der er en nyere version tilgængelig. Gå til Plugins for at installere den.' : 'Du har den nyeste version.') . '</p>';
    }
    echo '<form method="post">';
    wp_nonce_field('agrotek_force_update_check');
    submit_button('Søg efter opdateringer', 'primary', 'agrotek_force_update_check');
    echo '</form></div>';
}


// Manual machine order, stored in WordPress menu_order (does not alter product metadata).
add_action('admin_menu', function () {
    add_submenu_page('edit.php?post_type=maskine', 'Sorter maskiner', 'Sorter maskiner', 'edit_others_posts', 'agrotek-sort', 'agrotek_sort_page');
});
function agrotek_sort_page() {
    if (!current_user_can('edit_others_posts')) wp_die('Ingen adgang.');
    $brands = get_terms(array('taxonomy' => 'maerke', 'hide_empty' => false));
    $cats = get_terms(array('taxonomy' => 'maskin-kategori', 'hide_empty' => false));
    $brand = isset($_GET['brand']) ? absint($_GET['brand']) : 0;
    $cat = isset($_GET['cat']) ? absint($_GET['cat']) : 0;
    $tax = array();
    if ($brand) $tax[] = array('taxonomy' => 'maerke', 'field' => 'term_id', 'terms' => $brand);
    if ($cat) $tax[] = array('taxonomy' => 'maskin-kategori', 'field' => 'term_id', 'terms' => $cat);
    $args = array('post_type' => 'maskine', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => array('menu_order' => 'ASC', 'title' => 'ASC'));
    if ($tax) $args['tax_query'] = $tax;
    $posts = get_posts($args);
    echo '<div class="wrap"><h1>Sorter maskiner</h1><p>Træk maskinerne i den ønskede rækkefølge, og tryk Gem rækkefølge. Sorteringen gælder alle visninger af maskinerne.</p>';
    echo '<form method="get"><input type="hidden" name="post_type" value="maskine"><input type="hidden" name="page" value="agrotek-sort"><select name="brand"><option value="0">Alle mærker</option>';
    foreach ((array) $brands as $t) if ($t instanceof WP_Term) echo '<option value="' . esc_attr($t->term_id) . '" ' . selected($brand, $t->term_id, false) . '>' . esc_html($t->name) . '</option>';
    echo '</select> <select name="cat"><option value="0">Alle kategorier</option>';
    foreach ((array) $cats as $t) if ($t instanceof WP_Term) echo '<option value="' . esc_attr($t->term_id) . '" ' . selected($cat, $t->term_id, false) . '>' . esc_html($t->name) . '</option>';
    echo '</select> <button class="button">Filtrer</button></form>';
    echo '<ul id="agrotek-sortable" style="max-width:750px">';
    foreach ($posts as $post) echo '<li data-id="' . esc_attr($post->ID) . '" style="padding:12px 16px;margin:6px 0;background:white;border:1px solid #ddd;cursor:move">☰ &nbsp;' . esc_html($post->post_title) . '</li>';
    echo '</ul><button id="agrotek-save-order" class="button button-primary">Gem rækkefølge</button> <span id="agrotek-sort-result" aria-live="polite"></span></div>';
    wp_enqueue_script('jquery-ui-sortable');
    wp_add_inline_script('jquery-ui-sortable', 'jQuery(function($){$("#agrotek-sortable").sortable();$("#agrotek-save-order").on("click",function(){var b=$(this);b.prop("disabled",true);$("#agrotek-sort-result").text("Gemmer...");$.post(ajaxurl,{action:"agrotek_save_order",nonce:' . wp_json_encode(wp_create_nonce('agrotek_save_order')) . ',ids:$("#agrotek-sortable li").map(function(){return $(this).data("id")}).get()},function(r){$("#agrotek-sort-result").text(r.success?"Rækkefølgen er gemt.":"Kunne ikke gemme.");}).fail(function(){$("#agrotek-sort-result").text("Netværksfejl.")}).always(function(){b.prop("disabled",false)});});});');
}
add_action('wp_ajax_agrotek_save_order', function () {
    check_ajax_referer('agrotek_save_order', 'nonce');
    if (!current_user_can('edit_others_posts')) wp_send_json_error('Ingen adgang', 403);
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_values(array_unique(array_map('absint', wp_unslash($_POST['ids'])))) : array();
    if (!$ids || count($ids) > 5000) wp_send_json_error('Ugyldig liste', 400);
    // Assign positions across the catalog while preserving the chosen relative order.
    foreach ($ids as $i => $id) {
        if (get_post_type($id) !== 'maskine' || !current_user_can('edit_post', $id)) wp_send_json_error('Ugyldig maskine', 403);
    }
    foreach ($ids as $i => $id) wp_update_post(array('ID' => $id, 'menu_order' => $i + 1));
    wp_send_json_success();
});
