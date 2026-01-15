<?php
/**
 * Admin Page Template
 *
 * @package SpeedBackups
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap sb-wrap">
    <h1 class="sb-page-title">
        <span class="sb-logo">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2L2 7L12 12L22 7L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M2 17L12 22L22 17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M2 12L12 17L22 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </span>
        <?php esc_html_e( 'Speed Backups', 'speed-backups' ); ?>
    </h1>

    <div class="sb-container">
        <!-- Main Content -->
        <div class="sb-main">
            <!-- Create Backup Section -->
            <div class="sb-card sb-card-primary" id="sb-backup-section">
                <div class="sb-card-header">
                    <h2><?php esc_html_e( 'Create Backup', 'speed-backups' ); ?></h2>
                </div>
                <div class="sb-card-body">
                    <p class="sb-description">
                        <?php esc_html_e( 'Create a complete backup of your WordPress site including database, files, themes, plugins, and uploads.', 'speed-backups' ); ?>
                    </p>

                    <!-- Backup Options -->
                    <div class="sb-options">
                        <label class="sb-checkbox">
                            <input type="checkbox" id="sb-include-database" checked>
                            <span><?php esc_html_e( 'Include Database', 'speed-backups' ); ?></span>
                            <small><?php echo esc_html( $db_stats['total_size_formatted'] ); ?></small>
                        </label>
                        <label class="sb-checkbox">
                            <input type="checkbox" id="sb-include-files" checked>
                            <span><?php esc_html_e( 'Include Files (wp-content)', 'speed-backups' ); ?></span>
                            <small><?php echo esc_html( $site_info['files_size_formatted'] ); ?></small>
                        </label>
                        <label class="sb-checkbox">
                            <input type="checkbox" id="sb-include-config" checked>
                            <span><?php esc_html_e( 'Include Configuration (wp-config.php, .htaccess)', 'speed-backups' ); ?></span>
                        </label>
                    </div>

                    <!-- Progress Bar -->
                    <div class="sb-progress-wrapper" id="sb-backup-progress" style="display: none;">
                        <div class="sb-progress-bar">
                            <div class="sb-progress-fill" id="sb-backup-progress-fill"></div>
                        </div>
                        <div class="sb-progress-info">
                            <span class="sb-progress-text" id="sb-backup-progress-text"><?php esc_html_e( 'Initializing...', 'speed-backups' ); ?></span>
                            <span class="sb-progress-percent" id="sb-backup-progress-percent">0%</span>
                        </div>
                    </div>

                    <!-- Backup Result -->
                    <div class="sb-result" id="sb-backup-result" style="display: none;">
                        <div class="sb-result-success">
                            <span class="sb-result-icon">&#10003;</span>
                            <div class="sb-result-content">
                                <strong><?php esc_html_e( 'Backup Complete!', 'speed-backups' ); ?></strong>
                                <p id="sb-backup-result-details"></p>
                            </div>
                        </div>
                        <a href="#" class="button button-primary" id="sb-download-backup">
                            <?php esc_html_e( 'Download Backup', 'speed-backups' ); ?>
                        </a>
                    </div>

                    <!-- Action Buttons -->
                    <div class="sb-actions">
                        <button type="button" class="button button-primary button-hero" id="sb-start-backup">
                            <?php esc_html_e( 'Create Full Backup', 'speed-backups' ); ?>
                        </button>
                        <button type="button" class="button button-secondary" id="sb-cancel-backup" style="display: none;">
                            <?php esc_html_e( 'Cancel', 'speed-backups' ); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Restore Section -->
            <div class="sb-card" id="sb-restore-section">
                <div class="sb-card-header">
                    <h2><?php esc_html_e( 'Restore from Backup', 'speed-backups' ); ?></h2>
                </div>
                <div class="sb-card-body">
                    <p class="sb-description">
                        <?php esc_html_e( 'Upload a backup file or select from existing backups to restore your site.', 'speed-backups' ); ?>
                    </p>

                    <!-- Upload Area -->
                    <div class="sb-upload-area" id="sb-upload-area">
                        <div class="sb-upload-content">
                            <span class="sb-upload-icon">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M21 15V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <polyline points="17,8 12,3 7,8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <line x1="12" y1="3" x2="12" y2="15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </span>
                            <p><?php esc_html_e( 'Drag and drop a backup file here, or click to select', 'speed-backups' ); ?></p>
                            <small><?php printf( esc_html__( 'Maximum upload size: %s', 'speed-backups' ), esc_html( $site_info['max_upload_size_formatted'] ) ); ?></small>
                        </div>
                        <input type="file" id="sb-upload-input" accept=".zip" style="display: none;">
                    </div>

                    <!-- Upload Progress -->
                    <div class="sb-upload-progress" id="sb-upload-progress" style="display: none;">
                        <div class="sb-progress-bar">
                            <div class="sb-progress-fill" id="sb-upload-progress-fill"></div>
                        </div>
                        <span class="sb-progress-text" id="sb-upload-progress-text"><?php esc_html_e( 'Uploading...', 'speed-backups' ); ?></span>
                    </div>

                    <!-- Backup Validation -->
                    <div class="sb-validation" id="sb-validation" style="display: none;">
                        <div class="sb-validation-header">
                            <strong><?php esc_html_e( 'Backup Details', 'speed-backups' ); ?></strong>
                        </div>
                        <div class="sb-validation-content">
                            <table class="sb-info-table">
                                <tr>
                                    <th><?php esc_html_e( 'Created', 'speed-backups' ); ?></th>
                                    <td id="sb-val-created"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Site URL', 'speed-backups' ); ?></th>
                                    <td id="sb-val-site-url"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'WordPress Version', 'speed-backups' ); ?></th>
                                    <td id="sb-val-wp-version"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Contents', 'speed-backups' ); ?></th>
                                    <td id="sb-val-contents"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Total Size', 'speed-backups' ); ?></th>
                                    <td id="sb-val-size"></td>
                                </tr>
                            </table>

                            <!-- Warnings -->
                            <div class="sb-warnings" id="sb-val-warnings" style="display: none;">
                                <strong><?php esc_html_e( 'Warnings', 'speed-backups' ); ?></strong>
                                <ul id="sb-val-warnings-list"></ul>
                            </div>

                            <!-- Restore Options -->
                            <div class="sb-restore-options">
                                <label class="sb-checkbox">
                                    <input type="checkbox" id="sb-restore-database" checked>
                                    <span><?php esc_html_e( 'Restore Database', 'speed-backups' ); ?></span>
                                </label>
                                <label class="sb-checkbox">
                                    <input type="checkbox" id="sb-restore-files" checked>
                                    <span><?php esc_html_e( 'Restore Files', 'speed-backups' ); ?></span>
                                </label>
                                <label class="sb-checkbox">
                                    <input type="checkbox" id="sb-replace-urls" checked>
                                    <span><?php esc_html_e( 'Update URLs (recommended)', 'speed-backups' ); ?></span>
                                </label>
                            </div>
                        </div>

                        <input type="hidden" id="sb-restore-file-path" value="">

                        <div class="sb-validation-actions">
                            <button type="button" class="button button-primary" id="sb-start-restore">
                                <?php esc_html_e( 'Start Restore', 'speed-backups' ); ?>
                            </button>
                            <button type="button" class="button button-secondary" id="sb-cancel-validation">
                                <?php esc_html_e( 'Cancel', 'speed-backups' ); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Restore Progress -->
                    <div class="sb-progress-wrapper" id="sb-restore-progress" style="display: none;">
                        <div class="sb-progress-bar">
                            <div class="sb-progress-fill" id="sb-restore-progress-fill"></div>
                        </div>
                        <div class="sb-progress-info">
                            <span class="sb-progress-text" id="sb-restore-progress-text"><?php esc_html_e( 'Initializing...', 'speed-backups' ); ?></span>
                            <span class="sb-progress-percent" id="sb-restore-progress-percent">0%</span>
                        </div>
                        <button type="button" class="button button-secondary" id="sb-cancel-restore">
                            <?php esc_html_e( 'Cancel', 'speed-backups' ); ?>
                        </button>
                    </div>

                    <!-- Restore Result -->
                    <div class="sb-result" id="sb-restore-result" style="display: none;">
                        <div class="sb-result-success">
                            <span class="sb-result-icon">&#10003;</span>
                            <div class="sb-result-content">
                                <strong><?php esc_html_e( 'Restore Complete!', 'speed-backups' ); ?></strong>
                                <p><?php esc_html_e( 'Your site has been restored successfully. The page will reload in a few seconds.', 'speed-backups' ); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Existing Backups Section -->
            <div class="sb-card" id="sb-backups-section">
                <div class="sb-card-header">
                    <h2><?php esc_html_e( 'Existing Backups', 'speed-backups' ); ?></h2>
                    <button type="button" class="button button-secondary" id="sb-refresh-backups">
                        <?php esc_html_e( 'Refresh', 'speed-backups' ); ?>
                    </button>
                </div>
                <div class="sb-card-body">
                    <?php if ( empty( $backups ) ) : ?>
                        <p class="sb-no-backups"><?php esc_html_e( 'No backups found. Create your first backup above.', 'speed-backups' ); ?></p>
                    <?php else : ?>
                        <table class="sb-backups-table widefat">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Backup', 'speed-backups' ); ?></th>
                                    <th><?php esc_html_e( 'Size', 'speed-backups' ); ?></th>
                                    <th><?php esc_html_e( 'Date', 'speed-backups' ); ?></th>
                                    <th><?php esc_html_e( 'Actions', 'speed-backups' ); ?></th>
                                </tr>
                            </thead>
                            <tbody id="sb-backups-list">
                                <?php foreach ( $backups as $backup ) : ?>
                                    <tr data-file="<?php echo esc_attr( $backup['file_name'] ); ?>">
                                        <td>
                                            <strong><?php echo esc_html( $backup['file_name'] ); ?></strong>
                                            <?php if ( $backup['manifest'] ) : ?>
                                                <br>
                                                <small>
                                                    <?php
                                                    $contents = array();
                                                    if ( ! empty( $backup['manifest']['contents']['database'] ) ) {
                                                        $contents[] = __( 'Database', 'speed-backups' );
                                                    }
                                                    if ( ! empty( $backup['manifest']['contents']['files'] ) ) {
                                                        $contents[] = sprintf( __( '%d files', 'speed-backups' ), $backup['manifest']['contents']['files'] );
                                                    }
                                                    echo esc_html( implode( ', ', $contents ) );
                                                    ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html( $backup['file_size_formatted'] ); ?></td>
                                        <td><?php echo esc_html( $backup['created_formatted'] ); ?></td>
                                        <td>
                                            <button type="button" class="button button-small sb-restore-backup" data-file="<?php echo esc_attr( $backup['file_path'] ); ?>">
                                                <?php esc_html_e( 'Restore', 'speed-backups' ); ?>
                                            </button>
                                            <a href="<?php echo esc_url( add_query_arg( array(
                                                'action'   => 'speed_backups_download',
                                                'file'     => $backup['file_name'],
                                                '_wpnonce' => wp_create_nonce( 'speed_backups_download' ),
                                            ), admin_url( 'admin-ajax.php' ) ) ); ?>" class="button button-small">
                                                <?php esc_html_e( 'Download', 'speed-backups' ); ?>
                                            </a>
                                            <button type="button" class="button button-small sb-delete-backup" data-file="<?php echo esc_attr( $backup['file_name'] ); ?>">
                                                <?php esc_html_e( 'Delete', 'speed-backups' ); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="sb-sidebar">
            <!-- Site Info -->
            <div class="sb-card sb-card-info">
                <div class="sb-card-header">
                    <h3><?php esc_html_e( 'Site Information', 'speed-backups' ); ?></h3>
                </div>
                <div class="sb-card-body">
                    <table class="sb-info-table">
                        <tr>
                            <th><?php esc_html_e( 'WordPress', 'speed-backups' ); ?></th>
                            <td><?php echo esc_html( $site_info['wp_version'] ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'PHP', 'speed-backups' ); ?></th>
                            <td><?php echo esc_html( $site_info['php_version'] ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'MySQL', 'speed-backups' ); ?></th>
                            <td><?php echo esc_html( $site_info['mysql_version'] ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Database', 'speed-backups' ); ?></th>
                            <td>
                                <?php echo esc_html( $db_stats['total_size_formatted'] ); ?>
                                <br><small><?php printf( esc_html__( '%d tables', 'speed-backups' ), $db_stats['tables'] ); ?></small>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Files', 'speed-backups' ); ?></th>
                            <td><?php echo esc_html( $site_info['files_size_formatted'] ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Theme', 'speed-backups' ); ?></th>
                            <td><?php echo esc_html( $site_info['active_theme'] ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Plugins', 'speed-backups' ); ?></th>
                            <td><?php printf( esc_html__( '%d active', 'speed-backups' ), count( $site_info['active_plugins'] ) ); ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Quick Tips -->
            <div class="sb-card">
                <div class="sb-card-header">
                    <h3><?php esc_html_e( 'Tips', 'speed-backups' ); ?></h3>
                </div>
                <div class="sb-card-body">
                    <ul class="sb-tips">
                        <li><?php esc_html_e( 'Create backups before major updates or changes.', 'speed-backups' ); ?></li>
                        <li><?php esc_html_e( 'Download backups to your computer for safekeeping.', 'speed-backups' ); ?></li>
                        <li><?php esc_html_e( 'Test restores on a staging site when possible.', 'speed-backups' ); ?></li>
                        <li><?php esc_html_e( 'Keep multiple backup copies in different locations.', 'speed-backups' ); ?></li>
                    </ul>
                </div>
            </div>

            <!-- DEBUG: Debug Log Section - REMOVE BEFORE PRODUCTION -->
            <div class="sb-card" id="sb-debug-section" style="border-color: #dc3545;">
                <div class="sb-card-header" style="background: #dc3545; color: #fff;">
                    <h3><?php esc_html_e( '🐛 Debug Log (Development Only)', 'speed-backups' ); ?></h3>
                </div>
                <div class="sb-card-body">
                    <p style="color: #dc3545; font-weight: bold;">
                        <?php esc_html_e( 'This section is for debugging only. Remove before production release!', 'speed-backups' ); ?>
                    </p>
                    <div class="sb-debug-actions" style="margin-bottom: 15px;">
                        <button type="button" class="button button-primary" id="sb-view-debug-log">
                            <?php esc_html_e( 'View Log', 'speed-backups' ); ?>
                        </button>
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-ajax.php?action=speed_backups_download_debug_log' ), 'speed_backups_download_log' ) ); ?>" class="button button-secondary" id="sb-download-debug-log">
                            <?php esc_html_e( 'Download Log', 'speed-backups' ); ?>
                        </a>
                        <button type="button" class="button button-secondary" id="sb-clear-debug-log" style="color: #dc3545;">
                            <?php esc_html_e( 'Clear Log', 'speed-backups' ); ?>
                        </button>
                        <span id="sb-debug-log-size" style="margin-left: 10px; color: #666;"></span>
                    </div>
                    <div id="sb-debug-log-content" style="display: none;">
                        <textarea readonly style="width: 100%; height: 400px; font-family: monospace; font-size: 12px; background: #1e1e1e; color: #d4d4d4; padding: 10px;" id="sb-debug-log-textarea"></textarea>
                    </div>
                </div>
            </div>
            <!-- END DEBUG SECTION -->
        </div>
    </div>

    <!-- Footer -->
    <div class="sb-footer">
        <p>
            <?php printf(
                esc_html__( 'Speed Backups v%s', 'speed-backups' ),
                esc_html( SPEED_BACKUPS_VERSION )
            ); ?>
            &nbsp;|&nbsp;
            <a href="https://github.com/SpeeDigital/speed-backups" target="_blank"><?php esc_html_e( 'Documentation', 'speed-backups' ); ?></a>
            &nbsp;|&nbsp;
            <a href="https://github.com/SpeeDigital/speed-backups/issues" target="_blank"><?php esc_html_e( 'Support', 'speed-backups' ); ?></a>
        </p>
    </div>
</div>
