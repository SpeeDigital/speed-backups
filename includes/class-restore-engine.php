<?php
/**
 * Restore Engine Class
 *
 * Orchestrates the entire restore process including validation,
 * file extraction, database import, and URL replacement.
 *
 * @package SpeedBackups
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Restore Engine Class
 */
class Speed_Backups_Restore_Engine {

    /**
     * Main plugin instance
     *
     * @var Speed_Backups
     */
    private $plugin;

    /**
     * Restore phases and their weights for progress calculation
     *
     * @var array
     */
    private $phases = array(
        'validate'  => array( 'weight' => 10, 'label' => 'Validating backup' ),
        'extract'   => array( 'weight' => 30, 'label' => 'Extracting files' ),
        'database'  => array( 'weight' => 30, 'label' => 'Restoring database' ),
        'files'     => array( 'weight' => 20, 'label' => 'Restoring files' ),
        'finalize'  => array( 'weight' => 10, 'label' => 'Finalizing restore' ),
    );

    /**
     * Constructor
     *
     * @param Speed_Backups $plugin Main plugin instance
     */
    public function __construct( $plugin ) {
        $this->plugin = $plugin;
    }

    /**
     * Validate a backup file
     *
     * @param string $file_path Path to backup ZIP
     * @return array Validation result
     */
    public function validate_backup( $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            return array(
                'valid'   => false,
                'error'   => __( 'Backup file not found.', 'speed-backups' ),
            );
        }

        // Check file type
        $file_info = wp_check_filetype( $file_path );
        if ( 'zip' !== $file_info['ext'] ) {
            return array(
                'valid'   => false,
                'error'   => __( 'Invalid file type. Please upload a ZIP file.', 'speed-backups' ),
            );
        }

        // Try to read manifest
        $manifest_content = $this->plugin->files->get_file_from_zip( $file_path, 'manifest.json' );

        if ( false === $manifest_content ) {
            return array(
                'valid'   => false,
                'error'   => __( 'Invalid backup file. Manifest not found.', 'speed-backups' ),
            );
        }

        $manifest = json_decode( $manifest_content, true );

        if ( ! $manifest || ! isset( $manifest['version'] ) ) {
            return array(
                'valid'   => false,
                'error'   => __( 'Invalid backup manifest.', 'speed-backups' ),
            );
        }

        // Get ZIP contents info
        $zip_info = $this->plugin->files->get_zip_contents( $file_path );

        if ( is_wp_error( $zip_info ) ) {
            return array(
                'valid'   => false,
                'error'   => $zip_info->get_error_message(),
            );
        }

        // Check for required files
        $has_database = false;
        foreach ( $zip_info['files'] as $file ) {
            if ( 'database.sql' === $file['name'] ) {
                $has_database = true;
                break;
            }
        }

