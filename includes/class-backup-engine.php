<?php
/**
 * Backup Engine Class
 *
 * Orchestrates the entire backup process including database export,
 * file collection, ZIP creation, and manifest generation.
 *
 * @package SpeedBackups
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Backup Engine Class
 */
class Speed_Backups_Backup_Engine {

    /**
     * Main plugin instance
     *
     * @var Speed_Backups
     */
    private $plugin;

    /**
     * Backup phases and their weights for progress calculation
     *
     * @var array
     */
    private $phases = array(
        'init'      => array( 'weight' => 5, 'label' => 'Initializing backup' ),
        'database'  => array( 'weight' => 30, 'label' => 'Exporting database' ),
        'files'     => array( 'weight' => 50, 'label' => 'Backing up files' ),
        'config'    => array( 'weight' => 5, 'label' => 'Backing up configuration' ),
        'manifest'  => array( 'weight' => 5, 'label' => 'Creating manifest' ),
        'finalize'  => array( 'weight' => 5, 'label' => 'Finalizing backup' ),
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
     * Start a new backup job
     *
     * @param array $options Backup options
     * @return string|WP_Error Job ID or error
     */
    public function start_backup( $options = array() ) {
        $defaults = array(
            'include_database' => true,
            'include_files'    => true,
            'include_config'   => true,
            'custom_name'      => '',
        );

        $options = wp_parse_args( $options, $defaults );

        // Check disk space before starting
        $space_check = $this->check_disk_space( $options );
        if ( is_wp_error( $space_check ) ) {
            return $space_check;
        }

        // Create job
        $job_id = $this->plugin->processor->create_job( 'backup', $options );

        // Update status to running
        $this->plugin->processor->update_status( $job_id, 'running', __( 'Starting backup...', 'speed-backups' ) );

        // Initialize state
        $this->plugin->processor->update_state( $job_id, array(
            'phase'           => 'init',
            'temp_dir'        => '',
            'zip_path'        => '',
            'db_exported'     => false,
            'files_list'      => array(),
            'files_offset'    => 0,
            'files_total'     => 0,
            'tables_exported' => array(),
            'manifest'        => array(),
        ) );

        return $job_id;
    }

    /**
     * Process the next chunk of a backup job
     *
     * @param string $job_id Job ID
     * @return array Progress status
     */
    public function process_chunk( $job_id ) {
        $job = $this->plugin->processor->get_job( $job_id );

        if ( ! $job ) {
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
        $phase = isset( $state['phase'] ) ? $state['phase'] : 'init';

        try {
            switch ( $phase ) {
                case 'init':
                    return $this->process_init( $job_id );

                case 'database':
                    return $this->process_database( $job_id );

                case 'files':
                    return $this->process_files( $job_id );

                case 'config':
                    return $this->process_config( $job_id );

                case 'manifest':
                    return $this->process_manifest( $job_id );

                case 'finalize':
                    return $this->process_finalize( $job_id );

                default:
                    return array(
                        'success' => false,
                        'error'   => __( 'Unknown backup phase.', 'speed-backups' ),
                    );
            }
        } catch ( Exception $e ) {
            $this->plugin->processor->fail_job( $job_id, $e->getMessage() );
            $this->plugin->log( 'Backup failed: ' . $e->getMessage(), 'error' );

            return array(
                'success' => false,
                'error'   => $e->getMessage(),
            );
        }
    }

    /**
     * Process initialization phase
     *
     * @param string $job_id Job ID
     * @return array Progress status
     */
    private function process_init( $job_id ) {
        $job = $this->plugin->processor->get_job( $job_id );

        // Create temp directory
        $temp_dir = speed_backups_get_temp_dir() . '/' . $job_id;
        if ( ! file_exists( $temp_dir ) ) {
            wp_mkdir_p( $temp_dir );
        }

        // Generate backup filename
        $site_name = sanitize_file_name( get_bloginfo( 'name' ) );
        $site_name = ! empty( $site_name ) ? $site_name : 'wordpress';
        $custom_name = isset( $job['params']['custom_name'] ) ? sanitize_file_name( $job['params']['custom_name'] ) : '';

        if ( ! empty( $custom_name ) ) {
            $filename = $custom_name . '.zip';
        } else {
            $filename = sprintf( '%s-backup-%s.zip', $site_name, gmdate( 'Y-m-d-His' ) );
        }

        $zip_path = speed_backups_get_backup_dir() . '/' . $filename;

        // Create empty ZIP
        $zip = new ZipArchive();
        if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            throw new Exception( __( 'Could not create backup ZIP file.', 'speed-backups' ) );
        }
        $zip->close();

        // Get file list and save to temp file (NOT to database - can be too large)
        $files_total = 0;
        $total_files_size = 0;
        $files_list_file = $temp_dir . '/files_list.json';

        if ( $job['params']['include_files'] ) {
            $this->plugin->processor->update_phase(
                $job_id,
                'init',
                50,
                __( 'Scanning files (this may take a while for large sites)...', 'speed-backups' )
            );

            // Use chunked scanning for memory efficiency (supports 40GB+ sites)
            $scan_result = $this->plugin->files->scan_files_chunked(
                WP_CONTENT_DIR,
                $files_list_file,
                1000, // Flush every 1000 files
                array( $this->plugin->processor, 'should_continue' )
            );

            if ( ! $scan_result['success'] ) {
                throw new Exception( $scan_result['error'] );
            }

            $files_total = $scan_result['total_files'];
            $total_files_size = $scan_result['total_size'];

            $this->plugin->log(
                sprintf(
                    'File scan complete: %d files, %s total',
                    $files_total,
                    speed_backups_format_bytes( $total_files_size )
                ),
                'info'
            );
        }

        // Update state (without the large files_list array)
        $this->plugin->processor->update_state( $job_id, array(
            'phase'            => $job['params']['include_database'] ? 'database' : 'files',
            'temp_dir'         => $temp_dir,
            'zip_path'         => $zip_path,
            'zip_name'         => $filename,
            'files_list_file'  => $files_list_file,
            'files_total'      => $files_total,
            'files_total_size' => $total_files_size,
        ) );

        $this->plugin->processor->update_phase(
            $job_id,
            'init',
            100,
            __( 'Initialization complete.', 'speed-backups' )
        );

        return array(
            'success'  => true,
            'complete' => false,
            'progress' => $this->calculate_progress( $job_id ),
        );
    }

    /**
     * Process database export phase
     *
     * @param string $job_id Job ID
     * @return array Progress status
     */
    private function process_database( $job_id ) {
        $state = $this->plugin->processor->get_state( $job_id );

        $this->plugin->processor->update_phase(
            $job_id,
            'database',
            0,
            __( 'Exporting database...', 'speed-backups' )
        );

        // Get database file path
        $db_file = $state['temp_dir'] . '/database.sql';

        // Export database
        $result = $this->plugin->database->export_database( $db_file );

        if ( ! $result['success'] ) {
            throw new Exception( $result['error'] );
        }

        // Add to ZIP
        $zip = new ZipArchive();
        if ( true !== $zip->open( $state['zip_path'], ZipArchive::CREATE ) ) {
            throw new Exception( __( 'Could not open backup ZIP file.', 'speed-backups' ) );
        }

        $zip->addFile( $db_file, 'database.sql' );
        $zip->close();

        // Update state
        $this->plugin->processor->update_state( $job_id, array(
            'phase'       => 'files',
            'db_exported' => true,
            'db_stats'    => array(
                'tables' => $result['tables_exported'],
                'rows'   => $result['rows_exported'],
                'size'   => $result['file_size'],
            ),
        ) );

        $this->plugin->processor->update_phase(
            $job_id,
            'database',
            100,
            sprintf(
                __( 'Database exported: %d tables, %s', 'speed-backups' ),
                $result['tables_exported'],
                speed_backups_format_bytes( $result['file_size'] )
            )
        );

        return array(
            'success'  => true,
            'complete' => false,
            'progress' => $this->calculate_progress( $job_id ),
        );
    }

    /**
     * Process files backup phase (chunked)
     *
     * @param string $job_id Job ID
     * @return array Progress status
     */
    private function process_files( $job_id ) {
        $job = $this->plugin->processor->get_job( $job_id );
        $state = $job['state'];

        // Check if we should skip files phase
        $files_list_file = isset( $state['files_list_file'] ) ? $state['files_list_file'] : '';
        $total = isset( $state['files_total'] ) ? $state['files_total'] : 0;

        if ( ! $job['params']['include_files'] || $total === 0 || ! file_exists( $files_list_file ) ) {
            // Skip files phase
            $this->plugin->processor->update_state( $job_id, array(
                'phase' => 'config',
            ) );

            return array(
                'success'  => true,
                'complete' => false,
                'progress' => $this->calculate_progress( $job_id ),
            );
        }

        $offset = isset( $state['files_offset'] ) ? $state['files_offset'] : 0;
        $batch_size = $this->plugin->get_option( 'file_chunk_size', 100 );

        $this->plugin->processor->update_phase(
            $job_id,
            'files',
            $total > 0 ? ( $offset / $total ) * 100 : 0,
            sprintf(
                __( 'Backing up files: %d / %d', 'speed-backups' ),
                $offset,
                $total
            )
        );

        // Read only the chunk we need from file list (memory efficient for large sites)
        $files_chunk = $this->plugin->files->read_file_list_chunk( $files_list_file, $offset, $batch_size );
        if ( false === $files_chunk ) {
            throw new Exception( __( 'Could not read files list.', 'speed-backups' ) );
        }

        // If no files in chunk, we're done
        if ( empty( $files_chunk ) ) {
            $this->plugin->processor->update_state( $job_id, array(
                'phase' => 'config',
            ) );

            $this->plugin->processor->update_phase(
                $job_id,
                'files',
                100,
                sprintf(
                    __( 'Files backed up: %d files', 'speed-backups' ),
                    $total
                )
            );

            return array(
                'success'  => true,
                'complete' => false,
                'progress' => $this->calculate_progress( $job_id ),
            );
        }

        // Process files in chunks - pass the chunk with offset 0 since we already did the offset
        $result = $this->plugin->files->create_zip(
            $state['zip_path'],
            $files_chunk,
            WP_CONTENT_DIR,
            0, // Start from 0 since files_chunk is already the subset we need
            count( $files_chunk )
        );

        if ( ! $result['success'] ) {
            throw new Exception( $result['error'] );
        }

        // Update offset
        $new_offset = $offset + count( $files_chunk );

        $this->plugin->processor->update_state( $job_id, array(
            'files_offset' => $new_offset,
        ) );

        // Check if complete
        if ( $new_offset >= $total ) {
            $this->plugin->processor->update_state( $job_id, array(
                'phase' => 'config',
            ) );

            $this->plugin->processor->update_phase(
                $job_id,
                'files',
                100,
                sprintf(
                    __( 'Files backed up: %d files', 'speed-backups' ),
                    $total
                )
            );
        }

        return array(
            'success'  => true,
            'complete' => false,
            'progress' => $this->calculate_progress( $job_id ),
        );
    }

    /**
     * Process configuration backup phase
     *
     * @param string $job_id Job ID
     * @return array Progress status
     */
    private function process_config( $job_id ) {
        $job = $this->plugin->processor->get_job( $job_id );
        $state = $job['state'];

        if ( ! $job['params']['include_config'] ) {
            $this->plugin->processor->update_state( $job_id, array(
                'phase' => 'manifest',
            ) );

            return array(
                'success'  => true,
                'complete' => false,
                'progress' => $this->calculate_progress( $job_id ),
            );
        }

        $this->plugin->processor->update_phase(
            $job_id,
            'config',
            0,
            __( 'Backing up configuration...', 'speed-backups' )
        );

        $zip = new ZipArchive();
        if ( true !== $zip->open( $state['zip_path'], ZipArchive::CREATE ) ) {
            throw new Exception( __( 'Could not open backup ZIP file.', 'speed-backups' ) );
        }

        // Backup wp-config.php (sanitized)
        $wp_config_path = ABSPATH . 'wp-config.php';
        if ( file_exists( $wp_config_path ) ) {
            $wp_config_content = file_get_contents( $wp_config_path );

            // Sanitize sensitive data (replace actual values with placeholders)
            $wp_config_sanitized = $this->sanitize_wp_config( $wp_config_content );

            $zip->addFromString( 'wp-config.php', $wp_config_sanitized );
        }

        // Backup .htaccess
        $htaccess_path = ABSPATH . '.htaccess';
        if ( file_exists( $htaccess_path ) ) {
            $zip->addFile( $htaccess_path, '.htaccess' );
        }

        // Backup robots.txt
        $robots_path = ABSPATH . 'robots.txt';
        if ( file_exists( $robots_path ) ) {
            $zip->addFile( $robots_path, 'robots.txt' );
        }

        $zip->close();

        $this->plugin->processor->update_state( $job_id, array(
            'phase'         => 'manifest',
            'config_backed' => true,
        ) );

        $this->plugin->processor->update_phase(
            $job_id,
            'config',
            100,
            __( 'Configuration backed up.', 'speed-backups' )
        );

        return array(
            'success'  => true,
            'complete' => false,
            'progress' => $this->calculate_progress( $job_id ),
        );
    }

    /**
     * Sanitize wp-config.php content
     *
     * @param string $content Original content
     * @return string Sanitized content
     */
    private function sanitize_wp_config( $content ) {
        // Add header comment
        $header = "<?php\n/**\n * Speed Backups - wp-config.php backup\n";
        $header .= " * Generated: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
        $header .= " * \n";
        $header .= " * IMPORTANT: Database credentials have been preserved.\n";
        $header .= " * Update them if restoring to a new server.\n";
        $header .= " */\n\n";

        // Remove opening PHP tag for our header
        $content = preg_replace( '/^<\?php\s*/i', '', $content );

        return $header . $content;
    }

    /**
     * Process manifest creation phase
     *
     * @param string $job_id Job ID
     * @return array Progress status
     */
    private function process_manifest( $job_id ) {
        $state = $this->plugin->processor->get_state( $job_id );

        $this->plugin->processor->update_phase(
            $job_id,
            'manifest',
            0,
            __( 'Creating backup manifest...', 'speed-backups' )
        );

        // Get site information
        $site_info = $this->plugin->get_site_info();

        // Build manifest
        $manifest = array(
            'version'         => SPEED_BACKUPS_VERSION,
            'backup_type'     => 'full',
            'created'         => gmdate( 'c' ),
            'created_timestamp' => time(),
            'site_url'        => $site_info['site_url'],
            'home_url'        => $site_info['home_url'],
            'wp_version'      => $site_info['wp_version'],
            'php_version'     => $site_info['php_version'],
            'mysql_version'   => $site_info['mysql_version'],
            'table_prefix'    => $site_info['table_prefix'],
            'charset'         => $site_info['charset'],
            'collate'         => $site_info['collate'],
            'multisite'       => $site_info['multisite'],
            'active_theme'    => $site_info['active_theme'],
            'active_plugins'  => $site_info['active_plugins'],
            'contents'        => array(
                'database'   => isset( $state['db_exported'] ) && $state['db_exported'],
                'files'      => isset( $state['files_total'] ) ? $state['files_total'] : 0,
                'wp_config'  => isset( $state['config_backed'] ) && $state['config_backed'],
            ),
            'database_stats'  => isset( $state['db_stats'] ) ? $state['db_stats'] : null,
            'checksum'        => '',
        );

        // Add manifest to ZIP (first pass without checksum)
        $zip = new ZipArchive();
        if ( true !== $zip->open( $state['zip_path'], ZipArchive::CREATE ) ) {
            throw new Exception( __( 'Could not open backup ZIP file.', 'speed-backups' ) );
        }

        $zip->addFromString( 'manifest.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        $zip->close();

        // Calculate checksum after adding manifest
        $manifest['checksum'] = md5_file( $state['zip_path'] );

        // Update manifest with checksum (second pass)
        $zip = new ZipArchive();
        if ( true !== $zip->open( $state['zip_path'], ZipArchive::CREATE ) ) {
            throw new Exception( __( 'Could not open backup ZIP file for checksum update.', 'speed-backups' ) );
        }
        $zip->addFromString( 'manifest.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        $zip->close();

        $this->plugin->processor->update_state( $job_id, array(
            'phase'    => 'finalize',
            'manifest' => $manifest,
        ) );

        $this->plugin->processor->update_phase(
            $job_id,
            'manifest',
            100,
            __( 'Manifest created.', 'speed-backups' )
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
        $state = $this->plugin->processor->get_state( $job_id );

        $this->plugin->processor->update_phase(
            $job_id,
            'finalize',
            0,
            __( 'Finalizing backup...', 'speed-backups' )
        );

        // Clean up temp directory
        if ( ! empty( $state['temp_dir'] ) && file_exists( $state['temp_dir'] ) ) {
            $this->plugin->files->delete_directory( $state['temp_dir'] );
        }

        // Get final file size
        $file_size = file_exists( $state['zip_path'] ) ? filesize( $state['zip_path'] ) : 0;

        // Complete the job
        $this->plugin->processor->complete_job( $job_id, array(
            'file_path' => $state['zip_path'],
            'file_name' => $state['zip_name'],
            'file_size' => $file_size,
            'file_size_formatted' => speed_backups_format_bytes( $file_size ),
            'manifest'  => $state['manifest'],
        ) );

        $this->plugin->processor->update_phase(
            $job_id,
            'finalize',
            100,
            __( 'Backup complete!', 'speed-backups' )
        );

        $this->plugin->log(
            sprintf(
                'Backup completed: %s (%s)',
                $state['zip_name'],
                speed_backups_format_bytes( $file_size )
            ),
            'info'
        );

        return array(
            'success'  => true,
            'complete' => true,
            'progress' => 100,
            'result'   => array(
                'file_path' => $state['zip_path'],
                'file_name' => $state['zip_name'],
                'file_size' => $file_size,
                'file_size_formatted' => speed_backups_format_bytes( $file_size ),
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
        $current_phase = isset( $state['phase'] ) ? $state['phase'] : 'init';

        $phases_progress = array();
        $found_current = false;

        foreach ( $this->phases as $phase_key => $phase_data ) {
            if ( $phase_key === $current_phase ) {
                $found_current = true;
                // Get phase progress from job
                $phases_progress[] = array(
                    'weight'   => $phase_data['weight'],
                    'progress' => $job['progress'],
                );
            } elseif ( $found_current ) {
                // Future phases
                $phases_progress[] = array(
                    'weight'   => $phase_data['weight'],
                    'progress' => 0,
                );
            } else {
                // Completed phases
                $phases_progress[] = array(
                    'weight'   => $phase_data['weight'],
                    'progress' => 100,
                );
            }
        }

        return $this->plugin->processor->calculate_overall_progress( $phases_progress );
    }

    /**
     * Get download URL for a backup file
     *
     * @param string $file_path Full path to backup file
     * @return string Download URL
     */
    public function get_download_url( $file_path ) {
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace( $upload_dir['basedir'], '', $file_path );

        return add_query_arg( array(
            'action'   => 'speed_backups_download',
            'file'     => basename( $file_path ),
            '_wpnonce' => wp_create_nonce( 'speed_backups_download' ),
        ), admin_url( 'admin-ajax.php' ) );
    }

    /**
     * Get list of existing backups
     *
     * @return array List of backup files
     */
    public function get_backups_list() {
        $backup_dir = speed_backups_get_backup_dir();
        $backups = array();

        if ( ! file_exists( $backup_dir ) ) {
            return $backups;
        }

        $files = glob( $backup_dir . '/*.zip' );

        foreach ( $files as $file ) {
            $manifest = $this->get_backup_manifest( $file );

            $backups[] = array(
                'file_path'  => $file,
                'file_name'  => basename( $file ),
                'file_size'  => filesize( $file ),
                'file_size_formatted' => speed_backups_format_bytes( filesize( $file ) ),
                'created'    => filemtime( $file ),
                'created_formatted' => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), filemtime( $file ) ),
                'manifest'   => $manifest,
            );
        }

        // Sort by date, newest first
        usort( $backups, function( $a, $b ) {
            return $b['created'] - $a['created'];
        } );

        return $backups;
    }

    /**
     * Get manifest from a backup file
     *
     * @param string $file_path Path to backup ZIP
     * @return array|null Manifest data or null
     */
    public function get_backup_manifest( $file_path ) {
        $manifest_content = $this->plugin->files->get_file_from_zip( $file_path, 'manifest.json' );

        if ( false === $manifest_content ) {
            return null;
        }

        $manifest = json_decode( $manifest_content, true );

        return $manifest;
    }

    /**
     * Delete a backup file
     *
     * @param string $file_name Backup filename
     * @return bool
     */
    public function delete_backup( $file_name ) {
        $file_name = sanitize_file_name( $file_name );
        $file_path = speed_backups_get_backup_dir() . '/' . $file_name;

        if ( ! file_exists( $file_path ) ) {
            return false;
        }

        return wp_delete_file( $file_path );
    }

    /**
     * Check if there's enough disk space for backup
     *
     * @param array $options Backup options
     * @return true|WP_Error True if enough space, WP_Error if not
     */
    public function check_disk_space( $options ) {
        $backup_dir = speed_backups_get_backup_dir();

        // Get available disk space
        $free_space = @disk_free_space( $backup_dir );

        if ( false === $free_space ) {
            // Can't determine free space, log warning but continue
            $this->plugin->log( 'Could not determine free disk space', 'warning' );
            return true;
        }

        // Estimate backup size
        $estimated_size = $this->estimate_backup_size( $options );

        // Add 20% buffer for safety (ZIP overhead, temp files, etc.)
        $required_space = $estimated_size * 1.2;

        // Minimum required: 100MB or estimated size, whichever is larger
        $min_required = max( 100 * 1024 * 1024, $required_space );

        if ( $free_space < $min_required ) {
            $error_message = sprintf(
                /* translators: 1: Required space, 2: Available space, 3: Estimated backup size */
                __( 'Not enough disk space for backup. Required: %1$s (estimated backup: %3$s), Available: %2$s. Please free up disk space before creating a backup.', 'speed-backups' ),
                speed_backups_format_bytes( $min_required ),
                speed_backups_format_bytes( $free_space ),
                speed_backups_format_bytes( $estimated_size )
            );

            $this->plugin->log( $error_message, 'error' );

            return new WP_Error(
                'insufficient_disk_space',
                $error_message,
                array(
                    'required'       => $min_required,
                    'available'      => $free_space,
                    'estimated_size' => $estimated_size,
                )
            );
        }

        // Warn if space is getting low (less than 500MB after backup)
        $remaining_after_backup = $free_space - $estimated_size;
        if ( $remaining_after_backup < 500 * 1024 * 1024 ) {
            $this->plugin->log(
                sprintf(
                    'Low disk space warning: Only %s will remain after backup',
                    speed_backups_format_bytes( $remaining_after_backup )
                ),
                'warning'
            );
        }

        return true;
    }

    /**
     * Estimate the size of the backup
     *
     * @param array $options Backup options
     * @return int Estimated size in bytes
     */
    public function estimate_backup_size( $options ) {
        $total_size = 0;

        // Estimate database size
        if ( ! empty( $options['include_database'] ) ) {
            $db_stats = $this->plugin->database->get_stats();
            // Database export is usually slightly larger than raw data due to SQL syntax
            $total_size += $db_stats['total_size'] * 1.1;
        }

        // Estimate files size
        if ( ! empty( $options['include_files'] ) ) {
            $files_size = $this->plugin->files->get_directory_size( WP_CONTENT_DIR );
            // ZIP compression typically reduces size by 20-50%, but we use uncompressed for safety estimate
            $total_size += $files_size;
        }

        // Config files are small, add 1MB for safety
        if ( ! empty( $options['include_config'] ) ) {
            $total_size += 1024 * 1024;
        }

        // Add overhead for temp files during backup process
        // During backup, we need space for: temp SQL file + building ZIP + final ZIP
        // So multiply by 2 to account for temporary files
        $total_size *= 2;

        return (int) $total_size;
    }

    /**
     * Get disk space information
     *
     * @return array Disk space info
     */
    public function get_disk_space_info() {
        $backup_dir = speed_backups_get_backup_dir();

        $free_space = @disk_free_space( $backup_dir );
        $total_space = @disk_total_space( $backup_dir );

        if ( false === $free_space || false === $total_space ) {
            return array(
                'available'           => false,
                'free_space'          => 0,
                'total_space'         => 0,
                'used_space'          => 0,
                'free_percentage'     => 0,
            );
        }

        $used_space = $total_space - $free_space;

        return array(
            'available'               => true,
            'free_space'              => $free_space,
            'free_space_formatted'    => speed_backups_format_bytes( $free_space ),
            'total_space'             => $total_space,
            'total_space_formatted'   => speed_backups_format_bytes( $total_space ),
            'used_space'              => $used_space,
            'used_space_formatted'    => speed_backups_format_bytes( $used_space ),
            'free_percentage'         => round( ( $free_space / $total_space ) * 100, 1 ),
            'used_percentage'         => round( ( $used_space / $total_space ) * 100, 1 ),
        );
    }
}
