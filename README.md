# Wix to WordPress Migrator

A comprehensive WordPress plugin to migrate content from Wix websites to WordPress using the official Wix API.

## 🌟 Features

- **Complete Content Migration**: Migrate blog posts, categories, and tags from Wix to WordPress
- **Rich Content Preservation**: Converts Wix rich content format to WordPress HTML while preserving formatting
- **Image Migration**: Automatically downloads and imports images to WordPress media library
- **Smart Mapping System**: Prevents duplicates and enables content updates using Wix ID mapping
- **Batch Processing**: Handles large amounts of content with automatic pagination and batch processing
- **Robust Error Handling**: Multiple fallback mechanisms ensure successful migration even with problematic content
- **SEO Data Preservation**: Maintains publication dates and other metadata
- **Real-time Progress Tracking**: Live progress updates with detailed logging

## 📋 Requirements

- WordPress 5.0+
- PHP 7.4+
- cURL extension
- GD extension (for image processing)
- MySQL database
- Wix application with API access

## 🚀 Installation

1. **Download or clone this repository**
   ```bash
   git clone https://github.com/[your-username]/wix-to-wordpress-migrator.git
   ```

2. **Upload to WordPress**
   - Upload the `wix-to-wordpress-migrator` folder to `/wp-content/plugins/`
   - Or install via WordPress admin by uploading the ZIP file

3. **Activate the plugin**
   - Go to WordPress Admin > Plugins
   - Find "Wix to WordPress Migrator" and click "Activate"

4. **Access the migration tool**
   - Navigate to **Tools > Wix Migrator** in WordPress admin

## ⚙️ Setup & Configuration

### 1. Get Wix API Credentials

1. Visit [Wix Developers](https://dev.wix.com/)
2. Create a new app or use an existing one
3. Get your **Client ID** from the app settings
4. Ensure your app has permission to access Blog APIs

### 2. Configure the Plugin

1. Go to **Tools > Wix Migrator** in WordPress admin
2. Enter your **Wix Client ID**
3. Click **"Authenticate with Wix"**
4. Once authenticated, migration options will appear

### 3. Run Migration

**Recommended migration order:**
1. **Migrate Categories** first
2. **Migrate Tags** second  
3. **Migrate Posts** last (this will take the longest)

## 📊 Migration Process

### Categories & Tags
- Fetches all categories and tags from Wix
- Creates corresponding WordPress terms
- Maps Wix IDs to WordPress IDs for relationship preservation

### Posts Migration
- **Batch Processing**: Processes posts in batches of 100 for better performance
- **Rich Content Conversion**: Converts Wix's proprietary rich content format to WordPress HTML
- **Image Handling**: Downloads images from Wix and uploads to WordPress media library
- **Metadata Preservation**: Maintains publication dates, excerpts, and SEO data
- **Category/Tag Assignment**: Links posts to previously migrated categories and tags

### Error Handling & Fallbacks

The plugin includes multiple fallback mechanisms:

1. **Primary**: Full rich content conversion with images
2. **Secondary**: Simplified content using post excerpt
3. **Tertiary**: Minimal safe content with cleaned titles and basic text

This ensures even problematic posts are migrated successfully.

## 🔧 Technical Details

### Database Structure

The plugin creates a mapping table to track migrated content:

```sql
CREATE TABLE wp_wix_migration_mapping (
    id int(11) NOT NULL AUTO_INCREMENT,
    wix_id varchar(255) NOT NULL,
    wp_id int(11) NOT NULL,  
    content_type varchar(50) NOT NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY wix_type_unique (wix_id, content_type)
);
```

### File Structure

```
wix-to-wordpress-migrator/
├── wix-to-wordpress-migrator.php    # Main plugin file
├── includes/
│   ├── class-wix-api.php           # Wix API integration
│   └── class-wix-migrator.php      # Core migration logic
├── admin/
│   ├── class-wix-migrator-admin.php # Admin interface
│   ├── css/admin.css               # Admin styles
│   └── js/admin.js                 # Frontend JavaScript
└── README.md                       # Documentation
```

### Rich Content Conversion

Converts Wix rich content elements:
- **Paragraphs** → `<p>` tags
- **Headings** → `<h1>` to `<h6>` tags  
- **Text formatting** → `<strong>`, `<em>`, `<u>` tags
- **Images** → Downloads and converts to `<img>` tags with WordPress URLs
- **Lists** → `<ul>`, `<ol>`, `<li>` tags

## 🐛 Troubleshooting

### Common Issues

**Authentication Failed**
- Verify your Wix Client ID is correct
- Ensure your Wix app has Blog API permissions
- Check your WordPress site can make external HTTP requests

**Migration Stops/Fails**
- Check WordPress error logs (`/wp-content/debug.log`)
- Verify sufficient server memory and execution time
- Check database connection and permissions

**Images Not Importing**
- Ensure GD extension is installed
- Check WordPress uploads directory permissions
- Verify server can download from external URLs

**Posts Missing Content**
- Check the migration log for conversion errors
- Look for posts marked with `wix_content_failed` meta
- Original content may be preserved in metadata

### Debug Mode

Enable WordPress debug mode for detailed error logging:

```php
// Add to wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

## 🔒 Security Features

- **Input Sanitization**: All user inputs are sanitized using WordPress functions
- **Nonce Protection**: AJAX requests protected with WordPress nonces  
- **Permission Checks**: Only administrators can perform migrations
- **Data Validation**: Comprehensive validation of API responses and user data

## 🚧 Limitations

- **API Rate Limits**: Respects Wix API rate limits with built-in delays
- **Content Size**: Very large posts may timeout (fallback mechanisms handle this)
- **Custom Fields**: Does not migrate Wix custom fields (WordPress doesn't have equivalent)
- **Comments**: Wix comments are not accessible via API

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

### Development Setup

1. Clone the repository
2. Set up a local WordPress installation
3. Symlink the plugin directory to your WordPress plugins folder
4. Enable WordPress debug mode for development

### Reporting Issues

Please report issues on the [GitHub Issues page](https://github.com/[your-username]/wix-to-wordpress-migrator/issues) with:
- WordPress version
- PHP version  
- Error messages from debug log
- Steps to reproduce the issue

## 📄 License

This project is licensed under the GPL v2 or later - see the [LICENSE](LICENSE) file for details.

## 🙏 Acknowledgments

- Built using WordPress Plugin API
- Utilizes Wix REST API
- Inspired by the WordPress community's need for better migration tools

## 📞 Support

- **Documentation**: This README file
- **Issues**: [GitHub Issues](https://github.com/[your-username]/wix-to-wordpress-migrator/issues)
- **WordPress Plugin Directory**: [Coming Soon]

---

**Made with ❤️ for the WordPress community**

If this plugin helped you migrate from Wix to WordPress, please consider giving it a ⭐ on GitHub!