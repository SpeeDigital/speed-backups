=== Speed Backups ===
Contributors: speedigital
Tags: backup, restore, migration, database backup, site backup, woocommerce backup
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete WordPress backup and restore solution. One-click full site backup including database, files, plugins, themes, and uploads.

== Description ==

Speed Backups is a powerful, user-friendly WordPress backup plugin that creates complete backups of your entire WordPress site with just one click. Perfect for site migration, disaster recovery, or simply keeping your site safe.

= Key Features =

* **One-Click Full Backup** - Create a complete backup of your site with a single click
* **Complete Site Backup** - Includes database, themes, plugins, uploads, and configuration files
* **WooCommerce Compatible** - Full support for WooCommerce stores including orders, products, and customer data
* **Large Site Support** - Chunked processing handles sites of any size without timeout issues
* **Direct Download** - Download backups directly to your computer
* **Easy Restore** - Upload a backup file to restore your site completely
* **URL Replacement** - Automatically updates URLs when migrating to a new domain
* **Progress Tracking** - Real-time progress updates during backup and restore
* **Secure** - Backups are protected from direct access

= What Gets Backed Up =

* **Database** - All WordPress tables including custom tables from plugins
* **wp-content** - Themes, plugins, uploads, and mu-plugins
* **Configuration** - wp-config.php and .htaccess files
* **WooCommerce Data** - Orders, products, customers, subscriptions, and settings
* **Custom Tables** - Any custom database tables from third-party plugins

= Perfect For =

* **Site Migration** - Move your WordPress site to a new host or domain
* **Disaster Recovery** - Restore your site after a crash or hack
* **Development** - Create copies of production sites for testing
* **Peace of Mind** - Know your site is always backed up

= WooCommerce Support =

Speed Backups fully supports WooCommerce stores:

* All WooCommerce database tables
* Product images and gallery
* Order history and customer data
* Payment and shipping settings
* Subscription data (if using WooCommerce Subscriptions)

= Large Sites Welcome =

Speed Backups uses advanced chunked processing to handle sites of any size:

* Process databases table-by-table
* Stream files into ZIP archives
* Resume interrupted backups
* No memory limit issues

== Installation ==

1. Upload the `speed-backups` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Tools > Speed Backups to create your first backup

== Frequently Asked Questions ==

= How large of a site can Speed Backups handle? =

Speed Backups uses chunked processing to handle sites of any size. Whether your site is 100MB or 50GB, Speed Backups will process it in manageable chunks to avoid timeout issues.

= Does it work with WooCommerce? =

Yes! Speed Backups automatically detects and backs up all WooCommerce tables including orders, products, customers, and subscriptions.

= Can I use it to migrate my site? =

Absolutely! Create a backup on your old site, download it, upload it to your new site, and restore. Speed Backups will automatically update all URLs to match your new domain.

= Where are backups stored? =

Backups are stored in `wp-content/uploads/speed-backups/`. This directory is protected from direct access.

= Can I schedule automatic backups? =

Scheduled backups are planned for a future release. Currently, backups are created manually.

= What PHP version do I need? =

Speed Backups requires PHP 7.4 or higher.

= Does it backup the entire WordPress installation? =

Speed Backups backs up the database and wp-content directory. WordPress core files are not included as they can be easily reinstalled.

== Screenshots ==

1. Main backup interface showing site information and backup options
2. Backup in progress with real-time progress bar
3. Restore interface with backup validation
4. List of existing backups with download and restore options

== Changelog ==

= 1.0.0 =
* Initial release
* Full site backup (database + files)
* One-click restore
* WooCommerce support
* Large site support with chunked processing
* Automatic URL replacement during restore
* Secure backup storage
* Progress tracking for backup and restore operations

== Upgrade Notice ==

= 1.0.0 =
Initial release of Speed Backups.
