<?php
/**
 * Plugin Name: Bulk Custom Fields Editor
 * Plugin URI: https://github.com/ugurtasar/bulk-custom-fields-editor
 * Description: Easily view and edit custom fields in bulk for any post type.
 * Version: 1.0
 * Text Domain: bulk-custom-fields-editor
 * Author: ugurtasar
 * Author URI: https://github.com/ugurtasar
 * License:     GPLv2 or later
 * License URI: http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

class BulkCustomFieldsEditor {
    private $option_name = 'bcfe_settings';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_pages']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_bcfe_save_meta', [$this, 'save_bulk_meta']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_post_bcfe_export_json', [$this, 'export_json']);
        add_action('admin_post_bcfe_import_json', [$this, 'handle_import_json']);
        add_action('admin_notices', [$this, 'admin_notices']);
    }

    public function add_admin_pages() {
        add_menu_page('Bulk Custom Fields Editor', 'Bulk Custom Fields Editor', 'manage_options', 'bulk-custom-fields-editor', [$this, 'list_page'], 'dashicons-edit', 60);
        add_submenu_page('bulk-custom-fields-editor', 'Settings', 'Settings', 'manage_options', 'bulk-custom-fields-editor-settings', [$this, 'settings_page']);
        add_submenu_page(
            'bulk-custom-fields-editor',
            'Import / Export',
            'Import / Export',
            'manage_options',
            'bulk-custom-fields-editor-import-export',
            [$this, 'import_export_page']
        );
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'bulk-custom-fields-editor_page_bulk-custom-fields-editor-settings') {
            return;
        }
        wp_enqueue_script(
            'bcfe-settings-js',
            plugin_dir_url(__FILE__) . 'js/bcfe-settings.js',
            [],
            '1.0',
            true
        );
    }

    public function import_export_page() {
        ?>
        <div class="wrap">
            <h1>Import / Export</h1>
            <h2>Export</h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="bcfe_export_json">
                <?php submit_button('Export JSON'); ?>
            </form>
            <hr>
            <h2>Import</h2>
            <form method="post" enctype="multipart/form-data" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="bcfe_import_json">

                <p>
                    <label for="import_file">JSON File:</label><br>
                    <input type="file" name="import_file" id="import_file" required>
                </p>

                <p>
                    <label for="match_type">Match by:</label><br>
                    <select name="match_type" id="match_type" onchange="document.getElementById('meta-key-field').style.display = this.value === 'meta' ? 'block' : 'none';">
                        <option value="title">Title</option>
                        <option value="permalink">Permalink</option>
                        <option value="meta">Meta Key</option>
                    </select>
                </p>

                <p id="meta-key-field" style="display:none;">
                    <label for="match_meta_key">Meta Key:</label><br>
                    <input type="text" name="match_meta_key" id="match_meta_key">
                </p>

                <?php submit_button('Import JSON'); ?>
            </form>
        </div>
        <?php
    }

    public function export_json() {
        $settings = $this->get_settings();
        $meta_keys = array_keys($settings['meta_keys']);
        $post_type = $settings['post_type'];

        $query = new WP_Query([
            'post_type' => $post_type,
            'posts_per_page' => -1,
        ]);

        $data = [];
        foreach ($query->posts as $post) {
            $item = [
                'ID' => $post->ID,
                'title' => $post->post_title,
                'permalink' => get_permalink($post),
                'meta' => [],
            ];
            foreach ($meta_keys as $key) {
                $item['meta'][$key] = get_post_meta($post->ID, $key, true);
            }
            $data[] = $item;
        }

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename=export.json');
        echo json_encode($data);
        exit;
    }

    public function admin_notices() {
        if (isset($_GET['import']) && $_GET['import'] === 'success') {
            echo '<div class="notice notice-success is-dismissible">
                <p>Import completed successfully.</p>
            </div>';
        }
        if (isset($_GET['import']) && $_GET['import'] === 'error') {
            echo '<div class="notice notice-error is-dismissible">
                <p>There was a problem with the import file.</p>
            </div>';
        }
        if (isset($_GET['save']) && $_GET['save'] === 'success') {
            echo '<div class="notice notice-success is-dismissible">
                <p>Changes saved successfully.</p>
            </div>';
        }
        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible">
                <p>Settings saved successfully.</p>
            </div>';
        }
    }

    public function handle_import_json() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            wp_die('Invalid file.');
        }

        $match_type = $_POST['match_type'] ?? 'title';
        $match_meta_key = sanitize_text_field($_POST['match_meta_key'] ?? '');

        $json = file_get_contents($_FILES['import_file']['tmp_name']);
        $items = json_decode($json, true);
        if (!is_array($items)) wp_die('Invalid JSON format.');

        foreach ($items as $item) {
            $post_id = null;

            switch ($match_type) {
                case 'title':
                    $post = get_page_by_title($item['title'], OBJECT, $this->get_settings()['post_type']);
                    if ($post) $post_id = $post->ID;
                    break;
                case 'permalink':
                    $url = $item['permalink'] ?? '';
                    $id = url_to_postid($url);
                    if ($id) $post_id = $id;
                    break;
                case 'meta':
                    $meta_value = $item['meta'][$match_meta_key] ?? '';
                    $args = [
                        'post_type' => $this->get_settings()['post_type'],
                        'meta_query' => [
                            [
                                'key' => $match_meta_key,
                                'value' => $meta_value,
                                'compare' => '='
                            ]
                        ],
                        'posts_per_page' => 1
                    ];
                    $query = new WP_Query($args);
                    if ($query->have_posts()) $post_id = $query->posts[0]->ID;
                    break;
            }

            if ($post_id && !empty($item['meta'])) {
                foreach ($item['meta'] as $key => $val) {
                    update_post_meta($post_id, $key, sanitize_text_field($val));
                }
            }
        }
        wp_redirect(admin_url('admin.php?page=bulk-custom-fields-editor-import-export&import=success'));
        exit;
    }

    public function register_settings() {
    register_setting('bcfe_settings_group', $this->option_name, function($input) {
        $settings = get_option($this->option_name, []);
        if (!empty($_POST['bcfe_meta_keys']['keys'])) {
            $keys = $_POST['bcfe_meta_keys']['keys'];
            $values = $_POST['bcfe_meta_keys']['values'];
            $meta_keys = [];
            foreach ($keys as $index => $key) {
                $key = sanitize_text_field($key);
                $val = sanitize_text_field($values[$index] ?? '');
                if (trim($key) !== '') {
                    $meta_keys[$key] = $val;
                }
            }
            $input['meta_keys'] = $meta_keys;
        } else {
            $input['meta_keys'] = [];
        }
        $input['post_type'] = sanitize_text_field($input['post_type'] ?? $settings['post_type'] ?? 'post');
        $input['posts_per_page'] = intval($input['posts_per_page'] ?? $settings['posts_per_page'] ?? 10);
            return $input;
        });
    }

    private function get_settings() {
        $defaults = [
            'meta_keys' => [],
            'post_type' => 'post',
            'posts_per_page' => 10,
        ];
        return get_option($this->option_name, $defaults);
    }

    public function list_page() {
        $settings = $this->get_settings();
        $post_type = $settings['post_type'];
        $meta_keys = $settings['meta_keys'];
        $posts_per_page = (int)$settings['posts_per_page'];
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $args = [
            'post_type' => $post_type,
            'posts_per_page' => $posts_per_page,
            'paged' => $paged,
        ];
        if ($search_query !== '') {
            $args['s'] = $search_query;
        }
        $query = new WP_Query($args);
        echo '<div class="wrap">';
        echo '<h1>Bulk Custom Fields Editor</h1>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="search-form" style="margin:0;">';
        echo '<p class="search-box">';
        echo '<label class="screen-reader-text" for="post-search-input">Search Posts:</label>';
        echo '<input type="hidden" name="page" value="' . esc_attr($_GET['page'] ?? '') . '">';
        echo '<input type="search" id="post-search-input" name="s" value="' . esc_attr($search_query) . '" />';
        echo '<input type="submit" id="search-submit" class="button" value="Search">';
        echo '</p>';
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="bcfe_save_meta">';
        wp_nonce_field('bcfe_save_meta');
        echo '<input type="hidden" name="paged" value="' . esc_attr($paged) . '">';
        if ($search_query !== '') {
            echo '<input type="hidden" name="s" value="' . esc_attr($search_query) . '">';
        }
        echo '<table class="widefat fixed">';
        echo '<thead><tr><th>Title</th><th>Custom Fields</th></tr></thead><tbody>';
        foreach ($query->posts as $post) {
            echo '<tr><td><strong><a href="' . esc_url(get_edit_post_link($post->ID)) . '" target="_blank" rel="noopener noreferrer">' . esc_html($post->post_title) . '</a></strong></td><td>';
            foreach ($meta_keys as $key => $default) {
                $value = get_post_meta($post->ID, $key, true);
                if ($value === '') {
                    $value = $default;
                }
                echo '<label for="meta_' . $post->ID . '_' . $key . '">' . esc_html($key) . '</label><br>';
                echo '<input type="text" name="meta[' . $post->ID . '][' . $key . ']" id="meta_' . $post->ID . '_' . $key . '" value="' . esc_attr($value) . '" style="width:100%; margin-bottom:10px;">';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        if (!empty($query->posts) && !empty($meta_keys)) {
            submit_button('Save Changes');
        }
        echo '</form>';
        $total_items = $query->found_posts;
        $total_pages = $query->max_num_pages;
        $base_url = remove_query_arg('paged');
        $next_page = $paged < $total_pages ? $paged + 1 : $total_pages;
        $prev_page = $paged > 1 ? $paged - 1 : 1;
        echo '<div class="tablenav bottom">';
        echo '<div class="tablenav-pages">';
        echo '<span class="displaying-num">' . $total_items . ' items</span>';
        echo '<span class="pagination-links">';
        if ($paged <= 1) {
            echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>';
            echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>';
        } else {
            echo '<a class="first-page button" href="' . esc_url(add_query_arg('paged', 1, $base_url)) . '"><span class="screen-reader-text">First page</span><span aria-hidden="true">«</span></a>';
            echo '<a class="prev-page button" href="' . esc_url(add_query_arg('paged', $prev_page, $base_url)) . '"><span class="screen-reader-text">Previous page</span><span aria-hidden="true">‹</span></a>';
        }
        echo '<span class="screen-reader-text">Current Page</span>';
        echo '<span id="table-paging" class="paging-input">';
        echo '<span class="tablenav-paging-text">' . $paged . ' of <span class="total-pages">' . $total_pages . '</span></span>';
        echo '</span>';
        if ($paged >= $total_pages) {
            echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>';
            echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>';
        } else {
            echo '<a class="next-page button" href="' . esc_url(add_query_arg('paged', $next_page, $base_url)) . '"><span class="screen-reader-text">Next page</span><span aria-hidden="true">›</span></a>';
            echo '<a class="last-page button" href="' . esc_url(add_query_arg('paged', $total_pages, $base_url)) . '"><span class="screen-reader-text">Last page</span><span aria-hidden="true">»</span></a>';
        }
        echo '</span></div></div>';
        echo '</div>';
    }

    public function save_bulk_meta() {
        if (!current_user_can('manage_options') || !check_admin_referer('bcfe_save_meta')) {
            wp_die('Unauthorized request.');
        }
        if (!empty($_POST['meta'])) {
            foreach ($_POST['meta'] as $post_id => $metas) {
                foreach ($metas as $key => $value) {
                    update_post_meta($post_id, $key, sanitize_text_field($value));
                }
            }
        }
        $paged = isset($_POST['paged']) ? intval($_POST['paged']) : 1;
        $search_query = isset($_POST['s']) ? sanitize_text_field($_POST['s']) : '';
        $redirect_args = [
            'paged' => $paged,
            'save' => 'success',
        ];
        if ($search_query !== '') {
            $redirect_args['s'] = $search_query;
        }
        wp_redirect(add_query_arg($redirect_args, esc_url(admin_url('admin.php?page=bulk-custom-fields-editor'))));
        exit;
    }

    public function settings_page() {
        $settings = $this->get_settings();
        $saved_meta_keys = $settings['meta_keys'] ?? [];
        echo '<div class="wrap"><h1>Settings</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('bcfe_settings_group');
        do_settings_sections('bcfe_settings_group');
        echo '<h2>Post Type</h2>';
        echo '<input type="text" name="' . $this->option_name . '[post_type]" value="' . esc_attr($settings['post_type']) . '"><br>';
        echo '<h2>Posts Per Page</h2>';
        echo '<input type="number" name="' . $this->option_name . '[posts_per_page]" value="' . intval($settings['posts_per_page']) . '"><br>';
        echo '<h2>Custom Fields</h2>';
        echo '<div id="custom-fields-wrapper">';
        foreach ($saved_meta_keys as $key => $default) {
            echo '<div class="custom-field-entry" style="margin-bottom:10px;">';
            echo 'Key: <input type="text" name="bcfe_meta_keys[keys][]" value="' . esc_attr($key) . '" />';
            echo 'Default Value: <input type="text" name="bcfe_meta_keys[values][]" value="' . esc_attr($default) . '" />';
            echo '<a href="#" onclick="this.parentNode.remove(); return false;" style="color:red; margin-left:10px;">Remove</a>';
            echo '</div>';
        }
        echo '</div>';
        echo '<div id="custom-field-template" style="display:none;">';
        echo '<div class="custom-field-entry" style="margin-bottom:10px;">';
        echo 'Key: <input type="text" name="bcfe_meta_keys[keys][]" value="" />';
        echo 'Default Value: <input type="text" name="bcfe_meta_keys[values][]" value="" />';
        echo '<a href="#" onclick="this.parentNode.remove(); return false;" style="color:red; margin-left:10px;">Remove</a>';
        echo '</div>';
        echo '</div>';
        echo '<button type="button" class="button" onclick="addCustomField()">+ Add Field</button>';
        submit_button('Save Settings');
        echo '</form>';
        echo '</div>';
    }
}

new BulkCustomFieldsEditor();
?>
