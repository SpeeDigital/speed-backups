<?php
/**
 * File Handler Class
 *
 * Handles file system operations including ZIP creation, extraction,
 * directory scanning, and file copying with chunked processing.
 *
 * @package SpeedBackups
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * File Handler Class
 */
class Speed_Backups_File_Handler {

    /**
     * Files to process per batch
     *
     * @var int
     */
    private $batch_size = 100;

    /**
     * Buffer size for file operations (1MB)
     *
     * @var int
     */
    private $buffer_size = 1048576;

    /**
     * Patterns to exclude from backup
     *
     * @var array
     */
    private $exclude_patterns = array();

    /**
     * Constructor
     */
    public function __construct() {
        $options = get_option( 'speed_backups_options', array() );
        $this->exclude_patterns = isset( $options['exclude_patterns'] ) ? $options['exclude_patterns'] : array();
    }

    /**
     * Set batch size
     *
     * @param int $size Batch size
     */
    public function set_batch_size( $size ) {
        $this->batch_size = absint( $size );
    }

    /**
     * Set exclude patterns
     *
     * @param array $patterns Patterns to exclude
     */
    public function set_exclude_patterns( $patterns ) {
        $this->exclude_patterns = $patterns;
    }

    /**
     * Get list of files to backup
     *
     * @param string $base_path Base path to scan
     * @return array List of files with relative paths
     */
    public function get_file_list( $base_path ) {
        $files = array();

        if ( ! file_exists( $base_path ) || ! is_readable( $base_path ) ) {
            return $files;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $base_path, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ( $iterator as $file ) {
                $absolute_path = $file->getPathname();
                $relative_path = str_replace( ABSPATH, '', $absolute_path );

                // Check if file should be excluded
                if ( $this->should_exclude( $relative_path ) ) {
                    continue;
                }

                // Skip our own backup directory
                if ( strpos( $relative_path, 'wp-content/uploads/speed-backups' ) !== false ) {
                    continue;
                }

                if ( $file->isFile() && $file->isReadable() ) {
                    $files[] = array(
                        'absolute' => $absolute_path,
                        'relative' => $relative_path,
                        'size'     => $file->getSize(),
                    );
                }
            }
        } catch ( Exception $e ) {
            error_log( 'Speed Backups: Error scanning directory - ' . $e->getMessage() );
        }

        return $files;
    }

