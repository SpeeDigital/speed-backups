<?php
/**
 * Chunked Processor Class
 *
 * Handles chunked processing of large operations to avoid timeouts.
 * Manages job state persistence and progress tracking.
 *
 * @package SpeedBackups
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Chunked Processor Class
 */
class Speed_Backups_Chunked_Processor {

    /**
     * Transient prefix for job data
     *
     * @var string
     */
    const JOB_TRANSIENT_PREFIX = 'speed_backups_job_';

    /**
     * Job expiration time in seconds (24 hours)
     *
     * @var int
     */
    const JOB_EXPIRATION = 86400;

    /**
     * Maximum execution time per chunk (seconds)
     *
     * @var int
     */
    private $max_execution_time = 25;

    /**
     * Start time for current chunk
     *
     * @var float
     */
    private $start_time;

    /**
     * Constructor
     */
    public function __construct() {
        // Get max execution time from options
        $options = get_option( 'speed_backups_options', array() );
        if ( isset( $options['max_execution_time'] ) ) {
            $this->max_execution_time = (int) $options['max_execution_time'];
        }
    }

    /**
     * Create a new job
     *
     * @param string $type    Job type (backup, restore)
     * @param array  $params  Job parameters
     * @return string Job ID
     */
    public function create_job( $type, $params = array() ) {
        $job_id = $this->generate_job_id();

        $job_data = array(
            'id'          => $job_id,
            'type'        => $type,
            'status'      => 'pending',
            'phase'       => '',
            'progress'    => 0,
            'message'     => '',
            'params'      => $params,
            'state'       => array(),
            'errors'      => array(),
            'created_at'  => time(),
            'updated_at'  => time(),
            'started_at'  => null,
            'completed_at' => null,
        );

        $this->save_job( $job_id, $job_data );

        return $job_id;
    }

    /**
     * Generate unique job ID
     *
     * @return string
     */
    private function generate_job_id() {
        return wp_generate_uuid4();
    }

    /**
     * Get job data
     *
     * @param string $job_id Job ID
     * @return array|false Job data or false if not found
     */
    public function get_job( $job_id ) {
        $job_data = get_transient( self::JOB_TRANSIENT_PREFIX . $job_id );

        if ( false === $job_data ) {
            // Try to load from options for longer persistence
            $job_data = get_option( self::JOB_TRANSIENT_PREFIX . $job_id );
        }

        return $job_data;
    }

    /**
     * Save job data
     *
     * @param string $job_id   Job ID
     * @param array  $job_data Job data
     * @return bool
     */
    public function save_job( $job_id, $job_data ) {
        $job_data['updated_at'] = time();

        // Save to transient for quick access
        set_transient( self::JOB_TRANSIENT_PREFIX . $job_id, $job_data, self::JOB_EXPIRATION );

        // Also save to options for persistence
        update_option( self::JOB_TRANSIENT_PREFIX . $job_id, $job_data, false );

        return true;
    }

