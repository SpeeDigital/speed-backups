<?php
/**
 * Plugin Name: Speed Backups
 * Plugin URI: https://github.com/SpeeDigital/speed-backups
 * Description: Complete WordPress backup and restore solution. One-click full site backup including database, files, plugins, themes, and uploads. Perfect for site migration and disaster recovery.
 * Version: 1.0.0
 * Author: SpeeDigital
 * Author URI: https://speedigital.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: speed-backups
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * @package SpeedBackups
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'SPEED_BACKUPS_VERSION', '1.0.0' );
define( 'SPEED_BACKUPS_PLUGIN_FILE', __FILE__ );
define( 'SPEED_BACKUPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPEED_BACKUPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPEED_BACKUPS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Minimum requirements
define( 'SPEED_BACKUPS_MIN_PHP_VERSION', '7.4' );
define( 'SPEED_BACKUPS_MIN_WP_VERSION', '5.0' );

/**
 * Check minimum requirements before loading the plugin
 *
 * @return bool
 */
function speed_backups_check_requirements() {
    $errors = array();

    // Check PHP version
    if ( version_compare( PHP_VERSION, SPEED_BACKUPS_MIN_PHP_VERSION, '<' ) ) {
        $errors[] = sprintf(
            /* translators: 1: Current PHP version, 2: Required PHP version */
            __( 'Speed Backups requires PHP version %2$s or higher. Your current version is %1$s.', 'speed-backups' ),
            PHP_VERSION,
            SPEED_BACKUPS_MIN_PHP_VERSION
        );
    }

    // Check WordPress version
    global $wp_version;
    if ( version_compare( $wp_version, SPEED_BACKUPS_MIN_WP_VERSION, '<' ) ) {
        $errors[] = sprintf(
            /* translators: 1: Current WordPress version, 2: Required WordPress version */
            __( 'Speed Backups requires WordPress version %2$s or higher. Your current version is %1$s.', 'speed-backups' ),
            $wp_version,
            SPEED_BACKUPS_MIN_WP_VERSION
        );
    }

    // Check ZipArchive extension
    if ( ! class_exists( 'ZipArchive' ) ) {
        $errors[] = __( 'Speed Backups requires the PHP ZipArchive extension to be installed and enabled.', 'speed-backups' );
    }

    // Check if we can write to wp-content
    $upload_dir = wp_upload_dir();
    if ( ! wp_is_writable( $upload_dir['basedir'] ) ) {
        $errors[] = __( 'Speed Backups requires write permissions to the WordPress uploads directory.', 'speed-backups' );
    }

    if ( ! empty( $errors ) ) {
        add_action( 'admin_notices', function() use ( $errors ) {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Speed Backups', 'speed-backups' ) . '</strong></p>';
            foreach ( $errors as $error ) {
                echo '<p>' . esc_html( $error ) . '</p>';
            }
            echo '</div>';
        });
        return false;
    }

    return true;
}

/**
 * Initialize the plugin
 */
function speed_backups_init() {
    // Check requirements
    if ( ! speed_backups_check_requirements() ) {
        return;
    }

    // Load text domain
    load_plugin_textdomain( 'speed-backups', false, dirname( SPEED_BACKUPS_PLUGIN_BASENAME ) . '/languages' );

    // Include required files
    require_once SPEED_BACKUPS_PLUGIN_DIR . 'includes/class-speed-backups.php';
    require_once SPEED_BACKUPS_PLUGIN_DIR . 'includes/class-database-handler.php';
    require_once SPEED_BACKUPS_PLUGIN_DIR . 'includes/class-file-handler.php';
    require_once SPEED_BACKUPS_PLUGIN_DIR . 'includes/class-chunked-processor.php';
    require_once SPEED_BACKUPS_PLUGIN_DIR . 'includes/class-backup-engine.php';
    require_once SPEED_BACKUPS_PLUGIN_DIR . 'includes/class-restore-engine.php';
    require_once SPEED_BACKUPS_PLUGIN_DIR . 'includes/class-ajax-handler.php';
    require_once SPEED_BACKUPS_PLUGIN_DIR . 'admin/class-admin-page.php';

    // DEBUG: Remove these lines before production release
    require_once SPEED_BACKUPS_PLUGIN_DIR . 'includes/class-debug-logger.php';
    Speed_Backups_Debug_Logger::init_error_handlers();

    // Initialize main plugin class
    Speed_Backups::get_instance();
}
add_action( 'plugins_loaded', 'speed_backups_init' );