    /**
     * Check if a path should be excluded
     *
     * @param string $path Relative path to check
     * @return bool
     */
    public function should_exclude( $path ) {
        // Normalize path separators
        $path = str_replace( '\\', '/', $path );

        foreach ( $this->exclude_patterns as $pattern ) {
            $pattern = str_replace( '\\', '/', $pattern );

            if ( fnmatch( $pattern, $path ) ) {
                return true;
            }

            // Also check against basename
            if ( fnmatch( $pattern, basename( $path ) ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a ZIP archive from a file list
     *
     * @param string $zip_path   Path for the ZIP file
     * @param array  $files      Array of files to add
     * @param string $base_path  Base path for relative paths in ZIP
     * @param int    $offset     Starting offset
     * @param int    $limit      Number of files to add (0 = all)
     * @return array Result with status and progress
     */
    public function create_zip( $zip_path, $files, $base_path = ABSPATH, $offset = 0, $limit = 0 ) {
        $zip = new ZipArchive();

        // Open or create the ZIP file (always use CREATE to handle empty archives)
        $result = $zip->open( $zip_path, ZipArchive::CREATE );

        if ( true !== $result ) {
            return array(
                'success' => false,
                'error'   => $this->get_zip_error( $result ),
            );
        }

        $added = 0;
        $skipped = 0;
        $limit = $limit > 0 ? $limit : count( $files );

        for ( $i = $offset; $i < min( $offset + $limit, count( $files ) ); $i++ ) {
            $file = $files[ $i ];

            if ( ! file_exists( $file['absolute'] ) || ! is_readable( $file['absolute'] ) ) {
                $skipped++;
                continue;
            }

            // Handle large files
            if ( $file['size'] > $this->buffer_size * 10 ) {
                // For very large files, add them directly
                $zip->addFile( $file['absolute'], $file['relative'] );
            } else {
                // For normal files, add from string to avoid file handles
                $contents = file_get_contents( $file['absolute'] );
                if ( false !== $contents ) {
                    $zip->addFromString( $file['relative'], $contents );
                } else {
                    $skipped++;
                    continue;
                }
            }

            $added++;
        }

        $zip->close();

        $new_offset = $offset + $limit;
        $complete = $new_offset >= count( $files );

        return array(
            'success'  => true,
            'added'    => $added,
            'skipped'  => $skipped,
            'offset'   => $new_offset,
            'total'    => count( $files ),
            'complete' => $complete,
            'progress' => $complete ? 100 : round( ( $new_offset / count( $files ) ) * 100, 2 ),
        );
    }

    /**
     * Add a single file to an existing ZIP
     *
     * @param string $zip_path     Path to ZIP file
     * @param string $file_path    Path to file to add
     * @param string $archive_path Path within the ZIP
     * @return bool
     */
    public function add_to_zip( $zip_path, $file_path, $archive_path ) {
        $zip = new ZipArchive();

        if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
            return false;
        }

        if ( is_dir( $file_path ) ) {
            $zip->addEmptyDir( $archive_path );
        } else {
            $zip->addFile( $file_path, $archive_path );
        }

        $zip->close();

        return true;
    }

    /**
     * Add string content to ZIP as a file
     *
     * @param string $zip_path     Path to ZIP file
     * @param string $content      Content to add
     * @param string $archive_path Path within the ZIP
     * @return bool
     */
    public function add_content_to_zip( $zip_path, $content, $archive_path ) {
        $zip = new ZipArchive();

        if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
            return false;
        }

        $zip->addFromString( $archive_path, $content );
        $zip->close();

        return true;
    }

    /**
     * Extract ZIP archive
     *
     * @param string $zip_path     Path to ZIP file
     * @param string $extract_path Path to extract to
     * @return array Result with status
     */
    public function extract_zip( $zip_path, $extract_path ) {
        if ( ! file_exists( $zip_path ) ) {
            return array(
                'success' => false,
                'error'   => __( 'ZIP file not found.', 'speed-backups' ),
            );
        }

        $zip = new ZipArchive();
        $result = $zip->open( $zip_path );

        if ( true !== $result ) {
            return array(
                'success' => false,
                'error'   => $this->get_zip_error( $result ),
            );
        }

        // Create extraction directory
        if ( ! file_exists( $extract_path ) ) {
            wp_mkdir_p( $extract_path );
        }

        // Extract all files
        $extracted = $zip->extractTo( $extract_path );

        $file_count = $zip->numFiles;
        $zip->close();

        if ( ! $extracted ) {
            return array(
                'success' => false,
                'error'   => __( 'Failed to extract ZIP file.', 'speed-backups' ),
            );
        }

        return array(
            'success'    => true,
            'files'      => $file_count,
            'extract_path' => $extract_path,
        );
    }

    /**
     * Extract ZIP archive in chunks
     *
     * @param string $zip_path     Path to ZIP file
     * @param string $extract_path Path to extract to
     * @param int    $offset       Starting file index
     * @param int    $limit        Number of files to extract
     * @return array Result with status and progress
     */
    public function extract_zip_chunked( $zip_path, $extract_path, $offset = 0, $limit = 100 ) {
        if ( ! file_exists( $zip_path ) ) {
            return array(
                'success' => false,
                'error'   => __( 'ZIP file not found.', 'speed-backups' ),
            );
        }

        $zip = new ZipArchive();
        $result = $zip->open( $zip_path );

        if ( true !== $result ) {
            return array(
                'success' => false,
                'error'   => $this->get_zip_error( $result ),
            );
        }

        // Create extraction directory
        if ( ! file_exists( $extract_path ) ) {
            wp_mkdir_p( $extract_path );
        }

        $total_files = $zip->numFiles;
        $extracted = 0;

        for ( $i = $offset; $i < min( $offset + $limit, $total_files ); $i++ ) {
            $filename = $zip->getNameIndex( $i );

            if ( false === $filename ) {
                continue;
            }

            // Get file stats
            $stat = $zip->statIndex( $i );

            // Create directory structure
            $full_path = $extract_path . '/' . $filename;
            $dir_path = dirname( $full_path );

            if ( ! file_exists( $dir_path ) ) {
                wp_mkdir_p( $dir_path );
            }

            // Extract file (skip directories, they're created above)
            if ( substr( $filename, -1 ) !== '/' ) {
                $content = $zip->getFromIndex( $i );
                if ( false !== $content ) {
                    file_put_contents( $full_path, $content );
                    $extracted++;
                }
            }
        }

        $zip->close();

        $new_offset = $offset + $limit;
        $complete = $new_offset >= $total_files;

        return array(
            'success'   => true,
            'extracted' => $extracted,
            'offset'    => $new_offset,
            'total'     => $total_files,
            'complete'  => $complete,
            'progress'  => $complete ? 100 : round( ( $new_offset / $total_files ) * 100, 2 ),
        );
    }

    /**
     * Get ZIP file contents (list of files)
     *
     * @param string $zip_path Path to ZIP file
     * @return array|WP_Error List of files or error
     */
    public function get_zip_contents( $zip_path ) {
        if ( ! file_exists( $zip_path ) ) {
            return new WP_Error( 'file_not_found', __( 'ZIP file not found.', 'speed-backups' ) );
        }

        $zip = new ZipArchive();
        $result = $zip->open( $zip_path );

        if ( true !== $result ) {
            return new WP_Error( 'zip_error', $this->get_zip_error( $result ) );
        }

        $files = array();
        $total_size = 0;

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $stat = $zip->statIndex( $i );
            $files[] = array(
                'name' => $stat['name'],
                'size' => $stat['size'],
                'compressed_size' => $stat['comp_size'],
            );
            $total_size += $stat['size'];
        }

        $zip->close();

        return array(
            'files'      => $files,
            'file_count' => count( $files ),
            'total_size' => $total_size,
            'total_size_formatted' => speed_backups_format_bytes( $total_size ),
        );
    }

    /**
     * Get a specific file from ZIP
     *
     * @param string $zip_path  Path to ZIP file
     * @param string $file_name File name within ZIP
     * @return string|false File contents or false
     */
    public function get_file_from_zip( $zip_path, $file_name ) {
        if ( ! file_exists( $zip_path ) ) {
            return false;
        }

        $zip = new ZipArchive();

        if ( true !== $zip->open( $zip_path ) ) {
            return false;
        }

        $content = $zip->getFromName( $file_name );
        $zip->close();

        return $content;
    }

    /**
     * Copy directory recursively
     *
     * @param string $source      Source directory
     * @param string $destination Destination directory
     * @param array  $exclude     Patterns to exclude
     * @return array Result with status
     */
    public function copy_directory( $source, $destination, $exclude = array() ) {
        if ( ! file_exists( $source ) ) {
            return array(
                'success' => false,
                'error'   => __( 'Source directory not found.', 'speed-backups' ),
            );
        }

        if ( ! file_exists( $destination ) ) {
            wp_mkdir_p( $destination );
        }

        $copied = 0;
        $errors = array();

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $source, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ( $iterator as $item ) {
                $relative_path = str_replace( $source, '', $item->getPathname() );
                $dest_path = $destination . $relative_path;

                // Check exclusions
                $skip = false;
                foreach ( $exclude as $pattern ) {
                    if ( fnmatch( $pattern, $relative_path ) || fnmatch( $pattern, basename( $relative_path ) ) ) {
                        $skip = true;
                        break;
                    }
                }

                if ( $skip ) {
                    continue;
                }

                if ( $item->isDir() ) {
                    if ( ! file_exists( $dest_path ) ) {
                        wp_mkdir_p( $dest_path );
                    }
                } else {
                    // Create parent directory if needed
                    $parent_dir = dirname( $dest_path );
                    if ( ! file_exists( $parent_dir ) ) {
                        wp_mkdir_p( $parent_dir );
                    }

                    if ( copy( $item->getPathname(), $dest_path ) ) {
                        $copied++;
                    } else {
                        $errors[] = $item->getPathname();
                    }
                }
            }
        } catch ( Exception $e ) {
            return array(
                'success' => false,
                'error'   => $e->getMessage(),
            );
        }

        return array(
            'success' => empty( $errors ),
            'copied'  => $copied,
            'errors'  => $errors,
        );
    }

