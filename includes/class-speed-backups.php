<?php
/**
 * Main Speed Backups Plugin Class
 *
 * @package SpeedBackups
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main plugin class using singleton pattern
 */
class Speed_Backups {

    /**
     * Single instance of the class
     *
     * @var Speed_Backups
     */
    private static $instance = null;

    /**
     * Database handler instance
     *
     * @var Speed_Backups_Database_Handler
     */
    public $database;

    /**
     * File handler instance
     *
     * @var Speed_Backups_File_Handler
     */
    public $files;

    /**
     * Chunked processor instance
     *
     * @var Speed_Backups_Chunked_Processor
     */
    public $processor;

    /**
     * Backup engine instance
     *
     * @var Speed_Backups_Backup_Engine
     */
    public $backup;

    /**
     * Restore engine instance
     *
     * @var Speed_Backups_Restore_Engine
     */
    public $restore;

    /**
     * AJAX handler instance
     *
     * @var Speed_Backups_Ajax_Handler
     */
    public $ajax;

    /**
     * Admin page instance
     *
     * @var Speed_Backups_Admin_Page
     */
    public $admin;

    /**
     * Plugin options
     *
     * @var array
     */
    private $options;

    /**
     * Get single instance of the class
     *
     * @return Speed_Backups
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_options();
        $this->init_components();
        $this->init_hooks();
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new Exception( 'Cannot unserialize singleton' );
    }

    /**
     * Load plugin options
     */
    private function load_options() {
        $this->options = get_option( 'speed_backups_options', array() );
    }

    /**
     * Initialize plugin components
     */
    private function init_components() {
        // Initialize handlers
        $this->database  = new Speed_Backups_Database_Handler();
        $this->files     = new Speed_Backups_File_Handler();
        $this->processor = new Speed_Backups_Chunked_Processor();

        // Initialize engines
        $this->backup  = new Speed_Backups_Backup_Engine( $this );
        $this->restore = new Speed_Backups_Restore_Engine( $this );

        // Initialize AJAX handler
        $this->ajax = new Speed_Backups_Ajax_Handler( $this );

        // Initialize admin page (only in admin)
        if ( is_admin() ) {
            $this->admin = new Speed_Backups_Admin_Page( $this );
        }
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Add settings link on plugins page
        add_filter( 'plugin_action_links_' . SPEED_BACKUPS_PLUGIN_BASENAME, array( $this, 'add_plugin_links' ) );

        // Schedule cleanup cron job
        add_action( 'init', array( $this, 'schedule_cleanup' ) );
        add_action( 'speed_backups_cleanup', array( $this, 'cleanup_old_temp_files' ) );
    }

    /**
     * Add plugin action links
     *
     * @param array $links Existing links
     * @return array Modified links
     */
    public function add_plugin_links( $links ) {
        $plugin_links = array(
            '<a href="' . admin_url( 'admin.php?page=speed-backups' ) . '">' . __( 'Backup Now', 'speed-backups' ) . '</a>',
        );
        return array_merge( $plugin_links, $links );
    }

