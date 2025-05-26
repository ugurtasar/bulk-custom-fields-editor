<?php
/**
 * Plugin Name: Bulk Custom Fields Editor
 * Description: Easily view and edit custom fields in bulk for any post type.
 * Version: 1.0
 * Author: ugurtasar
 */

if (!defined('ABSPATH')) exit;

class BulkCustomFieldsEditor {
    private $option_name = 'bcfe_settings';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_pages']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_bcfe_save_meta', [$this, 'save_bulk_meta']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    public function add_admin_pages() {
        add_menu_page('Bulk Custom Fields Editor', 'Bulk Custom Fields Editor', 'manage_options', 'bulk-custom-fields-editor', [$this, 'list_page'], 'dashicons-edit', 60);
        add_submenu_page('bulk-custom-fields-editor', 'Settings', 'Settings', 'manage_options', 'bulk-custom-fields-editor-settings', [$this, 'settings_page']);
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
        echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">';
        echo '<h1>Bulk Custom Fields Editor</h1>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin:0;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($_GET['page'] ?? '') . '">';
        echo '<input type="search" name="s" value="' . esc_attr($search_query) . '" placeholder="Search posts..." class="wp-filter-search" style="margin-left:10px; max-width:250px;">';
        echo '<input type="submit" class="button" value="Search">';
        echo '</form>';
        echo '</div>';
        echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
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
        ];
        if ($search_query !== '') {
            $redirect_args['s'] = $search_query;
        }
        wp_redirect(add_query_arg($redirect_args, admin_url('admin.php?page=bulk-custom-fields-editor')));
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
