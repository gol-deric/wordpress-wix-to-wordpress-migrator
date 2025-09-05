<?php

class Wix_Migrator_Admin {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('wp_ajax_wix_migrate', array($this, 'ajax_migrate'));
        add_action('wp_ajax_wix_migrate_batch', array($this, 'ajax_migrate_batch'));
        add_action('wp_ajax_wix_retry_post', array($this, 'ajax_retry_post'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    public function add_admin_menu() {
        add_management_page(
            'Wix to WordPress Migrator',
            'Wix Migrator',
            'manage_options',
            'wix-migrator',
            array($this, 'admin_page')
        );
    }
    
    public function admin_init() {
        register_setting('wix_migrator_settings', 'wix_client_id');
        register_setting('wix_migrator_settings', 'wix_migration_status');
    }
    
    public function enqueue_scripts($hook) {
        if ($hook !== 'tools_page_wix-migrator') {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_script('wix-migrator-admin', WIX_MIGRATOR_PLUGIN_URL . 'admin/js/admin.js', array('jquery'), '1.0.0', true);
        wp_localize_script('wix-migrator-admin', 'wixMigrator', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wix_migrate_nonce')
        ));
        
        wp_enqueue_style('wix-migrator-admin', WIX_MIGRATOR_PLUGIN_URL . 'admin/css/admin.css', array(), '1.0.0');
    }
    
    public function admin_page() {
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['wix_migrator_nonce'], 'wix_migrator_action')) {
            $client_id = sanitize_text_field($_POST['wix_client_id']);
            update_option('wix_client_id', $client_id);
            
            $wix_api = new Wix_API($client_id);
            $auth_result = $wix_api->authenticate($client_id);
            
            if (is_wp_error($auth_result)) {
                echo '<div class="notice notice-error"><p>' . esc_html($auth_result->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>Authentication successful! Ready to migrate.</p></div>';
            }
        }
        
        $client_id = get_option('wix_client_id', '');
        $wix_api = new Wix_API();
        $is_authenticated = $wix_api->is_authenticated();
        
        ?>
        <div class="wrap">
            <h1>Wix to WordPress Migrator</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('wix_migrator_action', 'wix_migrator_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wix_client_id">Wix Client ID</label>
                        </th>
                        <td>
                            <input type="text" id="wix_client_id" name="wix_client_id" value="<?php echo esc_attr($client_id); ?>" class="regular-text" required />
                            <p class="description">Enter your Wix application Client ID</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Authenticate with Wix', 'primary', 'submit'); ?>
            </form>
            
            <?php if ($is_authenticated): ?>
                <hr>
                <h2>Migration Options</h2>
                
                <div id="migration-status" style="display:none;">
                    <h3>Migration Progress</h3>
                    
                    <div id="progress-bar" style="width: 100%; background-color: #f0f0f0; border-radius: 4px; margin: 10px 0;">
                        <div id="progress-fill" style="width: 0%; height: 20px; background-color: #0073aa; border-radius: 4px; transition: width 0.3s;"></div>
                    </div>
                    <p id="progress-text">Starting migration...</p>
                    
                    <div id="migration-log" style="max-height: 300px; overflow-y: auto; background: #f9f9f9; border: 1px solid #ddd; padding: 10px; margin-top: 10px;">
                    </div>
                </div>
                
                <div id="migration-controls">
                    <p>
                        <button type="button" id="migrate-categories" class="button button-secondary">
                            Migrate Categories
                        </button>
                        <button type="button" id="migrate-tags" class="button button-secondary">
                            Migrate Tags
                        </button>
                        <button type="button" id="migrate-posts" class="button button-secondary">
                            Migrate Posts
                        </button>
                    </p>
                </div>
                
            <?php else: ?>
                <p><em>Please authenticate with Wix first to access migration options.</em></p>
            <?php endif; ?>
        </div>
        <?php
    }
    
    public function ajax_migrate() {
        check_ajax_referer('wix_migrate_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $action = sanitize_text_field($_POST['migrate_action']);
        $client_id = get_option('wix_client_id');
        
        if (empty($client_id)) {
            wp_send_json_error('No Wix Client ID configured');
        }
        
        $migrator = new Wix_Migrator($client_id);
        
        switch ($action) {
            case 'categories':
                $result = $migrator->migrate_categories();
                break;
            case 'tags':
                $result = $migrator->migrate_tags();
                break;
            case 'posts':
                $result = $migrator->migrate_posts();
                break;
            case 'all':
                $result = $migrator->migrate_all();
                break;
            default:
                wp_send_json_error('Invalid migration action');
        }
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        // Enhanced error reporting with failed posts details
        if (isset($result['failed_posts']) && !empty($result['failed_posts'])) {
            $migrator = new Wix_Migrator($client_id);
            $result['failed_posts_summary'] = $migrator->get_failed_posts_summary($result['failed_posts']);
            
            // Log failed posts for admin review
            error_log("Wix Migration: Failed posts detected in action '$action': " . count($result['failed_posts']) . " posts failed");
            
            // Reduce response size by removing large wix_data from response
            foreach ($result['failed_posts'] as &$failed_post) {
                if (isset($failed_post['wix_data'])) {
                    unset($failed_post['wix_data']);
                }
            }
        }
        
        wp_send_json_success($result);
    }
    
    public function ajax_migrate_batch() {
        check_ajax_referer('wix_migrate_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $action = sanitize_text_field($_POST['migrate_action']);
        $offset = intval($_POST['offset'] ?? 0);
        $limit = intval($_POST['limit'] ?? 100);
        $client_id = get_option('wix_client_id');
        
        if (empty($client_id)) {
            wp_send_json_error('No Wix Client ID configured');
        }
        
        if ($action !== 'posts') {
            wp_send_json_error('Batch migration only supported for posts');
        }
        
        $migrator = new Wix_Migrator($client_id);
        $result = $migrator->migrate_posts_batch($offset, $limit);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        // Enhanced error reporting with failed posts details for batch processing
        if (isset($result['failed_posts']) && !empty($result['failed_posts'])) {
            $migrator = new Wix_Migrator($client_id);
            $result['failed_posts_summary'] = $migrator->get_failed_posts_summary($result['failed_posts']);
            
            // Log failed posts for admin review
            error_log("Wix Migration: Failed posts detected in batch at offset $offset: " . count($result['failed_posts']) . " posts failed");
            
            // Reduce response size by removing large wix_data from response
            foreach ($result['failed_posts'] as &$failed_post) {
                if (isset($failed_post['wix_data'])) {
                    unset($failed_post['wix_data']);
                }
            }
        }
        
        wp_send_json_success($result);
    }
    
    public function ajax_retry_post() {
        check_ajax_referer('wix_migrate_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $wix_post_data = $_POST['wix_post_data'] ?? null;
        $client_id = get_option('wix_client_id');
        
        if (empty($client_id)) {
            wp_send_json_error('No Wix Client ID configured');
        }
        
        if (empty($wix_post_data) || !is_array($wix_post_data)) {
            wp_send_json_error('Invalid post data for retry');
        }
        
        try {
            $migrator = new Wix_Migrator($client_id);
            $result = $migrator->retry_failed_post($wix_post_data);
            
            wp_send_json_success($result);
        } catch (Exception $e) {
            error_log("Wix Migration: Manual retry failed - " . $e->getMessage());
            wp_send_json_error("Manual retry failed: " . $e->getMessage());
        }
    }
}