    /**
     * Update job status
     *
     * @param string $job_id  Job ID
     * @param string $status  New status
     * @param string $message Status message
     * @return bool
     */
    public function update_status( $job_id, $status, $message = '' ) {
        $job = $this->get_job( $job_id );

        if ( ! $job ) {
            return false;
        }

        $job['status'] = $status;

        if ( ! empty( $message ) ) {
            $job['message'] = $message;
        }

        if ( 'running' === $status && ! $job['started_at'] ) {
            $job['started_at'] = time();
        }

        if ( in_array( $status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
            $job['completed_at'] = time();
        }

        return $this->save_job( $job_id, $job );
    }

    /**
     * Update job phase
     *
     * @param string $job_id   Job ID
     * @param string $phase    Current phase
     * @param int    $progress Progress percentage (0-100)
     * @param string $message  Status message
     * @return bool
     */
    public function update_phase( $job_id, $phase, $progress = 0, $message = '' ) {
        $job = $this->get_job( $job_id );

        if ( ! $job ) {
            return false;
        }

        $job['phase'] = $phase;
        $job['progress'] = min( 100, max( 0, $progress ) );

        if ( ! empty( $message ) ) {
            $job['message'] = $message;
        }

        return $this->save_job( $job_id, $job );
    }

    /**
     * Update job state (for resumable operations)
     *
     * @param string $job_id Job ID
     * @param array  $state  State data
     * @return bool
     */
    public function update_state( $job_id, $state ) {
        $job = $this->get_job( $job_id );

        if ( ! $job ) {
            return false;
        }

        $job['state'] = array_merge( $job['state'], $state );

        return $this->save_job( $job_id, $job );
    }

    /**
     * Get job state
     *
     * @param string $job_id Job ID
     * @param string $key    State key (optional)
     * @param mixed  $default Default value
     * @return mixed
     */
    public function get_state( $job_id, $key = null, $default = null ) {
        $job = $this->get_job( $job_id );

        if ( ! $job ) {
            return $default;
        }

        if ( null === $key ) {
            return $job['state'];
        }

        return isset( $job['state'][ $key ] ) ? $job['state'][ $key ] : $default;
    }

    /**
     * Add error to job
     *
     * @param string $job_id  Job ID
     * @param string $error   Error message
     * @param string $context Error context
     * @return bool
     */
    public function add_error( $job_id, $error, $context = '' ) {
        $job = $this->get_job( $job_id );

        if ( ! $job ) {
            return false;
        }

        $job['errors'][] = array(
            'message' => $error,
            'context' => $context,
            'time'    => time(),
        );

        return $this->save_job( $job_id, $job );
    }

    /**
     * Complete a job
     *
     * @param string $job_id Job ID
     * @param array  $result Job result data
     * @return bool
     */
    public function complete_job( $job_id, $result = array() ) {
        $job = $this->get_job( $job_id );

        if ( ! $job ) {
            return false;
        }

        $job['status'] = 'completed';
        $job['progress'] = 100;
        $job['completed_at'] = time();
        $job['result'] = $result;

        return $this->save_job( $job_id, $job );
    }

    /**
     * Fail a job
     *
     * @param string $job_id Job ID
     * @param string $error  Error message
     * @return bool
     */
    public function fail_job( $job_id, $error ) {
        $job = $this->get_job( $job_id );

        if ( ! $job ) {
            return false;
        }

        $job['status'] = 'failed';
        $job['completed_at'] = time();
        $job['message'] = $error;

        $this->add_error( $job_id, $error, 'job_failure' );

        return $this->save_job( $job_id, $job );
    }

    /**
     * Cancel a job
     *
     * @param string $job_id Job ID
     * @return bool
     */
    public function cancel_job( $job_id ) {
        $job = $this->get_job( $job_id );

        if ( ! $job ) {
            return false;
        }

        $job['status'] = 'cancelled';
        $job['completed_at'] = time();
        $job['message'] = __( 'Job cancelled by user.', 'speed-backups' );

        return $this->save_job( $job_id, $job );
    }

    /**
     * Delete a job
     *
     * @param string $job_id Job ID
     * @return bool
     */
    public function delete_job( $job_id ) {
        delete_transient( self::JOB_TRANSIENT_PREFIX . $job_id );
        delete_option( self::JOB_TRANSIENT_PREFIX . $job_id );
        return true;
    }

    /**
     * Get active job of a specific type
     *
     * @param string $type Job type
     * @return array|false Job data or false
     */
    public function get_active_job( $type ) {
        global $wpdb;

        // Search for active jobs in options
        $option_name = self::JOB_TRANSIENT_PREFIX . '%';

        $jobs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $option_name
            ),
            ARRAY_A
        );

        foreach ( $jobs as $job_row ) {
            $job = maybe_unserialize( $job_row['option_value'] );

            if ( is_array( $job ) &&
                 isset( $job['type'] ) && $job['type'] === $type &&
                 isset( $job['status'] ) && in_array( $job['status'], array( 'pending', 'running' ), true ) ) {
                return $job;
            }
        }