    /**
     * Delete directory recursively
     *
     * @param string $dir Directory to delete
     * @return bool
     */
    public function delete_directory( $dir ) {
        return speed_backups_delete_directory( $dir );
    }

    /**
     * Get directory size
     *
     * @param string $path Directory path
     * @return int Size in bytes
     */
    public function get_directory_size( $path ) {
        $size = 0;

        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            return $size;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ( $iterator as $file ) {
                if ( $file->isFile() && $file->isReadable() ) {
                    $relative_path = str_replace( ABSPATH, '', $file->getPathname() );

                    if ( ! $this->should_exclude( $relative_path ) ) {
                        $size += $file->getSize();
                    }
                }
            }
        } catch ( Exception $e ) {
            error_log( 'Speed Backups: Error calculating directory size - ' . $e->getMessage() );
        }

        return $size;
    }

    /**
     * Get file count in directory
     *
     * @param string $path Directory path
     * @return int File count
     */
    public function get_file_count( $path ) {
        $count = 0;

        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            return $count;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ( $iterator as $file ) {
                if ( $file->isFile() ) {
                    $relative_path = str_replace( ABSPATH, '', $file->getPathname() );

                    if ( ! $this->should_exclude( $relative_path ) ) {
                        $count++;
                    }
                }
            }
        } catch ( Exception $e ) {
            error_log( 'Speed Backups: Error counting files - ' . $e->getMessage() );
        }

        return $count;
    }

    /**
     * Get human readable ZIP error message
     *
     * @param int $code ZipArchive error code
     * @return string Error message
     */
    private function get_zip_error( $code ) {
        switch ( $code ) {
            case ZipArchive::ER_EXISTS:
                return __( 'File already exists.', 'speed-backups' );
            case ZipArchive::ER_INCONS:
                return __( 'ZIP archive is inconsistent.', 'speed-backups' );
            case ZipArchive::ER_INVAL:
                return __( 'Invalid argument.', 'speed-backups' );
            case ZipArchive::ER_MEMORY:
                return __( 'Memory allocation failure.', 'speed-backups' );
            case ZipArchive::ER_NOENT:
                return __( 'No such file.', 'speed-backups' );
            case ZipArchive::ER_NOZIP:
                return __( 'Not a ZIP archive.', 'speed-backups' );
            case ZipArchive::ER_OPEN:
                return __( 'Cannot open file.', 'speed-backups' );
            case ZipArchive::ER_READ:
                return __( 'Read error.', 'speed-backups' );
            case ZipArchive::ER_SEEK:
                return __( 'Seek error.', 'speed-backups' );
            default:
                return sprintf( __( 'Unknown ZIP error (code: %d).', 'speed-backups' ), $code );
        }
    }

    /**
     * Create a safe filename
     *
     * @param string $filename Original filename
     * @return string Safe filename
     */
    public function sanitize_filename( $filename ) {
        $filename = sanitize_file_name( $filename );
        $filename = preg_replace( '/[^a-zA-Z0-9_\-\.]/', '', $filename );
        return $filename;
    }

    /**
     * Ensure directory exists and is writable
     *
     * @param string $dir Directory path
     * @return bool
     */
    public function ensure_directory( $dir ) {
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        return file_exists( $dir ) && is_writable( $dir );
    }
}