    /**
     * Schedule cleanup cron job
     */
    public function schedule_cleanup() {
        if ( ! wp_next_scheduled( 'speed_backups_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'speed_backups_cleanup' );
        }
    }

    /**
     * Cleanup old temporary files
     */
    public function cleanup_old_temp_files() {
        $temp_dir = speed_backups_get_temp_dir();

        if ( ! file_exists( $temp_dir ) ) {
            return;
        }

        $files = new DirectoryIterator( $temp_dir );
        $max_age = 24 * HOUR_IN_SECONDS; // 24 hours

        foreach ( $files as $file ) {
            if ( $file->isDot() ) {
                continue;
            }

            if ( time() - $file->getMTime() > $max_age ) {
                if ( $file->isDir() ) {
                    speed_backups_delete_directory( $file->getPathname() );
                } else {
                    wp_delete_file( $file->getPathname() );
                }
            }
        }
    }

    /**
     * Get plugin option
     *
     * @param string $key Option key
     * @param mixed  $default Default value
     * @return mixed
     */
    public function get_option( $key, $default = null ) {
        return isset( $this->options[ $key ] ) ? $this->options[ $key ] : $default;
    }

    /**
     * Set plugin option
     *
     * @param string $key Option key
     * @param mixed  $value Option value
     * @return bool
     */
    public function set_option( $key, $value ) {
        $this->options[ $key ] = $value;
        return update_option( 'speed_backups_options', $this->options );
    }

    /**
     * Get all plugin options
     *
     * @return array
     */
    public function get_all_options() {
        return $this->options;
    }

    /**
     * Get site information for backup manifest
     *
     * @return array
     */
    public function get_site_info() {
        global $wpdb, $wp_version;

        // Get database size
        $db_size = 0;
        $tables = $wpdb->get_results( "SHOW TABLE STATUS", ARRAY_A );
        foreach ( $tables as $table ) {
            if ( strpos( $table['Name'], $wpdb->prefix ) === 0 ) {
                $db_size += $table['Data_length'] + $table['Index_length'];
            }
        }

        // Get files size (approximate for wp-content)
        $wp_content_size = $this->get_directory_size( WP_CONTENT_DIR );

        // Get active plugins
        $active_plugins = get_option( 'active_plugins', array() );

        // Get active theme
        $active_theme = wp_get_theme();

        return array(
            'site_url'        => get_site_url(),
            'home_url'        => get_home_url(),
            'wp_version'      => $wp_version,
            'php_version'     => PHP_VERSION,
            'mysql_version'   => $wpdb->db_version(),
            'table_prefix'    => $wpdb->prefix,
            'charset'         => $wpdb->charset,
            'collate'         => $wpdb->collate,
            'db_size'         => $db_size,
            'db_size_formatted' => speed_backups_format_bytes( $db_size ),
            'files_size'      => $wp_content_size,
            'files_size_formatted' => speed_backups_format_bytes( $wp_content_size ),
            'active_plugins'  => $active_plugins,
            'active_theme'    => $active_theme->get( 'Name' ),
            'multisite'       => is_multisite(),
            'server_software' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown',
            'max_upload_size' => wp_max_upload_size(),
            'max_upload_size_formatted' => speed_backups_format_bytes( wp_max_upload_size() ),
        );
    }

    /**
     * Get directory size recursively
     *
     * @param string $path Directory path
     * @return int Size in bytes
     */
    private function get_directory_size( $path ) {
        $size = 0;

        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            return $size;
        }

        $exclude_patterns = $this->get_option( 'exclude_patterns', array() );

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ( $iterator as $file ) {
                if ( $file->isFile() && $file->isReadable() ) {
                    // Check exclusions
                    $relative_path = str_replace( ABSPATH, '', $file->getPathname() );
                    $skip = false;

                    foreach ( $exclude_patterns as $pattern ) {
                        if ( fnmatch( $pattern, $relative_path ) ) {
                            $skip = true;
                            break;
                        }
                    }

                    if ( ! $skip ) {
                        $size += $file->getSize();
                    }
                }
            }
        } catch ( Exception $e ) {
            // Log error but continue
            error_log( 'Speed Backups: Error calculating directory size - ' . $e->getMessage() );
        }

        return $size;
    }

    /**
     * Check if a backup is currently in progress
     *
     * @return bool|array False if no backup in progress, or job data if in progress
     */
    public function get_current_backup_job() {
        return $this->processor->get_active_job( 'backup' );
    }

    /**
     * Check if a restore is currently in progress
     *
     * @return bool|array False if no restore in progress, or job data if in progress
     */
    public function get_current_restore_job() {
        return $this->processor->get_active_job( 'restore' );
    }

    /**
     * Log a message
     *
     * @param string $message Message to log
     * @param string $level   Log level (info, warning, error)
     */
    public function log( $message, $level = 'info' ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( '[Speed Backups][%s] %s', strtoupper( $level ), $message ) );
        }
    }
}