        return false;
    }

    /**
     * Clean up old completed/failed jobs
     *
     * @param int $max_age Maximum age in seconds
     * @return int Number of jobs cleaned
     */
    public function cleanup_old_jobs( $max_age = 86400 ) {
        global $wpdb;

        $cleaned = 0;
        $option_name = self::JOB_TRANSIENT_PREFIX . '%';

        $jobs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $option_name
            ),
            ARRAY_A
        );

        foreach ( $jobs as $job_row ) {
            $job = maybe_unserialize( $job_row['option_value'] );

            if ( is_array( $job ) && isset( $job['completed_at'] ) && $job['completed_at'] ) {
                if ( time() - $job['completed_at'] > $max_age ) {
                    delete_option( $job_row['option_name'] );
                    $job_id = str_replace( self::JOB_TRANSIENT_PREFIX, '', $job_row['option_name'] );
                    delete_transient( self::JOB_TRANSIENT_PREFIX . $job_id );
                    $cleaned++;
                }
            }
        }

        return $cleaned;
    }

    /**
     * Start timing for chunk execution
     */
    public function start_timer() {
        $this->start_time = microtime( true );
    }

    /**
     * Check if we should continue processing or stop for this chunk
     *
     * @return bool True if we should continue, false if we should stop
     */
    public function should_continue() {
        if ( ! $this->start_time ) {
            $this->start_timer();
        }

        $elapsed = microtime( true ) - $this->start_time;

        // Leave 5 seconds buffer
        return $elapsed < ( $this->max_execution_time - 5 );
    }

    /**
     * Get remaining time for current chunk
     *
     * @return float Remaining seconds
     */
    public function get_remaining_time() {
        if ( ! $this->start_time ) {
            return $this->max_execution_time;
        }

        $elapsed = microtime( true ) - $this->start_time;
        return max( 0, $this->max_execution_time - $elapsed );
    }

    /**
     * Get elapsed time for current chunk
     *
     * @return float Elapsed seconds
     */
    public function get_elapsed_time() {
        if ( ! $this->start_time ) {
            return 0;
        }

        return microtime( true ) - $this->start_time;
    }

    /**
     * Set max execution time
     *
     * @param int $seconds Max seconds
     */
    public function set_max_execution_time( $seconds ) {
        $this->max_execution_time = (int) $seconds;
    }

    /**
     * Get job progress summary
     *
     * @param string $job_id Job ID
     * @return array Progress summary
     */
    public function get_progress_summary( $job_id ) {
        $job = $this->get_job( $job_id );

        if ( ! $job ) {
            return array(
                'status'   => 'not_found',
                'progress' => 0,
                'message'  => __( 'Job not found.', 'speed-backups' ),
            );
        }

        $duration = null;
        if ( $job['started_at'] ) {
            $end_time = $job['completed_at'] ? $job['completed_at'] : time();
            $duration = $end_time - $job['started_at'];
        }

        return array(
            'id'        => $job['id'],
            'type'      => $job['type'],
            'status'    => $job['status'],
            'phase'     => $job['phase'],
            'progress'  => $job['progress'],
            'message'   => $job['message'],
            'errors'    => $job['errors'],
            'duration'  => $duration,
            'result'    => isset( $job['result'] ) ? $job['result'] : null,
        );
    }

    /**
     * Calculate overall progress from multiple phases
     *
     * @param array $phases Array of phase weights and progress
     * @return int Overall progress (0-100)
     */
    public function calculate_overall_progress( $phases ) {
        $total_weight = 0;
        $weighted_progress = 0;

        foreach ( $phases as $phase ) {
            $weight = isset( $phase['weight'] ) ? $phase['weight'] : 1;
            $progress = isset( $phase['progress'] ) ? $phase['progress'] : 0;

            $total_weight += $weight;
            $weighted_progress += ( $progress * $weight );
        }

        if ( $total_weight === 0 ) {
            return 0;
        }

        return (int) round( $weighted_progress / $total_weight );
    }
}
