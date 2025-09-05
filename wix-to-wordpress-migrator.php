<?php
/**
 * Plugin Name: Wix to WordPress Migrator
 * Plugin URI: https://github.com/your-username/wix-to-wordpress-migrator
 * Description: Seamlessly migrate your website content from Wix to WordPress. Import posts, pages, images, categories, and tags with advanced error handling and retry capabilities.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * Text Domain: wix-to-wp-migrator
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.6
 * Requires PHP: 7.4
 * Network: false
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WixToWordPressMigrator
 * @version 1.0.0
 * @author Your Name
 * @copyright 2024 Your Name
 * @license GPL-2.0-or-later
 * @link https://github.com/your-username/wix-to-wordpress-migrator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
if ( ! defined( 'WIX_MIGRATOR_VERSION' ) ) {
	define( 'WIX_MIGRATOR_VERSION', '1.0.0' );
}

if ( ! defined( 'WIX_MIGRATOR_PLUGIN_URL' ) ) {
	define( 'WIX_MIGRATOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'WIX_MIGRATOR_PLUGIN_PATH' ) ) {
	define( 'WIX_MIGRATOR_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'WIX_MIGRATOR_PLUGIN_BASENAME' ) ) {
	define( 'WIX_MIGRATOR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

/**
 * Main plugin class
 *
 * @package WixToWordPressMigrator
 * @since 1.0.0
 */
class Wix_To_WordPress_Migrator {

	/**
	 * Plugin instance
	 *
	 * @var Wix_To_WordPress_Migrator
	 * @since 1.0.0
	 */
	private static $instance = null;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Get plugin instance
	 *
	 * @return Wix_To_WordPress_Migrator
	 * @since 1.0.0
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize hooks
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
		register_uninstall_hook( __FILE__, array( __CLASS__, 'uninstall' ) );
	}

	/**
	 * Initialize plugin
	 *
	 * @since 1.0.0
	 */
	public function init() {
		// Check minimum requirements
		if ( ! $this->check_requirements() ) {
			return;
		}

		// Load required files
		$this->load_dependencies();

		// Initialize admin interface
		if ( is_admin() ) {
			new Wix_Migrator_Admin();
		}
	}

	/**
	 * Load plugin textdomain
	 *
	 * @since 1.0.0
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'wix-to-wp-migrator',
			false,
			dirname( WIX_MIGRATOR_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Check minimum requirements
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function check_requirements() {
		// Check PHP version
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			add_action( 'admin_notices', array( $this, 'php_version_notice' ) );
			return false;
		}

		// Check WordPress version
		if ( version_compare( get_bloginfo( 'version' ), '5.0', '<' ) ) {
			add_action( 'admin_notices', array( $this, 'wp_version_notice' ) );
			return false;
		}

		return true;
	}

	/**
	 * Load plugin dependencies
	 *
	 * @since 1.0.0
	 */
	private function load_dependencies() {
		require_once WIX_MIGRATOR_PLUGIN_PATH . 'includes/class-wix-api.php';
		require_once WIX_MIGRATOR_PLUGIN_PATH . 'includes/class-wix-migrator.php';
		require_once WIX_MIGRATOR_PLUGIN_PATH . 'admin/class-wix-migrator-admin.php';
	}

	/**
	 * Plugin activation
	 *
	 * @since 1.0.0
	 */
	public function activate() {
		// Check requirements on activation
		if ( ! $this->check_requirements() ) {
			deactivate_plugins( WIX_MIGRATOR_PLUGIN_BASENAME );
			wp_die( 
				esc_html__( 'Wix to WordPress Migrator requires PHP 7.4+ and WordPress 5.0+', 'wix-to-wp-migrator' ),
				esc_html__( 'Plugin Activation Error', 'wix-to-wp-migrator' ),
				array( 'back_link' => true )
			);
		}

		$this->create_tables();
		
		// Set default options
		add_option( 'wix_migrator_version', WIX_MIGRATOR_VERSION );
	}

	/**
	 * Plugin deactivation
	 *
	 * @since 1.0.0
	 */
	public function deactivate() {
		// Clear any scheduled events if any
		wp_clear_scheduled_hook( 'wix_migrator_cleanup' );
	}

	/**
	 * Plugin uninstallation
	 *
	 * @since 1.0.0
	 */
	public static function uninstall() {
		// Remove plugin options
		delete_option( 'wix_client_id' );
		delete_option( 'wix_migration_status' );
		delete_option( 'wix_migrator_version' );

		// Remove custom tables
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wix_migration_mapping" );
	}

	/**
	 * PHP version notice
	 *
	 * @since 1.0.0
	 */
	public function php_version_notice() {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: %1$s: required PHP version, %2$s: current PHP version */
					esc_html__( 'Wix to WordPress Migrator requires PHP version %1$s or higher. You are running version %2$s.', 'wix-to-wp-migrator' ),
					'7.4',
					PHP_VERSION
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * WordPress version notice
	 *
	 * @since 1.0.0
	 */
	public function wp_version_notice() {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: %1$s: required WordPress version, %2$s: current WordPress version */
					esc_html__( 'Wix to WordPress Migrator requires WordPress version %1$s or higher. You are running version %2$s.', 'wix-to-wp-migrator' ),
					'5.0',
					get_bloginfo( 'version' )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Create plugin database tables
	 *
	 * @since 1.0.0
	 */
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

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}

/**
 * Initialize plugin
 *
 * @since 1.0.0
 */
function wix_to_wordpress_migrator_init() {
	return Wix_To_WordPress_Migrator::get_instance();
}

// Initialize the plugin
wix_to_wordpress_migrator_init();