        return array(
            'valid'       => true,
            'manifest'    => $manifest,
            'file_count'  => $zip_info['file_count'],
            'total_size'  => $zip_info['total_size'],
            'total_size_formatted' => $zip_info['total_size_formatted'],
            'has_database' => $has_database,
            'warnings'    => $this->get_restore_warnings( $manifest ),
        );
    }

    /**
     * Get warnings for restore operation
     *
     * @param array $manifest Backup manifest
     * @return array Warnings
     */
    private function get_restore_warnings( $manifest ) {
        $warnings = array();

        // Check WordPress version
        global $wp_version;
        if ( isset( $manifest['wp_version'] ) && version_compare( $manifest['wp_version'], $wp_version, '>' ) ) {
            $warnings[] = sprintf(
                __( 'Backup was created with WordPress %s, but you are running %s.', 'speed-backups' ),
                $manifest['wp_version'],
                $wp_version
            );
        }

        // Check PHP version
        if ( isset( $manifest['php_version'] ) && version_compare( $manifest['php_version'], PHP_VERSION, '>' ) ) {
            $warnings[] = sprintf(
                __( 'Backup was created with PHP %s, but you are running %s.', 'speed-backups' ),
                $manifest['php_version'],
                PHP_VERSION
            );
        }

        // Check table prefix
        global $wpdb;
        if ( isset( $manifest['table_prefix'] ) && $manifest['table_prefix'] !== $wpdb->prefix ) {
            $warnings[] = sprintf(
                __( 'Table prefix differs: backup uses "%s", current site uses "%s". Tables will be renamed.', 'speed-backups' ),
                $manifest['table_prefix'],
                $wpdb->prefix
            );
        }

        // Check URLs
        if ( isset( $manifest['site_url'] ) && $manifest['site_url'] !== get_site_url() ) {
            $warnings[] = sprintf(
                __( 'Site URL differs: backup from "%s", restoring to "%s". URLs will be updated.', 'speed-backups' ),
                $manifest['site_url'],
                get_site_url()
            );
        }

        // Check multisite
        if ( isset( $manifest['multisite'] ) && $manifest['multisite'] && ! is_multisite() ) {
            $warnings[] = __( 'Backup is from a multisite installation, but current site is not multisite.', 'speed-backups' );
        }

        return $warnings;
    }

    /**
     * Start a new restore job
     *
     * @param string $file_path Path to backup file
     * @param array  $options   Restore options
     * @return string|WP_Error Job ID or error
     */
    public function start_restore( $file_path, $options = array() ) {
        // DEBUG: Start new restore session
        Speed_Backups_Debug_Logger::start_restore_session();
        Speed_Backups_Debug_Logger::log_function_start( 'start_restore', array( 'file_path' => $file_path ) );

        // Validate first
        $validation = $this->validate_backup( $file_path );

        if ( ! $validation['valid'] ) {
            Speed_Backups_Debug_Logger::error( 'Backup validation failed', 'start_restore', $validation['error'] );
            return new WP_Error( 'invalid_backup', $validation['error'] );
        }

        Speed_Backups_Debug_Logger::log( 'Backup validation passed', 'start_restore' );

        $defaults = array(
            'restore_database' => true,
            'restore_files'    => true,
            'replace_urls'     => true,
            'old_url'          => isset( $validation['manifest']['site_url'] ) ? $validation['manifest']['site_url'] : '',
            'new_url'          => get_site_url(),
        );

        $options = wp_parse_args( $options, $defaults );
        $options['file_path'] = $file_path;
        $options['manifest'] = $validation['manifest'];

        Speed_Backups_Debug_Logger::log( 'Restore options', 'start_restore', array(
            'restore_database' => $options['restore_database'] ? 'YES' : 'NO',
            'restore_files'    => $options['restore_files'] ? 'YES' : 'NO',
            'replace_urls'     => $options['replace_urls'] ? 'YES' : 'NO',
        ) );

        // Create job
        $job_id = $this->plugin->processor->create_job( 'restore', $options );
        Speed_Backups_Debug_Logger::log( 'Job created', 'start_restore', array( 'job_id' => $job_id ) );

        // Update status to running
        $this->plugin->processor->update_status( $job_id, 'running', __( 'Starting restore...', 'speed-backups' ) );

        // Initialize state
        $this->plugin->processor->update_state( $job_id, array(
            'phase'          => 'validate',
            'temp_dir'       => '',
            'extract_offset' => 0,
            'files_restored' => 0,
            'db_imported'    => false,
            'urls_replaced'  => false,
        ) );

        // Verify job was created
        $verify = $this->plugin->processor->get_job( $job_id );
        Speed_Backups_Debug_Logger::log( 'Job creation verification', 'start_restore', array(
            'job_exists' => $verify ? 'YES' : 'NO',
        ) );

        Speed_Backups_Debug_Logger::log_function_end( 'start_restore', $job_id );
        return $job_id;
    }

    /**
     * Process the next chunk of a restore job
     *
     * @param string $job_id Job ID
     * @return array Progress status
     */
    public function process_chunk( $job_id ) {
        Speed_Backups_Debug_Logger::log_function_start( 'process_chunk', array( 'job_id' => $job_id ) );

        $job = $this->plugin->processor->get_job( $job_id );
        Speed_Backups_Debug_Logger::log_job_state( $job_id, $job );

        if ( ! $job ) {
            Speed_Backups_Debug_Logger::error( 'JOB NOT FOUND in process_chunk!', 'process_chunk', array( 'job_id' => $job_id ) );
            return array(
                'success' => false,
                'error'   => __( 'Job not found.', 'speed-backups' ),
            );
        }

        if ( 'running' !== $job['status'] ) {
            return array(
                'success' => false,
                'error'   => __( 'Job is not running.', 'speed-backups' ),
            );
        }

        // Start timer
        $this->plugin->processor->start_timer();

        $state = $job['state'];
        $phase = isset( $state['phase'] ) ? $state['phase'] : 'validate';

        try {
            switch ( $phase ) {
                case 'validate':
                    return $this->process_validate( $job_id );

                case 'extract':
                    return $this->process_extract( $job_id );

                case 'database':
                    return $this->process_database( $job_id );

                case 'files':
                    return $this->process_files( $job_id );

                case 'finalize':
                    return $this->process_finalize( $job_id );

                default:
                    return array(
                        'success' => false,
                        'error'   => __( 'Unknown restore phase.', 'speed-backups' ),
                    );
            }
        } catch ( Exception $e ) {
            $this->plugin->processor->fail_job( $job_id, $e->getMessage() );
            $this->plugin->log( 'Restore failed: ' . $e->getMessage(), 'error' );

            return array(
                'success' => false,
                'error'   => $e->getMessage(),
            );
        }
    }

    /**
     * Process validation phase
     *
     * @param string $job_id Job ID
     * @return array Progress status
     */
    private function process_validate( $job_id ) {
        $job = $this->plugin->processor->get_job( $job_id );

        $this->plugin->processor->update_phase(
            $job_id,
            'validate',
            0,
            __( 'Validating backup file...', 'speed-backups' )
        );

        // Create temp directory for extraction
        $temp_dir = speed_backups_get_temp_dir() . '/restore-' . $job_id;
        if ( ! file_exists( $temp_dir ) ) {
            wp_mkdir_p( $temp_dir );
        }

        // Get ZIP info
        $file_path = $job['params']['file_path'];
        $zip_info = $this->plugin->files->get_zip_contents( $file_path );

        if ( is_wp_error( $zip_info ) ) {
            throw new Exception( $zip_info->get_error_message() );
        }

        $this->plugin->processor->update_state( $job_id, array(
            'phase'       => 'extract',
            'temp_dir'    => $temp_dir,
            'total_files' => $zip_info['file_count'],
        ) );

        $this->plugin->processor->update_phase(
            $job_id,
            'validate',
            100,
            __( 'Validation complete.', 'speed-backups' )
        );

        return array(
            'success'  => true,
            'complete' => false,
            'progress' => $this->calculate_progress( $job_id ),
        );
    }

    /**
     * Process extraction phase (chunked)
     *
     * @param string $job_id Job ID
     * @return array Progress status
     */
    private function process_extract( $job_id ) {
        $job = $this->plugin->processor->get_job( $job_id );
        $state = $job['state'];

        $file_path = $job['params']['file_path'];
        $offset = isset( $state['extract_offset'] ) ? $state['extract_offset'] : 0;
        $total = isset( $state['total_files'] ) ? $state['total_files'] : 1;

        $this->plugin->processor->update_phase(
            $job_id,
            'extract',
            ( $offset / $total ) * 100,
            sprintf(
                __( 'Extracting files: %d / %d', 'speed-backups' ),
                $offset,
                $total
            )
        );

        // Extract in chunks
        $result = $this->plugin->files->extract_zip_chunked(
            $file_path,
            $state['temp_dir'],
            $offset,
            100
        );

        if ( ! $result['success'] ) {
            throw new Exception( $result['error'] );
        }

        $this->plugin->processor->update_state( $job_id, array(
            'extract_offset' => $result['offset'],
        ) );

        if ( $result['complete'] ) {
            // Determine next phase
            $next_phase = $job['params']['restore_database'] ? 'database' : 'files';

            $this->plugin->processor->update_state( $job_id, array(
                'phase' => $next_phase,
            ) );

            $this->plugin->processor->update_phase(
                $job_id,
                'extract',
                100,
                __( 'Extraction complete.', 'speed-backups' )
            );
        }

        return array(
            'success'  => true,
            'complete' => false,
            'progress' => $this->calculate_progress( $job_id ),
        );
    }

    /**
     * Process database restore phase
     *
     * @param string $job_id Job ID
     * @return array Progress status
     */
    private function process_database( $job_id ) {
        // DEBUG: Start logging
        Speed_Backups_Debug_Logger::log_function_start( 'process_database', array( 'job_id' => $job_id ) );

        $job = $this->plugin->processor->get_job( $job_id );
        Speed_Backups_Debug_Logger::log_job_state( $job_id, $job );

        if ( ! $job ) {
            Speed_Backups_Debug_Logger::error( 'Job is NULL at start of process_database', 'process_database' );
            throw new Exception( __( 'Job not found at start of database restore.', 'speed-backups' ) );
        }

        $state = $job['state'];
        Speed_Backups_Debug_Logger::log( 'State retrieved', 'process_database', array(
            'phase'    => isset( $state['phase'] ) ? $state['phase'] : 'N/A',
            'temp_dir' => isset( $state['temp_dir'] ) ? $state['temp_dir'] : 'N/A',
        ) );

        if ( ! $job['params']['restore_database'] ) {
            Speed_Backups_Debug_Logger::log( 'Skipping database restore (not requested)', 'process_database' );
            $this->plugin->processor->update_state( $job_id, array(
                'phase' => 'files',
            ) );

            return array(
                'success'  => true,
                'complete' => false,
                'progress' => $this->calculate_progress( $job_id ),
            );
        }

        $this->plugin->processor->update_phase(
            $job_id,
            'database',
            0,
            __( 'Restoring database...', 'speed-backups' )
        );

        $db_file = $state['temp_dir'] . '/database.sql';
        Speed_Backups_Debug_Logger::log( 'Database file path: ' . $db_file, 'process_database' );
        Speed_Backups_Debug_Logger::log( 'Database file exists: ' . ( file_exists( $db_file ) ? 'YES' : 'NO' ), 'process_database' );

        if ( ! file_exists( $db_file ) ) {
            Speed_Backups_Debug_Logger::log( 'No database file found, skipping', 'process_database' );
            $this->plugin->processor->update_state( $job_id, array(
                'phase'       => 'files',
                'db_imported' => false,
            ) );

            return array(
                'success'  => true,
                'complete' => false,
                'progress' => $this->calculate_progress( $job_id ),
            );
        }

        Speed_Backups_Debug_Logger::log( 'Database file size: ' . filesize( $db_file ) . ' bytes', 'process_database' );

        // CRITICAL: Save job data to a file before database import
        // The database import will overwrite wp_options, destroying our job tracking
        $job_backup_file = $state['temp_dir'] . '/job_data.json';
        Speed_Backups_Debug_Logger::log( 'Job backup file path: ' . $job_backup_file, 'process_database' );

        $job_data_to_save = $this->plugin->processor->get_job( $job_id );
        Speed_Backups_Debug_Logger::log( 'Job data retrieved for backup', 'process_database', array(
            'job_exists' => $job_data_to_save ? 'YES' : 'NO',
            'job_id'     => $job_id,
        ) );

        if ( ! $job_data_to_save ) {
            Speed_Backups_Debug_Logger::error( 'Job data is NULL before saving to file', 'process_database' );
            throw new Exception( __( 'Job data is NULL before database import.', 'speed-backups' ) );
        }

        $job_json = json_encode( $job_data_to_save );
        Speed_Backups_Debug_Logger::log( 'Job JSON encoded', 'process_database', array(
            'json_length'  => strlen( $job_json ),
            'json_valid'   => $job_json !== false ? 'YES' : 'NO',
            'json_error'   => json_last_error_msg(),
        ) );

        if ( false === $job_json ) {
            Speed_Backups_Debug_Logger::error( 'Failed to encode job data', 'process_database' );
            throw new Exception( __( 'Failed to encode job data for backup.', 'speed-backups' ) );
        }

        $bytes_written = file_put_contents( $job_backup_file, $job_json );
        Speed_Backups_Debug_Logger::log( 'Job data written to file', 'process_database', array(
            'bytes_written' => $bytes_written,
            'file_exists'   => file_exists( $job_backup_file ) ? 'YES' : 'NO',
        ) );

        if ( false === $bytes_written ) {
            Speed_Backups_Debug_Logger::error( 'Failed to write job data file', 'process_database' );
            throw new Exception( __( 'Failed to save job data before database import.', 'speed-backups' ) );
        }

        // Get old and new prefix
        $manifest = $job['params']['manifest'];
        $old_prefix = isset( $manifest['table_prefix'] ) ? $manifest['table_prefix'] : '';
        global $wpdb;
        $new_prefix = $wpdb->prefix;

        Speed_Backups_Debug_Logger::log( 'Table prefix info', 'process_database', array(
            'old_prefix' => $old_prefix,
            'new_prefix' => $new_prefix,
        ) );

        // Import database
        Speed_Backups_Debug_Logger::log( '>>> STARTING DATABASE IMPORT <<<', 'process_database' );
        $result = $this->plugin->database->import_database( $db_file, $old_prefix, $new_prefix );
        Speed_Backups_Debug_Logger::log( '>>> DATABASE IMPORT COMPLETED <<<', 'process_database', array(
            'success'          => isset( $result['success'] ) ? ( $result['success'] ? 'YES' : 'NO' ) : 'N/A',
            'queries_executed' => isset( $result['queries_executed'] ) ? $result['queries_executed'] : 'N/A',
            'errors_count'     => isset( $result['errors'] ) ? count( $result['errors'] ) : 0,
        ) );

        // Log first few errors if any
        if ( ! empty( $result['errors'] ) ) {
            $errors_to_log = array_slice( $result['errors'], 0, 5 );
            Speed_Backups_Debug_Logger::error( 'Database import errors (first 5)', 'process_database', $errors_to_log );
        }

        // CRITICAL: Restore job data from file after database import
        Speed_Backups_Debug_Logger::log( 'Checking job backup file after import', 'process_database', array(
            'file_exists' => file_exists( $job_backup_file ) ? 'YES' : 'NO',
        ) );

        if ( ! file_exists( $job_backup_file ) ) {
            Speed_Backups_Debug_Logger::error( 'Job backup file MISSING after import!', 'process_database' );
            throw new Exception( __( 'Job data backup file missing after database import.', 'speed-backups' ) );
        }

        $job_file_contents = file_get_contents( $job_backup_file );
        Speed_Backups_Debug_Logger::log( 'Job file contents read', 'process_database', array(
            'contents_length' => $job_file_contents !== false ? strlen( $job_file_contents ) : 'FALSE',
        ) );

        if ( false === $job_file_contents ) {
            Speed_Backups_Debug_Logger::error( 'Failed to read job backup file', 'process_database' );
            throw new Exception( __( 'Failed to read job data after database import.', 'speed-backups' ) );
        }

        $restored_job_data = json_decode( $job_file_contents, true );
        Speed_Backups_Debug_Logger::log( 'Job data decoded from JSON', 'process_database', array(
            'is_array'   => is_array( $restored_job_data ) ? 'YES' : 'NO',
            'json_error' => json_last_error_msg(),
        ) );

        if ( ! is_array( $restored_job_data ) ) {
            Speed_Backups_Debug_Logger::error( 'Decoded job data is not an array', 'process_database' );
            throw new Exception( __( 'Failed to decode job data after database import.', 'speed-backups' ) );
        }

        // CRITICAL: Flush WordPress object cache after database import
        // The database tables were dropped and recreated, so all cached data is stale
        // Without this, the next AJAX request will read from stale cache and not find the job
        wp_cache_flush();
        Speed_Backups_Debug_Logger::log( 'WordPress object cache flushed after database import', 'process_database' );

        // Re-save the job data to the newly imported database
        // IMPORTANT: Use save_job_direct to bypass WordPress cache and write directly to DB
        Speed_Backups_Debug_Logger::log( 'Saving job data to newly imported database (direct)', 'process_database', array(
            'job_id' => $job_id,
        ) );
        $this->plugin->processor->save_job_direct( $job_id, $restored_job_data );

        // Verify job was saved correctly
        $verify_job = $this->plugin->processor->get_job( $job_id );
        Speed_Backups_Debug_Logger::log( 'Job verification after save', 'process_database', array(
            'job_exists' => $verify_job ? 'YES' : 'NO',
            'job_status' => $verify_job ? $verify_job['status'] : 'N/A',
        ) );

        if ( ! $verify_job ) {
            Speed_Backups_Debug_Logger::error( 'Job verification FAILED - job not found after save!', 'process_database' );
        }

        if ( ! $result['success'] && ! empty( $result['errors'] ) ) {
            // Log errors but continue
            foreach ( $result['errors'] as $error ) {
                $this->plugin->processor->add_error( $job_id, $error['error'], 'database_import' );
            }
        }

        $this->plugin->processor->update_phase(
            $job_id,
            'database',
            50,
            __( 'Database imported. Updating URLs...', 'speed-backups' )
        );

        // Replace URLs if needed
        if ( $job['params']['replace_urls'] && $job['params']['old_url'] !== $job['params']['new_url'] ) {
            $search_replace_result = $this->plugin->database->search_replace(
                $job['params']['old_url'],
                $job['params']['new_url']
            );

            // Also replace with different protocol variations
            $old_url_http = str_replace( 'https://', 'http://', $job['params']['old_url'] );
            $new_url_http = str_replace( 'https://', 'http://', $job['params']['new_url'] );

            if ( $old_url_http !== $job['params']['old_url'] ) {
                $this->plugin->database->search_replace( $old_url_http, $new_url_http );
            }

            $this->plugin->processor->update_state( $job_id, array(
                'urls_replaced' => true,
                'urls_changes'  => $search_replace_result['total_changes'],
            ) );
        }

        $this->plugin->processor->update_state( $job_id, array(
            'phase'       => 'files',
            'db_imported' => true,
        ) );

        $this->plugin->processor->update_phase(
            $job_id,
            'database',
            100,
            sprintf(
                __( 'Database restored: %d queries executed.', 'speed-backups' ),
                $result['queries_executed']
            )
        );

        return array(
            'success'  => true,
            'complete' => false,
            'progress' => $this->calculate_progress( $job_id ),
        );
    }

    /**
     * Process files restore phase
     *
     * @param string $job_id Job ID
     * @return array Progress status
     */
    private function process_files( $job_id ) {
        $job = $this->plugin->processor->get_job( $job_id );
        $state = $job['state'];

        if ( ! $job['params']['restore_files'] ) {
            $this->plugin->processor->update_state( $job_id, array(
                'phase' => 'finalize',
            ) );

            return array(
                'success'  => true,
                'complete' => false,
                'progress' => $this->calculate_progress( $job_id ),
            );
        }

        $this->plugin->processor->update_phase(
            $job_id,
            'files',
            0,
            __( 'Restoring files...', 'speed-backups' )
        );

        // Restore wp-content
        $wp_content_source = $state['temp_dir'] . '/wp-content';

        if ( file_exists( $wp_content_source ) ) {
            $result = $this->plugin->files->copy_directory(
                $wp_content_source,
                WP_CONTENT_DIR,
                array(
                    'uploads/speed-backups/*', // Don't overwrite our backup directory
                    'cache/*',
                )
            );

            if ( ! $result['success'] && ! empty( $result['errors'] ) ) {
                foreach ( $result['errors'] as $error_file ) {
                    $this->plugin->processor->add_error( $job_id, 'Failed to copy: ' . $error_file, 'file_restore' );
                }
            }

            $this->plugin->processor->update_state( $job_id, array(
                'files_restored' => $result['copied'],
            ) );
        }

        $this->plugin->processor->update_state( $job_id, array(
            'phase' => 'finalize',
        ) );

        $this->plugin->processor->update_phase(
            $job_id,
            'files',
            100,
            __( 'Files restored.', 'speed-backups' )
        );

        return array(
            'success'  => true,
            'complete' => false,
            'progress' => $this->calculate_progress( $job_id ),
        );
    }

    /**
     * Process finalization phase
     *
     * @param string $job_id Job ID
     * @return array Progress status
     */
    private function process_finalize( $job_id ) {
        $job = $this->plugin->processor->get_job( $job_id );
        $state = $job['state'];

        $this->plugin->processor->update_phase(
            $job_id,
            'finalize',
            0,
            __( 'Finalizing restore...', 'speed-backups' )
        );

        // Clean up temp directory
        if ( ! empty( $state['temp_dir'] ) && file_exists( $state['temp_dir'] ) ) {
            $this->plugin->files->delete_directory( $state['temp_dir'] );
        }

        // Flush rewrite rules
        flush_rewrite_rules();

        // Clear any object cache
        wp_cache_flush();

        // Clear transients (use esc_like to properly escape the pattern)
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( '_transient_' ) . '%'
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( '_site_transient_' ) . '%'
            )
        );

        // Complete the job
        $this->plugin->processor->complete_job( $job_id, array(
            'db_imported'     => isset( $state['db_imported'] ) && $state['db_imported'],
            'files_restored'  => isset( $state['files_restored'] ) ? $state['files_restored'] : 0,
            'urls_replaced'   => isset( $state['urls_replaced'] ) && $state['urls_replaced'],
            'urls_changes'    => isset( $state['urls_changes'] ) ? $state['urls_changes'] : 0,
        ) );

        $this->plugin->processor->update_phase(
            $job_id,
            'finalize',
            100,
            __( 'Restore complete!', 'speed-backups' )
        );

        $this->plugin->log( 'Restore completed successfully.', 'info' );

        return array(
            'success'  => true,
            'complete' => true,
            'progress' => 100,
            'result'   => array(
                'db_imported'    => isset( $state['db_imported'] ) && $state['db_imported'],
                'files_restored' => isset( $state['files_restored'] ) ? $state['files_restored'] : 0,
                'urls_replaced'  => isset( $state['urls_replaced'] ) && $state['urls_replaced'],
            ),
        );
    }

    /**
     * Calculate overall progress
     *
     * @param string $job_id Job ID
     * @return int Progress percentage
     */
    private function calculate_progress( $job_id ) {
        $job = $this->plugin->processor->get_job( $job_id );
        $state = $job['state'];
        $current_phase = isset( $state['phase'] ) ? $state['phase'] : 'validate';

        $phases_progress = array();
        $found_current = false;

        foreach ( $this->phases as $phase_key => $phase_data ) {
            if ( $phase_key === $current_phase ) {
                $found_current = true;
                $phases_progress[] = array(
                    'weight'   => $phase_data['weight'],
                    'progress' => $job['progress'],
                );
            } elseif ( $found_current ) {
                $phases_progress[] = array(
                    'weight'   => $phase_data['weight'],
                    'progress' => 0,
                );
            } else {
                $phases_progress[] = array(
                    'weight'   => $phase_data['weight'],
                    'progress' => 100,
                );
            }
        }

        return $this->plugin->processor->calculate_overall_progress( $phases_progress );
    }

    /**
     * Handle uploaded backup file
     *
     * @param array $file $_FILES array element
     * @return string|WP_Error File path or error
     */
    public function handle_upload( $file ) {
        // Check for upload errors
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return new WP_Error( 'upload_error', $this->get_upload_error_message( $file['error'] ) );
        }

        // Verify file type
        $file_info = wp_check_filetype( $file['name'] );
        if ( 'zip' !== $file_info['ext'] ) {
            return new WP_Error( 'invalid_type', __( 'Please upload a ZIP file.', 'speed-backups' ) );
        }

        // Move to backup directory
        $backup_dir = speed_backups_get_backup_dir();
        $filename = sanitize_file_name( $file['name'] );
        $destination = $backup_dir . '/' . $filename;

        // Handle duplicate filenames
        $counter = 1;
        while ( file_exists( $destination ) ) {
            $filename = pathinfo( $file['name'], PATHINFO_FILENAME ) . '-' . $counter . '.zip';
            $destination = $backup_dir . '/' . $filename;
            $counter++;
        }

        if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) {
            return new WP_Error( 'move_failed', __( 'Failed to save uploaded file.', 'speed-backups' ) );
        }

        return $destination;
    }

    /**
     * Get upload error message
     *
     * @param int $error_code PHP upload error code
     * @return string Error message
     */
    private function get_upload_error_message( $error_code ) {
        switch ( $error_code ) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return __( 'The uploaded file exceeds the maximum file size.', 'speed-backups' );
            case UPLOAD_ERR_PARTIAL:
                return __( 'The file was only partially uploaded.', 'speed-backups' );
            case UPLOAD_ERR_NO_FILE:
                return __( 'No file was uploaded.', 'speed-backups' );
            case UPLOAD_ERR_NO_TMP_DIR:
                return __( 'Missing temporary folder.', 'speed-backups' );
            case UPLOAD_ERR_CANT_WRITE:
                return __( 'Failed to write file to disk.', 'speed-backups' );
            case UPLOAD_ERR_EXTENSION:
                return __( 'A PHP extension stopped the file upload.', 'speed-backups' );
            default:
                return __( 'Unknown upload error.', 'speed-backups' );
        }
    }
}