/**
 * Plugin activation hook
 */
function speed_backups_activate() {
    // Check requirements on activation
    if ( ! speed_backups_check_requirements() ) {
        deactivate_plugins( SPEED_BACKUPS_PLUGIN_BASENAME );
        wp_die(
            esc_html__( 'Speed Backups cannot be activated. Please check the requirements.', 'speed-backups' ),
            esc_html__( 'Plugin Activation Error', 'speed-backups' ),
            array( 'back_link' => true )
        );
    }

    // Create backup directory
    $upload_dir = wp_upload_dir();
    $backup_dir = $upload_dir['basedir'] . '/speed-backups';

    if ( ! file_exists( $backup_dir ) ) {
        wp_mkdir_p( $backup_dir );
    }

    // Create .htaccess to protect backups
    $htaccess_file = $backup_dir . '/.htaccess';
    if ( ! file_exists( $htaccess_file ) ) {
        $htaccess_content = "# Speed Backups - Protect backup files\n";
        $htaccess_content .= "<IfModule mod_authz_core.c>\n";
        $htaccess_content .= "    Require all denied\n";
        $htaccess_content .= "</IfModule>\n";
        $htaccess_content .= "<IfModule !mod_authz_core.c>\n";
        $htaccess_content .= "    Order deny,allow\n";
        $htaccess_content .= "    Deny from all\n";
        $htaccess_content .= "</IfModule>\n";
        file_put_contents( $htaccess_file, $htaccess_content );
    }

    // Create index.php to prevent directory listing
    $index_file = $backup_dir . '/index.php';
    if ( ! file_exists( $index_file ) ) {
        file_put_contents( $index_file, '<?php // Silence is golden.' );
    }

    // Set default options
    $default_options = array(
        'version'           => SPEED_BACKUPS_VERSION,
        'chunk_size'        => 1000,
        'file_chunk_size'   => 100,
        'max_execution_time' => 30,
        'exclude_patterns'  => array(
            'wp-content/cache/*',
            'wp-content/backup*/*',
            'wp-content/uploads/speed-backups/*',
            'wp-content/wflogs/*',
            'wp-content/debug.log',
            'wp-content/uploads/wc-logs/*',
            'node_modules/*',
            '*.log',
            '.git/*',
            '.svn/*',
        ),
    );

    if ( ! get_option( 'speed_backups_options' ) ) {
        add_option( 'speed_backups_options', $default_options );
    }

    // Flush rewrite rules
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'speed_backups_activate' );

/**
 * Plugin deactivation hook
 */
function speed_backups_deactivate() {
    // Clean up any temporary backup files
    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/speed-backups/temp';

    if ( file_exists( $temp_dir ) ) {
        speed_backups_delete_directory( $temp_dir );
    }

    // Clear scheduled events if any
    wp_clear_scheduled_hook( 'speed_backups_cleanup' );

    // Flush rewrite rules
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'speed_backups_deactivate' );

/**
 * Helper function to recursively delete a directory
 *
 * @param string $dir Directory path
 * @return bool
 */
function speed_backups_delete_directory( $dir ) {
    if ( ! file_exists( $dir ) ) {
        return true;
    }

    if ( ! is_dir( $dir ) ) {
        return wp_delete_file( $dir );
    }

    $files = array_diff( scandir( $dir ), array( '.', '..' ) );

    foreach ( $files as $file ) {
        $path = $dir . '/' . $file;
        if ( is_dir( $path ) ) {
            speed_backups_delete_directory( $path );
        } else {
            wp_delete_file( $path );
        }
    }

    return rmdir( $dir );
}

/**
 * Get the backup directory path
 *
 * @return string
 */
function speed_backups_get_backup_dir() {
    $upload_dir = wp_upload_dir();
    return $upload_dir['basedir'] . '/speed-backups';
}

/**
 * Get the temporary directory path
 *
 * @return string
 */
function speed_backups_get_temp_dir() {
    return speed_backups_get_backup_dir() . '/temp';
}

/**
 * Format bytes to human readable format
 *
 * @param int $bytes Bytes to format
 * @param int $precision Decimal precision
 * @return string
 */
function speed_backups_format_bytes( $bytes, $precision = 2 ) {
    $units = array( 'B', 'KB', 'MB', 'GB', 'TB' );

    $bytes = max( $bytes, 0 );
    $pow = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
    $pow = min( $pow, count( $units ) - 1 );

    $bytes /= pow( 1024, $pow );

    return round( $bytes, $precision ) . ' ' . $units[ $pow ];
}
