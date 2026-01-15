<?php
/**
 * AJAX Handler Class
 *
 * Handles all AJAX requests for backup and restore operations.
 *
 * @package SpeedBackups
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AJAX Handler Class
 */
class Speed_Backups_Ajax_Handler {

    /**
     * Main plugin instance
     *
     * @var Speed_Backups
     */
    private $plugin;

    /**
     * Constructor
     *
     * @param Speed_Backups $plugin Main plugin instance
     */
    public function __construct( $plugin ) {
        $this->plugin = $plugin;
        $this->register_ajax_handlers();
    }

    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers() {
        // Backup actions
        add_action( 'wp_ajax_speed_backups_start_backup', array( $this, 'start_backup' ) );
        add_action( 'wp_ajax_speed_backups_process_backup', array( $this, 'process_backup' ) );
        add_action( 'wp_ajax_speed_backups_cancel_backup', array( $this, 'cancel_backup' ) );

        // Restore actions
        add_action( 'wp_ajax_speed_backups_upload_backup', array( $this, 'upload_backup' ) );
        add_action( 'wp_ajax_speed_backups_validate_backup', array( $this, 'validate_backup' ) );
        add_action( 'wp_ajax_speed_backups_start_restore', array( $this, 'start_restore' ) );
        add_action( 'wp_ajax_speed_backups_process_restore', array( $this, 'process_restore' ) );
        add_action( 'wp_ajax_speed_backups_cancel_restore', array( $this, 'cancel_restore' ) );

        // Job status
        add_action( 'wp_ajax_speed_backups_get_status', array( $this, 'get_status' ) );

        // File management
        add_action( 'wp_ajax_speed_backups_download', array( $this, 'download_backup' ) );
        add_action( 'wp_ajax_speed_backups_delete_backup', array( $this, 'delete_backup' ) );
        add_action( 'wp_ajax_speed_backups_get_backups', array( $this, 'get_backups' ) );

        // Site info
        add_action( 'wp_ajax_speed_backups_get_site_info', array( $this, 'get_site_info' ) );

        // Debug log (TEMPORARY - remove before production)
        add_action( 'wp_ajax_speed_backups_get_debug_log', array( $this, 'get_debug_log' ) );
        add_action( 'wp_ajax_speed_backups_clear_debug_log', array( $this, 'clear_debug_log' ) );
        add_action( 'wp_ajax_speed_backups_download_debug_log', array( $this, 'download_debug_log' ) );
    }

