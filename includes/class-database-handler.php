<?php
/**
 * Database Handler Class
 *
 * Handles database export and import operations with chunked processing
 * for handling large databases efficiently.
 *
 * @package SpeedBackups
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Database Handler Class
 */
class Speed_Backups_Database_Handler {

    /**
     * Number of rows to process per batch
     *
     * @var int
     */
    private $batch_size = 1000;

    /**
     * Database connection
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
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
     * Get all tables to backup
     *
     * @param bool $wp_only Only get tables with WordPress prefix
     * @return array List of table names
     */
    public function get_tables( $wp_only = true ) {
        $tables = array();

        if ( $wp_only ) {
            // Get only tables with WordPress prefix
            $sql = $this->wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $this->wpdb->esc_like( $this->wpdb->prefix ) . '%'
            );
        } else {
            $sql = "SHOW TABLES";
        }

        $results = $this->wpdb->get_results( $sql, ARRAY_N );

        foreach ( $results as $row ) {
            $tables[] = $row[0];
        }

        return $tables;
    }

    /**
     * Get table structure (CREATE TABLE statement)
     *
     * @param string $table Table name
     * @return string CREATE TABLE statement
     */
    public function get_table_structure( $table ) {
        $row = $this->wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );

        if ( $row ) {
            $create_statement = $row[1];
            // Add IF NOT EXISTS
            $create_statement = str_replace( 'CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $create_statement );
            return $create_statement . ";\n\n";
        }

        return '';
    }

    /**
     * Get row count for a table
     *
     * @param string $table Table name
     * @return int Row count
     */
    public function get_row_count( $table ) {
        return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
    }

    /**
     * Export table data in batches
     *
     * @param string   $table   Table name
     * @param resource $handle  File handle to write to
     * @param int      $offset  Starting offset
     * @param int      $limit   Number of rows to export (0 = all remaining)
     * @return array Result with 'exported' count and 'complete' boolean
     */
    public function export_table_data( $table, $handle, $offset = 0, $limit = 0 ) {
        $exported = 0;
        $batch_size = $limit > 0 ? min( $limit, $this->batch_size ) : $this->batch_size;
        $total_to_export = $limit > 0 ? $limit : PHP_INT_MAX;

        // Get column information
        $columns = $this->wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
        $column_names = array();
        $column_types = array();

        foreach ( $columns as $column ) {
            $column_names[] = $column['Field'];
            $column_types[ $column['Field'] ] = $column['Type'];
        }

        $column_list = '`' . implode( '`, `', $column_names ) . '`';

        while ( $exported < $total_to_export ) {
            $current_batch_size = min( $batch_size, $total_to_export - $exported );

            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM `{$table}` LIMIT %d OFFSET %d",
                    $current_batch_size,
                    $offset
                ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            // Build extended INSERT statement for efficiency
            $values_array = array();

            foreach ( $rows as $row ) {
                $values = array();
                foreach ( $column_names as $col ) {
                    $value = isset( $row[ $col ] ) ? $row[ $col ] : null;
                    $values[] = $this->escape_value( $value, $column_types[ $col ] );
                }
                $values_array[] = '(' . implode( ', ', $values ) . ')';

                // Write in chunks to avoid memory issues
                if ( count( $values_array ) >= 100 ) {
                    $insert_sql = "INSERT INTO `{$table}` ({$column_list}) VALUES\n" . implode( ",\n", $values_array ) . ";\n";
                    fwrite( $handle, $insert_sql );
                    $values_array = array();
                }
            }

            // Write remaining values
            if ( ! empty( $values_array ) ) {
                $insert_sql = "INSERT INTO `{$table}` ({$column_list}) VALUES\n" . implode( ",\n", $values_array ) . ";\n";
                fwrite( $handle, $insert_sql );
            }

            $exported += count( $rows );
            $offset += count( $rows );

            // If we got fewer rows than requested, we've reached the end
            if ( count( $rows ) < $current_batch_size ) {
                break;
            }
        }

        $total_rows = $this->get_row_count( $table );

        return array(
            'exported' => $exported,
            'offset'   => $offset,
            'complete' => $offset >= $total_rows,
            'total'    => $total_rows,
        );
    }

    /**
     * Escape a value for SQL insertion
     *
     * @param mixed  $value Value to escape
     * @param string $type  Column type
     * @return string Escaped value
     */
    private function escape_value( $value, $type ) {
        if ( null === $value ) {
            return 'NULL';
        }

        // Check for binary/blob types
        $binary_types = array( 'blob', 'binary', 'varbinary', 'tinyblob', 'mediumblob', 'longblob' );
        foreach ( $binary_types as $binary_type ) {
            if ( stripos( $type, $binary_type ) !== false ) {
                return '0x' . bin2hex( $value );
            }
        }

        // Check for numeric types that don't need quoting
        $numeric_types = array( 'int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'float', 'double', 'decimal' );
        foreach ( $numeric_types as $numeric_type ) {
            if ( stripos( $type, $numeric_type ) !== false ) {
                if ( is_numeric( $value ) ) {
                    return $value;
                }
            }
        }

        // Default: escape as string
        return "'" . $this->wpdb->_real_escape( $value ) . "'";
    }

    /**
     * Export full database to file
     *
     * @param string $file_path Path to output file
     * @param array  $tables    Tables to export (empty = all)
     * @return array Result with success status and details
     */
    public function export_database( $file_path, $tables = array() ) {
        if ( empty( $tables ) ) {
            $tables = $this->get_tables();
        }

        $handle = fopen( $file_path, 'w' );

        if ( ! $handle ) {
            return array(
                'success' => false,
                'error'   => __( 'Could not create database export file.', 'speed-backups' ),
            );
        }

        // Write header
        $this->write_header( $handle );

        $exported_tables = 0;
        $total_rows = 0;

        foreach ( $tables as $table ) {
            // Write table header comment
            fwrite( $handle, "\n-- --------------------------------------------------------\n" );
            fwrite( $handle, "-- Table: `{$table}`\n" );
            fwrite( $handle, "-- --------------------------------------------------------\n\n" );

            // Drop existing table
            fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n\n" );

            // Write table structure
            $structure = $this->get_table_structure( $table );
            fwrite( $handle, $structure );

            // Write table data
            $result = $this->export_table_data( $table, $handle );
            $total_rows += $result['exported'];

            fwrite( $handle, "\n" );

            $exported_tables++;
        }

        // Write footer
        $this->write_footer( $handle );

        fclose( $handle );

        return array(
            'success'         => true,
            'tables_exported' => $exported_tables,
            'rows_exported'   => $total_rows,
            'file_path'       => $file_path,
            'file_size'       => filesize( $file_path ),
        );
    }

    /**
     * Write SQL file header
     *
     * @param resource $handle File handle
     */
    private function write_header( $handle ) {
        $header = "-- Speed Backups Database Export\n";
        $header .= "-- Version: " . SPEED_BACKUPS_VERSION . "\n";
        $header .= "-- https://github.com/SpeeDigital/speed-backups\n";
        $header .= "--\n";
        $header .= "-- Host: " . DB_HOST . "\n";
        $header .= "-- Generation Time: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
        $header .= "-- Server version: " . $this->wpdb->db_version() . "\n";
        $header .= "-- PHP Version: " . PHP_VERSION . "\n";
        $header .= "-- WordPress Version: " . get_bloginfo( 'version' ) . "\n";
        $header .= "-- Site URL: " . get_site_url() . "\n";
        $header .= "-- Table Prefix: " . $this->wpdb->prefix . "\n";
        $header .= "--\n\n";

        $header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $header .= "SET time_zone = \"+00:00\";\n";
        $header .= "SET NAMES utf8mb4;\n";
        $header .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        $header .= "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
        $header .= "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
        $header .= "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
        $header .= "/*!40101 SET NAMES utf8mb4 */;\n\n";

        fwrite( $handle, $header );
    }

    /**
     * Write SQL file footer
     *
     * @param resource $handle File handle
     */
    private function write_footer( $handle ) {
        $footer = "\n\nSET FOREIGN_KEY_CHECKS = 1;\n";
        $footer .= "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n";
        $footer .= "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n";
        $footer .= "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";
        $footer .= "\n-- End of Speed Backups Database Export\n";

        fwrite( $handle, $footer );
    }

    /**
     * Import database from SQL file
     *
     * @param string $file_path Path to SQL file
     * @param string $old_prefix Old table prefix (for replacement)
     * @param string $new_prefix New table prefix
     * @return array Result with success status and details
     */
    public function import_database( $file_path, $old_prefix = '', $new_prefix = '' ) {
        if ( ! file_exists( $file_path ) ) {
            return array(
                'success' => false,
                'error'   => __( 'Database file not found.', 'speed-backups' ),
            );
        }

        $handle = fopen( $file_path, 'r' );

        if ( ! $handle ) {
            return array(
                'success' => false,
                'error'   => __( 'Could not open database file.', 'speed-backups' ),
            );
        }

        // Use new prefix if not specified
        if ( empty( $new_prefix ) ) {
            $new_prefix = $this->wpdb->prefix;
        }

        $query = '';
        $queries_executed = 0;
        $errors = array();
        $delimiter = ';';
        $in_string = false;
        $string_char = '';

        while ( ! feof( $handle ) ) {
            $line = fgets( $handle );

            // Skip empty lines and comments
            $trimmed = trim( $line );
            if ( empty( $trimmed ) || strpos( $trimmed, '--' ) === 0 || strpos( $trimmed, '#' ) === 0 ) {
                continue;
            }

            // Skip MySQL comments
            if ( strpos( $trimmed, '/*!' ) === 0 && strpos( $trimmed, '*/' ) !== false ) {
                // Execute MySQL specific commands
                $query = $trimmed;
            } else {
                $query .= $line;
            }

            // Check if query is complete (ends with delimiter outside of string)
            if ( $this->is_query_complete( $query, $delimiter ) ) {
                $query = trim( $query );

                // Remove trailing delimiter
                if ( substr( $query, -1 ) === $delimiter ) {
                    $query = substr( $query, 0, -1 );
                }

                // Replace table prefix if needed
                if ( ! empty( $old_prefix ) && $old_prefix !== $new_prefix ) {
                    $query = $this->replace_table_prefix( $query, $old_prefix, $new_prefix );
                }

                // Execute query
                if ( ! empty( $query ) ) {
                    $result = $this->wpdb->query( $query );

                    if ( false === $result ) {
                        $errors[] = array(
                            'query' => substr( $query, 0, 200 ) . '...',
                            'error' => $this->wpdb->last_error,
                        );
                    } else {
                        $queries_executed++;
                    }
                }

                $query = '';
            }
        }

        fclose( $handle );

        return array(
            'success'          => empty( $errors ),
            'queries_executed' => $queries_executed,
            'errors'           => $errors,
        );
    }

    /**
     * Check if a SQL query is complete
     *
     * @param string $query     The query to check
     * @param string $delimiter The delimiter to look for
     * @return bool
     */
    private function is_query_complete( $query, $delimiter ) {
        $query = trim( $query );

        if ( empty( $query ) ) {
            return false;
        }

        // Simple check: ends with delimiter
        if ( substr( $query, -strlen( $delimiter ) ) !== $delimiter ) {
            return false;
        }

        // Check if delimiter is inside a string
        $in_string = false;
        $string_char = '';
        $escaped = false;

        for ( $i = 0, $len = strlen( $query ); $i < $len; $i++ ) {
            $char = $query[ $i ];

            if ( $escaped ) {
                $escaped = false;
                continue;
            }

            if ( '\\' === $char ) {
                $escaped = true;
                continue;
            }

            if ( ! $in_string && ( "'" === $char || '"' === $char ) ) {
                $in_string = true;
                $string_char = $char;
            } elseif ( $in_string && $char === $string_char ) {
                $in_string = false;
                $string_char = '';
            }
        }

        return ! $in_string;
    }

    /**
     * Replace table prefix in query
     *
     * @param string $query      SQL query
     * @param string $old_prefix Old prefix
     * @param string $new_prefix New prefix
     * @return string Modified query
     */
    private function replace_table_prefix( $query, $old_prefix, $new_prefix ) {
        // Replace in table names
        $patterns = array(
            "/`{$old_prefix}/",
            "/'{$old_prefix}/",
            "/\"{$old_prefix}/",
            "/ {$old_prefix}([a-zA-Z_]+)/",
        );

        $replacements = array(
            "`{$new_prefix}",
            "'{$new_prefix}",
            "\"{$new_prefix}",
            " {$new_prefix}$1",
        );

        return preg_replace( $patterns, $replacements, $query );
    }

    /**
     * Search and replace in database (for URL changes)
     *
     * @param string $search  String to search for
     * @param string $replace String to replace with
     * @param array  $tables  Tables to process (empty = all)
     * @return array Result with changes made
     */
    public function search_replace( $search, $replace, $tables = array() ) {
        if ( empty( $tables ) ) {
            $tables = $this->get_tables();
        }

        $total_changes = 0;
        $table_changes = array();

        foreach ( $tables as $table ) {
            $changes = $this->search_replace_table( $table, $search, $replace );
            $total_changes += $changes;
            $table_changes[ $table ] = $changes;
        }

        return array(
            'success'       => true,
            'total_changes' => $total_changes,
            'table_changes' => $table_changes,
        );
    }

    /**
     * Search and replace in a single table
     *
     * @param string $table   Table name
     * @param string $search  String to search for
     * @param string $replace String to replace with
     * @return int Number of changes made
     */
    private function search_replace_table( $table, $search, $replace ) {
        $changes = 0;

        // Get primary key
        $primary_key = $this->get_primary_key( $table );

        if ( ! $primary_key ) {
            return 0;
        }

        // Get text columns
        $columns = $this->get_text_columns( $table );

        if ( empty( $columns ) ) {
            return 0;
        }

        // Process in batches
        $offset = 0;

        while ( true ) {
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM `{$table}` LIMIT %d OFFSET %d",
                    $this->batch_size,
                    $offset
                ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $update_data = array();
                $pk_value = $row[ $primary_key ];

                foreach ( $columns as $column ) {
                    if ( ! isset( $row[ $column ] ) || empty( $row[ $column ] ) ) {
                        continue;
                    }

                    $value = $row[ $column ];
                    $new_value = $this->recursive_unserialize_replace( $search, $replace, $value );

                    if ( $new_value !== $value ) {
                        $update_data[ $column ] = $new_value;
                    }
                }

                if ( ! empty( $update_data ) ) {
                    $result = $this->wpdb->update(
                        $table,
                        $update_data,
                        array( $primary_key => $pk_value )
                    );

                    if ( false !== $result ) {
                        $changes += count( $update_data );
                    }
                }
            }

            $offset += $this->batch_size;
        }

        return $changes;
    }

    /**
     * Get primary key for a table
     *
     * @param string $table Table name
     * @return string|null Primary key column name
     */
    private function get_primary_key( $table ) {
        $columns = $this->wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );

        foreach ( $columns as $column ) {
            if ( 'PRI' === $column['Key'] ) {
                return $column['Field'];
            }
        }

        return null;
    }

    /**
     * Get text-based columns for a table
     *
     * @param string $table Table name
     * @return array Column names
     */
    private function get_text_columns( $table ) {
        $columns = $this->wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
        $text_columns = array();

        $text_types = array( 'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext' );

        foreach ( $columns as $column ) {
            foreach ( $text_types as $type ) {
                if ( stripos( $column['Type'], $type ) !== false ) {
                    $text_columns[] = $column['Field'];
                    break;
                }
            }
        }

        return $text_columns;
    }

    /**
     * Recursively search and replace, handling serialized data
     *
     * @param string $search  String to search for
     * @param string $replace String to replace with
     * @param mixed  $data    Data to process
     * @return mixed Processed data
     */
    private function recursive_unserialize_replace( $search, $replace, $data ) {
        // Handle serialized data
        if ( is_string( $data ) && $this->is_serialized( $data ) ) {
            $unserialized = @unserialize( $data );

            if ( false !== $unserialized ) {
                $unserialized = $this->recursive_unserialize_replace( $search, $replace, $unserialized );
                return serialize( $unserialized );
            }
        }

        // Handle arrays
        if ( is_array( $data ) ) {
            foreach ( $data as $key => $value ) {
                $data[ $key ] = $this->recursive_unserialize_replace( $search, $replace, $value );
            }
            return $data;
        }

        // Handle objects
        if ( is_object( $data ) ) {
            foreach ( $data as $key => $value ) {
                $data->$key = $this->recursive_unserialize_replace( $search, $replace, $value );
            }
            return $data;
        }

        // Handle strings
        if ( is_string( $data ) ) {
            return str_replace( $search, $replace, $data );
        }

        return $data;
    }

    /**
     * Check if a string is serialized
     *
     * @param string $data Data to check
     * @return bool
     */
    private function is_serialized( $data ) {
        if ( ! is_string( $data ) ) {
            return false;
        }

        $data = trim( $data );

        if ( 'N;' === $data ) {
            return true;
        }

        if ( strlen( $data ) < 4 ) {
            return false;
        }

        if ( ':' !== $data[1] ) {
            return false;
        }

        $lastc = substr( $data, -1 );

        if ( ';' !== $lastc && '}' !== $lastc ) {
            return false;
        }

        $token = $data[0];

        switch ( $token ) {
            case 's':
                if ( '"' !== substr( $data, -2, 1 ) ) {
                    return false;
                }
                // Fall through
            case 'a':
            case 'O':
                return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
            case 'b':
            case 'i':
            case 'd':
                return (bool) preg_match( "/^{$token}:[0-9.E+-]+;$/", $data );
        }

        return false;
    }

    /**
     * Get database statistics
     *
     * @return array Database stats
     */
    public function get_stats() {
        $tables = $this->get_tables();
        $total_size = 0;
        $total_rows = 0;
        $table_stats = array();

        foreach ( $tables as $table ) {
            $row_count = $this->get_row_count( $table );
            $status = $this->wpdb->get_row( "SHOW TABLE STATUS LIKE '{$table}'", ARRAY_A );

            $size = 0;
            if ( $status ) {
                $size = $status['Data_length'] + $status['Index_length'];
            }

            $table_stats[ $table ] = array(
                'rows' => $row_count,
                'size' => $size,
                'size_formatted' => speed_backups_format_bytes( $size ),
            );

            $total_rows += $row_count;
            $total_size += $size;
        }

        return array(
            'tables'          => count( $tables ),
            'total_rows'      => $total_rows,
            'total_size'      => $total_size,
            'total_size_formatted' => speed_backups_format_bytes( $total_size ),
            'table_stats'     => $table_stats,
        );
    }
}
