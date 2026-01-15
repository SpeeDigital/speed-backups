<?php
/**
 * Debug Logger Class
 *
 * TEMPORARY - FOR DEBUGGING ONLY
 * Remove this entire file and all references to it before production release.
 *
 * @package SpeedBackups
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Debug Logger Class
 *
 * REMOVE BEFORE PRODUCTION
 */
class Speed_Backups_Debug_Logger {

    /**
     * Log file path
     *
     * @var string
     */
    private static $log_file = null;

    /**
     * Whether debug is enabled
     *
     * @var bool
     */
    private static $enabled = true; // Set to false to disable all logging

    /**
     * Get log file path
     *
     * @return string
     */
    private static function get_log_file() {
        if ( null === self::$log_file ) {
            $upload_dir = wp_upload_dir();
            self::$log_file = $upload_dir['basedir'] . '/speed-backups/debug-restore.log';
        }
        return self::$log_file;
    }

    /**
     * Log a debug message
     *
     * @param string $message Message to log
     * @param string $context Context/location of the log
     * @param mixed  $data    Optional data to include
     */
    public static function log( $message, $context = '', $data = null ) {
        if ( ! self::$enabled ) {
            return;
        }

        $log_file = self::get_log_file();

        // Ensure directory exists
        $dir = dirname( $log_file );
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $timestamp = gmdate( 'Y-m-d H:i:s' );
        $memory = size_format( memory_get_usage( true ) );

        $log_entry = "[{$timestamp}] [{$memory}]";

        if ( ! empty( $context ) ) {
            $log_entry .= " [{$context}]";
        }

        $log_entry .= " {$message}";

        if ( null !== $data ) {
            if ( is_array( $data ) || is_object( $data ) ) {
                $log_entry .= "\n    DATA: " . print_r( $data, true );
            } else {
                $log_entry .= "\n    DATA: " . $data;
            }
        }

        $log_entry .= "\n";

        file_put_contents( $log_file, $log_entry, FILE_APPEND | LOCK_EX );
    }

    /**
     * Log the start of a function/method
     *
     * @param string $function Function name
     * @param array  $args     Arguments passed
     */
    public static function log_function_start( $function, $args = array() ) {
        self::log( ">>> ENTER: {$function}", 'FUNCTION', $args ? array_keys( $args ) : null );
    }

    /**
     * Log the end of a function/method
     *
     * @param string $function Function name
     * @param mixed  $result   Return value
     */
    public static function log_function_end( $function, $result = null ) {
        $result_preview = null;
        if ( is_array( $result ) ) {
            $result_preview = array_keys( $result );
        } elseif ( is_bool( $result ) ) {
            $result_preview = $result ? 'true' : 'false';
        } elseif ( is_wp_error( $result ) ) {
            $result_preview = 'WP_Error: ' . $result->get_error_message();
        }
        self::log( "<<< EXIT: {$function}", 'FUNCTION', $result_preview );
    }

    /**
     * Log an error
     *
     * @param string $message Error message
     * @param string $context Context
     * @param mixed  $data    Additional data
     */
    public static function error( $message, $context = '', $data = null ) {
        self::log( "ERROR: {$message}", $context, $data );
    }

    /**
     * Log SQL query
     *
     * @param string $query SQL query (truncated)
     * @param mixed  $result Query result
     */
    public static function log_query( $query, $result = null ) {
        $query_preview = substr( $query, 0, 200 );
        if ( strlen( $query ) > 200 ) {
            $query_preview .= '... [truncated, total: ' . strlen( $query ) . ' chars]';
        }

        $result_info = null;
        if ( false === $result ) {
            global $wpdb;
            $result_info = 'FAILED: ' . $wpdb->last_error;
        } elseif ( is_numeric( $result ) ) {
            $result_info = "Affected rows: {$result}";
        }

        self::log( "SQL: {$query_preview}", 'QUERY', $result_info );
    }

    /**
     * Log job state
     *
     * @param string $job_id Job ID
     * @param array  $job    Job data
     */
    public static function log_job_state( $job_id, $job ) {
        if ( ! $job ) {
            self::log( "Job {$job_id} is NULL/FALSE", 'JOB_STATE' );
            return;
        }

        $state_summary = array(
            'status'   => isset( $job['status'] ) ? $job['status'] : 'N/A',
            'type'     => isset( $job['type'] ) ? $job['type'] : 'N/A',
            'phase'    => isset( $job['state']['phase'] ) ? $job['state']['phase'] : 'N/A',
            'progress' => isset( $job['progress'] ) ? $job['progress'] : 'N/A',
        );

        self::log( "Job {$job_id} state", 'JOB_STATE', $state_summary );
    }

    /**
     * Clear the log file
     */
    public static function clear_log() {
        $log_file = self::get_log_file();
        if ( file_exists( $log_file ) ) {
            unlink( $log_file );
        }
    }

    /**
     * Start a new restore session in the log
     */
    public static function start_restore_session() {
        self::log( str_repeat( '=', 60 ), 'SESSION' );
        self::log( 'NEW RESTORE SESSION STARTED', 'SESSION' );
        self::log( 'WordPress: ' . get_bloginfo( 'version' ), 'SESSION' );
        self::log( 'PHP: ' . PHP_VERSION, 'SESSION' );
        self::log( 'Plugin: ' . SPEED_BACKUPS_VERSION, 'SESSION' );
        self::log( str_repeat( '=', 60 ), 'SESSION' );
    }
}