    /**
     * Verify AJAX request
     *
     * @param string $action Nonce action
     * @return bool
     */
    private function verify_request( $action = 'speed_backups_nonce' ) {
        // Check nonce
        if ( ! check_ajax_referer( $action, 'nonce', false ) ) {
            wp_send_json_error( array(
                'message' => __( 'Security check failed.', 'speed-backups' ),
            ) );
            return false;
        }

        // Check capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array(
                'message' => __( 'You do not have permission to perform this action.', 'speed-backups' ),
            ) );
            return false;
        }

        return true;
    }

    /**
     * Start a new backup
     */
    public function start_backup() {
        $this->verify_request();

        // Check if a backup is already in progress
        $active_job = $this->plugin->get_current_backup_job();
        if ( $active_job ) {
            wp_send_json_error( array(
                'message' => __( 'A backup is already in progress.', 'speed-backups' ),
                'job_id'  => $active_job['id'],
            ) );
            return;
        }

        // Get options from request
        $options = array(
            'include_database' => isset( $_POST['include_database'] ) ? (bool) $_POST['include_database'] : true,
            'include_files'    => isset( $_POST['include_files'] ) ? (bool) $_POST['include_files'] : true,
            'include_config'   => isset( $_POST['include_config'] ) ? (bool) $_POST['include_config'] : true,
            'custom_name'      => isset( $_POST['custom_name'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_name'] ) ) : '',
        );

        // Start backup
        $result = $this->plugin->backup->start_backup( $options );

        // Check for errors (e.g., insufficient disk space)
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
                'data'    => $result->get_error_data(),
            ) );
            return;
        }

        wp_send_json_success( array(
            'message' => __( 'Backup started.', 'speed-backups' ),
            'job_id'  => $result,
        ) );
    }

    /**
     * Process backup chunk
     */
    public function process_backup() {
        $this->verify_request();

        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

        if ( empty( $job_id ) ) {
            wp_send_json_error( array(
                'message' => __( 'Invalid job ID.', 'speed-backups' ),
            ) );
            return;
        }

        // Process chunk
        $result = $this->plugin->backup->process_chunk( $job_id );

        if ( ! $result['success'] ) {
            wp_send_json_error( array(
                'message' => $result['error'],
            ) );
            return;
        }

        // Get progress summary
        $progress = $this->plugin->processor->get_progress_summary( $job_id );

        $response = array(
            'message'  => $progress['message'],
            'progress' => $result['progress'],
            'phase'    => $progress['phase'],
            'complete' => $result['complete'],
        );

        if ( $result['complete'] && isset( $result['result'] ) ) {
            $response['result'] = $result['result'];
            $response['download_url'] = $this->plugin->backup->get_download_url( $result['result']['file_path'] );
        }

        wp_send_json_success( $response );
    }

    /**
     * Cancel backup
     */
    public function cancel_backup() {
        $this->verify_request();

        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

        if ( empty( $job_id ) ) {
            wp_send_json_error( array(
                'message' => __( 'Invalid job ID.', 'speed-backups' ),
            ) );
            return;
        }

        // Cancel the job
        $this->plugin->processor->cancel_job( $job_id );

        // Clean up temp files
        $state = $this->plugin->processor->get_state( $job_id );
        if ( ! empty( $state['temp_dir'] ) && file_exists( $state['temp_dir'] ) ) {
            $this->plugin->files->delete_directory( $state['temp_dir'] );
        }
        if ( ! empty( $state['zip_path'] ) && file_exists( $state['zip_path'] ) ) {
            wp_delete_file( $state['zip_path'] );
        }

        wp_send_json_success( array(
            'message' => __( 'Backup cancelled.', 'speed-backups' ),
        ) );
    }

    /**
     * Upload backup file
     */
    public function upload_backup() {
        $this->verify_request();

        if ( empty( $_FILES['backup_file'] ) ) {
            wp_send_json_error( array(
                'message' => __( 'No file uploaded.', 'speed-backups' ),
            ) );
            return;
        }

        $file = $_FILES['backup_file'];
        $result = $this->plugin->restore->handle_upload( $file );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
            ) );
            return;
        }

        // Validate the uploaded backup
        $validation = $this->plugin->restore->validate_backup( $result );

        wp_send_json_success( array(
            'message'    => __( 'File uploaded successfully.', 'speed-backups' ),
            'file_path'  => $result,
            'file_name'  => basename( $result ),
            'validation' => $validation,
        ) );
    }

    /**
     * Validate backup file
     */
    public function validate_backup() {
        $this->verify_request();

        $file_path = isset( $_POST['file_path'] ) ? sanitize_text_field( wp_unslash( $_POST['file_path'] ) ) : '';

        if ( empty( $file_path ) ) {
            wp_send_json_error( array(
                'message' => __( 'No file specified.', 'speed-backups' ),
            ) );
            return;
        }

        // Validate file path is within backup directory
        $backup_dir = speed_backups_get_backup_dir();
        $real_file_path = realpath( $file_path );
        $real_backup_dir = realpath( $backup_dir );

        if ( false === $real_file_path || false === $real_backup_dir || strpos( $real_file_path, $real_backup_dir ) !== 0 ) {
            wp_send_json_error( array(
                'message' => __( 'Invalid file path.', 'speed-backups' ),
            ) );
            return;
        }

        $validation = $this->plugin->restore->validate_backup( $file_path );

        if ( ! $validation['valid'] ) {
            wp_send_json_error( array(
                'message' => $validation['error'],
            ) );
            return;
        }

        wp_send_json_success( array(
            'validation' => $validation,
        ) );
    }

    /**
     * Start restore
     */
    public function start_restore() {
        $this->verify_request();

        // Check if a restore is already in progress
        $active_job = $this->plugin->get_current_restore_job();
        if ( $active_job ) {
            wp_send_json_error( array(
                'message' => __( 'A restore is already in progress.', 'speed-backups' ),
                'job_id'  => $active_job['id'],
            ) );
            return;
        }

        $file_path = isset( $_POST['file_path'] ) ? sanitize_text_field( wp_unslash( $_POST['file_path'] ) ) : '';

        if ( empty( $file_path ) ) {
            wp_send_json_error( array(
                'message' => __( 'No backup file specified.', 'speed-backups' ),
            ) );
            return;
        }

        // Validate file path is within backup directory
        $backup_dir = speed_backups_get_backup_dir();
        $real_file_path = realpath( $file_path );
        $real_backup_dir = realpath( $backup_dir );

        if ( false === $real_file_path || false === $real_backup_dir || strpos( $real_file_path, $real_backup_dir ) !== 0 ) {
            wp_send_json_error( array(
                'message' => __( 'Invalid file path.', 'speed-backups' ),
            ) );
            return;
        }

        // Get options
        $options = array(
            'restore_database' => isset( $_POST['restore_database'] ) ? (bool) $_POST['restore_database'] : true,
            'restore_files'    => isset( $_POST['restore_files'] ) ? (bool) $_POST['restore_files'] : true,
            'replace_urls'     => isset( $_POST['replace_urls'] ) ? (bool) $_POST['replace_urls'] : true,
        );

        // Start restore
        $result = $this->plugin->restore->start_restore( $file_path, $options );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
            ) );
            return;
        }

        wp_send_json_success( array(
            'message' => __( 'Restore started.', 'speed-backups' ),
            'job_id'  => $result,
        ) );
    }

    /**
     * Process restore chunk
     */
    public function process_restore() {
        $this->verify_request();

        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

        if ( empty( $job_id ) ) {
            wp_send_json_error( array(
                'message' => __( 'Invalid job ID.', 'speed-backups' ),
            ) );
            return;
        }

        // Process chunk
        $result = $this->plugin->restore->process_chunk( $job_id );

        if ( ! $result['success'] ) {
            wp_send_json_error( array(
                'message' => $result['error'],
            ) );
            return;
        }

        // Get progress summary
        $progress = $this->plugin->processor->get_progress_summary( $job_id );

        $response = array(
            'message'  => $progress['message'],
            'progress' => $result['progress'],
            'phase'    => $progress['phase'],
            'complete' => $result['complete'],
        );

        if ( $result['complete'] && isset( $result['result'] ) ) {
            $response['result'] = $result['result'];
        }

        wp_send_json_success( $response );
    }

    /**
     * Cancel restore
     */
    public function cancel_restore() {
        $this->verify_request();

        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

        if ( empty( $job_id ) ) {
            wp_send_json_error( array(
                'message' => __( 'Invalid job ID.', 'speed-backups' ),
            ) );
            return;
        }

        // Cancel the job
        $this->plugin->processor->cancel_job( $job_id );

        // Clean up temp files
        $state = $this->plugin->processor->get_state( $job_id );
        if ( ! empty( $state['temp_dir'] ) && file_exists( $state['temp_dir'] ) ) {
            $this->plugin->files->delete_directory( $state['temp_dir'] );
        }

        wp_send_json_success( array(
            'message' => __( 'Restore cancelled.', 'speed-backups' ),
        ) );
    }

    /**
     * Get job status
     */
    public function get_status() {
        $this->verify_request();

        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

        if ( empty( $job_id ) ) {
            wp_send_json_error( array(
                'message' => __( 'Invalid job ID.', 'speed-backups' ),
            ) );
            return;
        }

        $progress = $this->plugin->processor->get_progress_summary( $job_id );

        wp_send_json_success( $progress );
    }

    /**
     * Download backup file
     */
    public function download_backup() {
        // Verify nonce
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'speed_backups_download' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'speed-backups' ) );
        }

        // Check capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to download backups.', 'speed-backups' ) );
        }

        $file_name = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';

        if ( empty( $file_name ) ) {
            wp_die( esc_html__( 'No file specified.', 'speed-backups' ) );
        }

        $file_path = speed_backups_get_backup_dir() . '/' . $file_name;

        if ( ! file_exists( $file_path ) ) {
            wp_die( esc_html__( 'File not found.', 'speed-backups' ) );
        }

        // Serve the file
        $file_size = filesize( $file_path );

        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $file_name . '"' );
        header( 'Content-Length: ' . $file_size );
        header( 'Pragma: public' );
        header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );

        // Clean output buffer
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        // Stream the file
        $handle = fopen( $file_path, 'rb' );
        while ( ! feof( $handle ) ) {
            echo fread( $handle, 8192 );
            flush();
        }
        fclose( $handle );

        exit;
    }

    /**
     * Delete backup file
     */
    public function delete_backup() {
        $this->verify_request();

        $file_name = isset( $_POST['file_name'] ) ? sanitize_file_name( wp_unslash( $_POST['file_name'] ) ) : '';

        if ( empty( $file_name ) ) {
            wp_send_json_error( array(
                'message' => __( 'No file specified.', 'speed-backups' ),
            ) );
            return;
        }

        $result = $this->plugin->backup->delete_backup( $file_name );

        if ( ! $result ) {
            wp_send_json_error( array(
                'message' => __( 'Failed to delete backup.', 'speed-backups' ),
            ) );
            return;
        }

        wp_send_json_success( array(
            'message' => __( 'Backup deleted.', 'speed-backups' ),
        ) );
    }

    /**
     * Get list of backups
     */
    public function get_backups() {
        $this->verify_request();

        $backups = $this->plugin->backup->get_backups_list();

        wp_send_json_success( array(
            'backups' => $backups,
        ) );
    }

    /**
     * Get site info
     */
    public function get_site_info() {
        $this->verify_request();

        $site_info = $this->plugin->get_site_info();
        $db_stats = $this->plugin->database->get_stats();
        $disk_space = $this->plugin->backup->get_disk_space_info();

        wp_send_json_success( array(
            'site_info'  => $site_info,
            'db_stats'   => $db_stats,
            'disk_space' => $disk_space,
        ) );
    }

    /**
     * Get debug log content (TEMPORARY - remove before production)
     */
    public function get_debug_log() {
        $this->verify_request();

        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/speed-backups/debug-restore.log';

        if ( ! file_exists( $log_file ) ) {
            wp_send_json_success( array(
                'exists'  => false,
                'content' => __( 'No debug log file found. Run a restore to generate logs.', 'speed-backups' ),
                'size'    => 0,
            ) );
            return;
        }

        $size = filesize( $log_file );
        $content = file_get_contents( $log_file );

        // If file is too large, only get the last 100KB
        if ( $size > 100 * 1024 ) {
            $content = '... [Truncated - showing last 100KB] ...' . "\n\n" . substr( $content, -100 * 1024 );
        }

        wp_send_json_success( array(
            'exists'         => true,
            'content'        => $content,
            'size'           => $size,
            'size_formatted' => speed_backups_format_bytes( $size ),
        ) );
    }

    /**
     * Clear debug log (TEMPORARY - remove before production)
     */
    public function clear_debug_log() {
        $this->verify_request();

        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/speed-backups/debug-restore.log';

        if ( file_exists( $log_file ) ) {
            unlink( $log_file );
        }

        wp_send_json_success( array(
            'message' => __( 'Debug log cleared.', 'speed-backups' ),
        ) );
    }

    /**
     * Download debug log (TEMPORARY - remove before production)
     */
    public function download_debug_log() {
        // Verify nonce
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'speed_backups_download_log' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'speed-backups' ) );
        }

        // Check capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission.', 'speed-backups' ) );
        }

        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/speed-backups/debug-restore.log';

        if ( ! file_exists( $log_file ) ) {
            wp_die( esc_html__( 'Log file not found.', 'speed-backups' ) );
        }

        $file_name = 'speed-backups-debug-' . gmdate( 'Y-m-d-His' ) . '.log';

        header( 'Content-Type: text/plain' );
        header( 'Content-Disposition: attachment; filename="' . $file_name . '"' );
        header( 'Content-Length: ' . filesize( $log_file ) );

        readfile( $log_file );
        exit;
    }
}
