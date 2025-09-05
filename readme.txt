=== Wix to WordPress Migrator ===
Contributors: yourusername
Donate link: https://yourwebsite.com/donate
Tags: migration, wix, import, content, posts, images
Requires at least: 5.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Seamlessly migrate your website content from Wix to WordPress. Import posts, pages, images, categories, and tags with advanced error handling.

== Description ==

**Wix to WordPress Migrator** is a powerful plugin that simplifies the process of migrating your website content from Wix to WordPress. Whether you're moving a blog, business website, or portfolio, this plugin handles the complex task of content migration with precision and care.

= Key Features =

* **Complete Content Migration** - Import posts, pages, categories, tags, and images
* **Advanced Error Handling** - Detailed error reporting with retry capabilities for failed migrations
* **Batch Processing** - Efficiently process large amounts of content in manageable batches
* **Image Migration** - Automatically download and import images from your Wix site
* **Content Preservation** - Maintains rich text formatting, links, and media
* **Mapping System** - Keeps track of migrated content to prevent duplicates
* **User-Friendly Interface** - Simple admin interface for easy migration management
* **Progress Tracking** - Real-time migration progress with detailed logs
* **Retry Failed Items** - Manual retry functionality for posts that failed to migrate
* **Safe Migration** - Non-destructive process that won't affect your existing WordPress content

= How It Works =

1. **Connect to Wix** - Enter your Wix API credentials to establish connection
2. **Configure Migration** - Choose what content to migrate (posts, categories, tags, images)
3. **Start Migration** - Begin the automated migration process
4. **Monitor Progress** - Track migration progress with detailed status updates
5. **Handle Errors** - Review and retry any failed migrations manually
6. **Complete Setup** - Finalize your migrated content and customize as needed

= Technical Features =

* **WordPress Coding Standards** - Built following WordPress best practices
* **Security First** - Proper sanitization, validation, and nonce protection
* **Internationalization Ready** - Fully translatable with i18n support
* **Database Optimization** - Efficient database operations with proper indexing
* **Memory Management** - Optimized for large migrations with memory-conscious processing
* **Error Logging** - Comprehensive logging system for troubleshooting
* **Clean Uninstall** - Properly removes all data when plugin is uninstalled

= Requirements =

* WordPress 5.0 or higher
* PHP 7.4 or higher
* Wix API access (free Wix account required)
* Sufficient server memory for large migrations
* MySQL database with CREATE TABLE permissions

= Support =

For support, bug reports, or feature requests, please visit our [GitHub repository](https://github.com/your-username/wix-to-wordpress-migrator) or contact us through the WordPress.org support forums.

== Installation ==

= From WordPress Admin =

1. Go to Plugins > Add New
2. Search for "Wix to WordPress Migrator"
3. Click Install Now and then Activate
4. Navigate to Tools > Wix Migrator to configure the plugin

= Manual Installation =

1. Download the plugin zip file
2. Upload it to your `/wp-content/plugins/` directory
3. Extract the files
4. Activate the plugin through the WordPress admin Plugins menu
5. Navigate to Tools > Wix Migrator to get started

= Configuration =

1. Obtain your Wix API credentials from your Wix developer account
2. Enter your Wix Client ID in the plugin settings
3. Test the connection to ensure proper authentication
4. Configure migration preferences
5. Start your content migration

== Frequently Asked Questions ==

= Is this plugin free? =

Yes, this plugin is completely free and open source under the GPL license.

= Do I need a Wix developer account? =

Yes, you'll need to create a free Wix developer account to obtain API credentials for accessing your Wix site's content.

= Will this plugin delete my Wix content? =

No, this plugin only reads from your Wix site. It never modifies or deletes your original Wix content.

= What if some posts fail to migrate? =

The plugin includes advanced error handling and retry mechanisms. Failed posts are clearly identified with detailed error messages, and you can retry them individually.

= Can I migrate from multiple Wix sites? =

Currently, the plugin supports migrating from one Wix site at a time. You can change the API credentials to migrate from different Wix sites in separate migration sessions.

= Will my SEO rankings be affected? =

The plugin migrates your content structure, but you'll need to set up proper redirects from your Wix URLs to your WordPress URLs to maintain SEO rankings.

= What about custom designs and layouts? =

This plugin migrates content only. You'll need to recreate your designs and layouts using WordPress themes and customization tools.

= Is there a size limit for migrations? =

The plugin is designed to handle large migrations efficiently. However, very large sites may require server configuration adjustments for optimal performance.

== Screenshots ==

1. Main migration dashboard showing connection status and migration options
2. Migration progress screen with real-time updates and statistics
3. Error handling interface showing failed migrations with retry options
4. Successfully migrated content preview in WordPress admin
5. Plugin settings and configuration panel

== Changelog ==

= 1.0.0 =
* Initial release
* Complete content migration from Wix to WordPress
* Advanced error handling and retry mechanisms
* Batch processing for efficient large-scale migrations
* Image downloading and importing
* Rich content formatting preservation
* User-friendly admin interface
* Comprehensive logging and progress tracking
* WordPress coding standards compliance
* Full internationalization support

== Upgrade Notice ==

= 1.0.0 =
Initial release of Wix to WordPress Migrator. This version provides complete content migration functionality with advanced error handling and retry capabilities.

== Privacy Policy ==

This plugin does not collect, store, or transmit any personal data from your website visitors. It only accesses your Wix content through the official Wix API using credentials you provide. All API communications are encrypted and secure.

The plugin stores migration mapping data in your WordPress database to prevent duplicate imports and enable retry functionality. This data can be completely removed by uninstalling the plugin.

== Technical Details ==

= System Requirements =
* WordPress: 5.0+
* PHP: 7.4+ (8.0+ recommended)
* MySQL: 5.6+ (8.0+ recommended)
* Memory: 256MB+ (512MB+ recommended for large migrations)
* Execution time: 300 seconds+ for batch processing

= API Dependencies =
* Wix REST API for content retrieval
* WordPress REST API for local processing
* cURL for HTTP requests
* JSON for data processing

= Database Tables =
The plugin creates one custom table (`wp_wix_migration_mapping`) to track migration progress and prevent duplicates. This table is automatically removed during plugin uninstallation.

For detailed technical documentation, please visit our [GitHub repository](https://github.com/your-username/wix-to-wordpress-migrator).