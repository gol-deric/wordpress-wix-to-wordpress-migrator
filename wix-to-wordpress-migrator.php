<?php
/**
 * Plugin Name: Wix to WordPress Migrator
 * Description: Migrate content from Wix to WordPress using Wix API
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WIX_MIGRATOR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WIX_MIGRATOR_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Include required files
require_once WIX_MIGRATOR_PLUGIN_PATH . 'includes/class-wix-api.php';
require_once WIX_MIGRATOR_PLUGIN_PATH . 'includes/class-wix-migrator.php';
require_once WIX_MIGRATOR_PLUGIN_PATH . 'admin/class-wix-migrator-admin.php';

// Initialize plugin
class Wix_To_WordPress_Migrator {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        if (is_admin()) {
            new Wix_Migrator_Admin();
        }
    }
    
    public function activate() {
        $this->create_tables();
    }
    
    public function deactivate() {
        // Clean up if needed
    }
    
    private function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wix_migration_mapping';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            wix_id varchar(255) NOT NULL,
            wp_id int(11) NOT NULL,
            content_type varchar(50) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY wix_type_unique (wix_id, content_type),
            KEY wp_id_index (wp_id),
            KEY content_type_index (content_type)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

new Wix_To_WordPress_Migrator();