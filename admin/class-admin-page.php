<?php
/**
 * Admin Page Class
 *
 * Handles the admin interface for Speed Backups.
 *
 * @package SpeedBackups
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin Page Class
 */
class Speed_Backups_Admin_Page {

    /**
     * Main plugin instance
     *
     * @var Speed_Backups
     */
    private $plugin;

    /**
     * Page hook suffix
     *
     * @var string
     */
    private $page_hook;

    /**
     * Constructor
     *
     * @param Speed_Backups $plugin Main plugin instance
     */
    public function __construct( $plugin ) {
        $this->plugin = $plugin;
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        $this->page_hook = add_management_page(
            __( 'Speed Backups', 'speed-backups' ),
            __( 'Speed Backups', 'speed-backups' ),
            'manage_options',
            'speed-backups',
            array( $this, 'render_page' )
        );
    }

    /**
     * Enqueue scripts and styles
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_scripts( $hook ) {
        if ( $hook !== $this->page_hook ) {
            return;
        }

        // Enqueue styles
        wp_enqueue_style(
            'speed-backups-admin',
            SPEED_BACKUPS_PLUGIN_URL . 'admin/css/admin-style.css',
            array(),
            SPEED_BACKUPS_VERSION
        );

        // Enqueue scripts
        wp_enqueue_script(
            'speed-backups-admin',
            SPEED_BACKUPS_PLUGIN_URL . 'admin/js/admin-script.js',
            array( 'jquery' ),
            SPEED_BACKUPS_VERSION,
            true
        );

        // Localize script
        wp_localize_script( 'speed-backups-admin', 'speedBackups', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'speed_backups_nonce' ),
            'strings'   => array(
                'confirmDelete'       => __( 'Are you sure you want to delete this backup? This action cannot be undone.', 'speed-backups' ),
                'confirmRestore'      => __( 'Are you sure you want to restore from this backup? This will overwrite your current site data.', 'speed-backups' ),
                'backupStarted'       => __( 'Backup started...', 'speed-backups' ),
                'backupComplete'      => __( 'Backup complete!', 'speed-backups' ),
                'backupFailed'        => __( 'Backup failed.', 'speed-backups' ),
                'restoreStarted'      => __( 'Restore started...', 'speed-backups' ),
                'restoreComplete'     => __( 'Restore complete! The page will reload.', 'speed-backups' ),
                'restoreFailed'       => __( 'Restore failed.', 'speed-backups' ),
                'uploading'           => __( 'Uploading...', 'speed-backups' ),
                'uploadComplete'      => __( 'Upload complete.', 'speed-backups' ),
                'uploadFailed'        => __( 'Upload failed.', 'speed-backups' ),
                'processing'          => __( 'Processing...', 'speed-backups' ),
                'cancelled'           => __( 'Cancelled.', 'speed-backups' ),
                'downloading'         => __( 'Preparing download...', 'speed-backups' ),
                'errorOccurred'       => __( 'An error occurred.', 'speed-backups' ),
                'selectFile'          => __( 'Please select a backup file.', 'speed-backups' ),
                'invalidFile'         => __( 'Please select a valid ZIP file.', 'speed-backups' ),
            ),
            'maxUploadSize' => wp_max_upload_size(),
            'maxUploadSizeFormatted' => speed_backups_format_bytes( wp_max_upload_size() ),
        ) );
    }

    /**
     * Render admin page
     */
    public function render_page() {
        // Check user capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'speed-backups' ) );
        }

        // Get site info and backups
        $site_info = $this->plugin->get_site_info();
        $backups = $this->plugin->backup->get_backups_list();
        $db_stats = $this->plugin->database->get_stats();

        // Check for active jobs
        $active_backup = $this->plugin->get_current_backup_job();
        $active_restore = $this->plugin->get_current_restore_job();

        // Include template
        include SPEED_BACKUPS_PLUGIN_DIR . 'templates/admin-page.php';
    }

    /**
     * Get status badge HTML
     *
     * @param string $status Job status
     * @return string HTML
     */
    public static function get_status_badge( $status ) {
        $badges = array(
            'pending'   => '<span class="sb-badge sb-badge-pending">' . esc_html__( 'Pending', 'speed-backups' ) . '</span>',
            'running'   => '<span class="sb-badge sb-badge-running">' . esc_html__( 'Running', 'speed-backups' ) . '</span>',
            'completed' => '<span class="sb-badge sb-badge-success">' . esc_html__( 'Completed', 'speed-backups' ) . '</span>',
            'failed'    => '<span class="sb-badge sb-badge-error">' . esc_html__( 'Failed', 'speed-backups' ) . '</span>',
            'cancelled' => '<span class="sb-badge sb-badge-warning">' . esc_html__( 'Cancelled', 'speed-backups' ) . '</span>',
        );

        return isset( $badges[ $status ] ) ? $badges[ $status ] : '';
    }
}
