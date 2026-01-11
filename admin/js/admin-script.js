/**
 * Speed Backups Admin JavaScript
 *
 * @package SpeedBackups
 * @since 1.0.0
 */

(function($) {
    'use strict';

    // State management
    const state = {
        backupJobId: null,
        restoreJobId: null,
        isBackupRunning: false,
        isRestoreRunning: false,
        isUploading: false
    };

    // DOM elements cache
    const elements = {};

    /**
     * Initialize the plugin
     */
    function init() {
        cacheElements();
        bindEvents();
    }

    /**
     * Cache DOM elements
     */
    function cacheElements() {
        // Backup elements
        elements.backupSection = $('#sb-backup-section');
        elements.startBackupBtn = $('#sb-start-backup');
        elements.cancelBackupBtn = $('#sb-cancel-backup');
        elements.backupProgress = $('#sb-backup-progress');
        elements.backupProgressFill = $('#sb-backup-progress-fill');
        elements.backupProgressText = $('#sb-backup-progress-text');
        elements.backupProgressPercent = $('#sb-backup-progress-percent');
        elements.backupResult = $('#sb-backup-result');
        elements.backupResultDetails = $('#sb-backup-result-details');
        elements.downloadBackupBtn = $('#sb-download-backup');

        // Backup options
        elements.includeDatabase = $('#sb-include-database');
        elements.includeFiles = $('#sb-include-files');
        elements.includeConfig = $('#sb-include-config');

        // Upload elements
        elements.uploadArea = $('#sb-upload-area');
        elements.uploadInput = $('#sb-upload-input');
        elements.uploadProgress = $('#sb-upload-progress');
        elements.uploadProgressFill = $('#sb-upload-progress-fill');
        elements.uploadProgressText = $('#sb-upload-progress-text');

        // Validation elements
        elements.validation = $('#sb-validation');
        elements.valCreated = $('#sb-val-created');
        elements.valSiteUrl = $('#sb-val-site-url');
        elements.valWpVersion = $('#sb-val-wp-version');
        elements.valContents = $('#sb-val-contents');
        elements.valSize = $('#sb-val-size');
        elements.valWarnings = $('#sb-val-warnings');
        elements.valWarningsList = $('#sb-val-warnings-list');
        elements.restoreFilePath = $('#sb-restore-file-path');
        elements.cancelValidationBtn = $('#sb-cancel-validation');

        // Restore options
        elements.restoreDatabase = $('#sb-restore-database');
        elements.restoreFiles = $('#sb-restore-files');
        elements.replaceUrls = $('#sb-replace-urls');

        // Restore elements
        elements.startRestoreBtn = $('#sb-start-restore');
        elements.cancelRestoreBtn = $('#sb-cancel-restore');
        elements.restoreProgress = $('#sb-restore-progress');
        elements.restoreProgressFill = $('#sb-restore-progress-fill');
        elements.restoreProgressText = $('#sb-restore-progress-text');
        elements.restoreProgressPercent = $('#sb-restore-progress-percent');
        elements.restoreResult = $('#sb-restore-result');

        // Backups list
        elements.backupsList = $('#sb-backups-list');
        elements.refreshBackupsBtn = $('#sb-refresh-backups');
    }

    /**
     * Bind events
     */
    function bindEvents() {
        // Backup events
        elements.startBackupBtn.on('click', startBackup);
        elements.cancelBackupBtn.on('click', cancelBackup);

        // Upload events
        elements.uploadArea.on('click', function() {
            elements.uploadInput.trigger('click');
        });
        elements.uploadInput.on('change', handleFileSelect);
        elements.uploadArea.on('dragover', handleDragOver);
        elements.uploadArea.on('dragleave', handleDragLeave);
        elements.uploadArea.on('drop', handleDrop);

        // Validation events
        elements.cancelValidationBtn.on('click', cancelValidation);

        // Restore events
        elements.startRestoreBtn.on('click', startRestore);
        elements.cancelRestoreBtn.on('click', cancelRestore);

        // Backup list events
        elements.refreshBackupsBtn.on('click', refreshBackupsList);
        $(document).on('click', '.sb-restore-backup', handleRestoreFromList);
        $(document).on('click', '.sb-delete-backup', handleDeleteBackup);
    }

    /**
     * Start backup process
     */
    function startBackup() {
        if (state.isBackupRunning) {
            return;
        }

        state.isBackupRunning = true;

        // Update UI
        elements.startBackupBtn.prop('disabled', true).hide();
        elements.cancelBackupBtn.show();
        elements.backupProgress.show();
        elements.backupResult.hide();
        updateBackupProgress(0, speedBackups.strings.backupStarted);

        // Start backup
        $.ajax({
            url: speedBackups.ajaxUrl,
            type: 'POST',
            data: {
                action: 'speed_backups_start_backup',
                nonce: speedBackups.nonce,
                include_database: elements.includeDatabase.is(':checked') ? 1 : 0,
                include_files: elements.includeFiles.is(':checked') ? 1 : 0,
                include_config: elements.includeConfig.is(':checked') ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    state.backupJobId = response.data.job_id;
                    processBackup();
                } else {
                    backupError(response.data.message);
                }
            },
            error: function() {
                backupError(speedBackups.strings.errorOccurred);
            }
        });
    }

    /**
     * Process backup chunks
     */
    function processBackup() {
        if (!state.isBackupRunning || !state.backupJobId) {
            return;
        }

        $.ajax({
            url: speedBackups.ajaxUrl,
            type: 'POST',
            data: {
                action: 'speed_backups_process_backup',
                nonce: speedBackups.nonce,
                job_id: state.backupJobId
            },
            success: function(response) {
                if (response.success) {
                    updateBackupProgress(response.data.progress, response.data.message);

                    if (response.data.complete) {
                        backupComplete(response.data);
                    } else {
                        // Continue processing
                        setTimeout(processBackup, 100);
                    }
                } else {
                    backupError(response.data.message);
                }
            },
            error: function() {
                backupError(speedBackups.strings.errorOccurred);
            }
        });
    }

    /**
     * Update backup progress
     */
    function updateBackupProgress(percent, message) {
        elements.backupProgressFill.css('width', percent + '%');
        elements.backupProgressPercent.text(Math.round(percent) + '%');
        if (message) {
            elements.backupProgressText.text(message);
        }
    }

    /**
     * Backup complete
     */
    function backupComplete(data) {
        state.isBackupRunning = false;
        state.backupJobId = null;

        elements.cancelBackupBtn.hide();
        elements.backupProgress.hide();
        elements.backupResult.show();
        elements.backupResultDetails.text(
            data.result.file_name + ' (' + data.result.file_size_formatted + ')'
        );
        elements.downloadBackupBtn.attr('href', data.download_url);
        elements.startBackupBtn.prop('disabled', false).show();

        // Refresh backups list
        refreshBackupsList();
    }

    /**
     * Backup error
     */
    function backupError(message) {
        state.isBackupRunning = false;
        state.backupJobId = null;

        elements.cancelBackupBtn.hide();
        elements.backupProgressFill.addClass('sb-progress-error');
        elements.backupProgressText.text(speedBackups.strings.backupFailed + ' ' + message);
        elements.startBackupBtn.prop('disabled', false).show();

        setTimeout(function() {
            elements.backupProgress.hide();
            elements.backupProgressFill.removeClass('sb-progress-error');
        }, 5000);
    }

    /**
     * Cancel backup
     */
    function cancelBackup() {
        if (!state.backupJobId) {
            return;
        }

        $.ajax({
            url: speedBackups.ajaxUrl,
            type: 'POST',
            data: {
                action: 'speed_backups_cancel_backup',
                nonce: speedBackups.nonce,
                job_id: state.backupJobId
            },
            success: function() {
                state.isBackupRunning = false;
                state.backupJobId = null;

                elements.cancelBackupBtn.hide();
                elements.backupProgress.hide();
                elements.startBackupBtn.prop('disabled', false).show();
                elements.backupProgressText.text(speedBackups.strings.cancelled);
            }
        });
    }

    /**
     * Handle file drag over
     */
    function handleDragOver(e) {
        e.preventDefault();
        e.stopPropagation();
        elements.uploadArea.addClass('sb-upload-dragover');
    }

    /**
     * Handle file drag leave
     */
    function handleDragLeave(e) {
        e.preventDefault();
        e.stopPropagation();
        elements.uploadArea.removeClass('sb-upload-dragover');
    }

    /**
     * Handle file drop
     */
    function handleDrop(e) {
        e.preventDefault();
        e.stopPropagation();
        elements.uploadArea.removeClass('sb-upload-dragover');

        const files = e.originalEvent.dataTransfer.files;
        if (files.length > 0) {
            handleFile(files[0]);
        }
    }

    /**
     * Handle file select
     */
    function handleFileSelect(e) {
        const files = e.target.files;
        if (files.length > 0) {
            handleFile(files[0]);
        }
    }

    /**
     * Handle uploaded file
     */
    function handleFile(file) {
        // Validate file type
        if (!file.name.toLowerCase().endsWith('.zip')) {
            alert(speedBackups.strings.invalidFile);
            return;
        }

        // Check file size
        if (file.size > speedBackups.maxUploadSize) {
            alert(speedBackups.strings.invalidFile + ' File too large.');
            return;
        }

        uploadFile(file);
    }

    /**
     * Upload file
     */
    function uploadFile(file) {
        if (state.isUploading) {
            return;
        }

        state.isUploading = true;

        const formData = new FormData();
        formData.append('action', 'speed_backups_upload_backup');
        formData.append('nonce', speedBackups.nonce);
        formData.append('backup_file', file);

        elements.uploadArea.hide();
        elements.uploadProgress.show();
        updateUploadProgress(0);

        $.ajax({
            url: speedBackups.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                const xhr = new XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        const percent = (e.loaded / e.total) * 100;
                        updateUploadProgress(percent);
                    }
                });
                return xhr;
            },
            success: function(response) {
                state.isUploading = false;
                elements.uploadProgress.hide();

                if (response.success) {
                    showValidation(response.data);
                } else {
                    elements.uploadArea.show();
                    alert(response.data.message);
                }
            },
            error: function() {
                state.isUploading = false;
                elements.uploadProgress.hide();
                elements.uploadArea.show();
                alert(speedBackups.strings.uploadFailed);
            }
        });
    }

    /**
     * Update upload progress
     */
    function updateUploadProgress(percent) {
        elements.uploadProgressFill.css('width', percent + '%');
        elements.uploadProgressText.text(
            speedBackups.strings.uploading + ' ' + Math.round(percent) + '%'
        );
    }

    /**
     * Show validation panel
     */
    function showValidation(data) {
        const validation = data.validation;
        const manifest = validation.manifest;

        elements.restoreFilePath.val(data.file_path);

        // Populate validation info
        if (manifest.created) {
            const date = new Date(manifest.created);
            elements.valCreated.text(date.toLocaleString());
        }
        elements.valSiteUrl.text(manifest.site_url || 'N/A');
        elements.valWpVersion.text(manifest.wp_version || 'N/A');
        elements.valSize.text(validation.total_size_formatted);

        // Contents
        const contents = [];
        if (manifest.contents) {
            if (manifest.contents.database) contents.push('Database');
            if (manifest.contents.files) contents.push(manifest.contents.files + ' files');
            if (manifest.contents.wp_config) contents.push('wp-config.php');
        }
        elements.valContents.text(contents.join(', ') || 'Unknown');

        // Warnings
        if (validation.warnings && validation.warnings.length > 0) {
            elements.valWarningsList.empty();
            validation.warnings.forEach(function(warning) {
                elements.valWarningsList.append('<li>' + escapeHtml(warning) + '</li>');
            });
            elements.valWarnings.show();
        } else {
            elements.valWarnings.hide();
        }

        elements.validation.show();
    }

    /**
     * Cancel validation
     */
    function cancelValidation() {
        elements.validation.hide();
        elements.uploadArea.show();
        elements.restoreFilePath.val('');
    }

    /**
     * Start restore process
     */
    function startRestore() {
        const filePath = elements.restoreFilePath.val();

        if (!filePath) {
            alert(speedBackups.strings.selectFile);
            return;
        }

        if (!confirm(speedBackups.strings.confirmRestore)) {
            return;
        }

        state.isRestoreRunning = true;

        elements.validation.hide();
        elements.restoreProgress.show();
        updateRestoreProgress(0, speedBackups.strings.restoreStarted);

        $.ajax({
            url: speedBackups.ajaxUrl,
            type: 'POST',
            data: {
                action: 'speed_backups_start_restore',
                nonce: speedBackups.nonce,
                file_path: filePath,
                restore_database: elements.restoreDatabase.is(':checked') ? 1 : 0,
                restore_files: elements.restoreFiles.is(':checked') ? 1 : 0,
                replace_urls: elements.replaceUrls.is(':checked') ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    state.restoreJobId = response.data.job_id;
                    processRestore();
                } else {
                    restoreError(response.data.message);
                }
            },
            error: function() {
                restoreError(speedBackups.strings.errorOccurred);
            }
        });
    }

    /**
     * Process restore chunks
     */
    function processRestore() {
        if (!state.isRestoreRunning || !state.restoreJobId) {
            return;
        }

        $.ajax({
            url: speedBackups.ajaxUrl,
            type: 'POST',
            data: {
                action: 'speed_backups_process_restore',
                nonce: speedBackups.nonce,
                job_id: state.restoreJobId
            },
            success: function(response) {
                if (response.success) {
                    updateRestoreProgress(response.data.progress, response.data.message);

                    if (response.data.complete) {
                        restoreComplete(response.data);
                    } else {
                        setTimeout(processRestore, 100);
                    }
                } else {
                    restoreError(response.data.message);
                }
            },
            error: function() {
                restoreError(speedBackups.strings.errorOccurred);
            }
        });
    }

    /**
     * Update restore progress
     */
    function updateRestoreProgress(percent, message) {
        elements.restoreProgressFill.css('width', percent + '%');
        elements.restoreProgressPercent.text(Math.round(percent) + '%');
        if (message) {
            elements.restoreProgressText.text(message);
        }
    }

    /**
     * Restore complete
     */
    function restoreComplete(data) {
        state.isRestoreRunning = false;
        state.restoreJobId = null;

        elements.restoreProgress.hide();
        elements.restoreResult.show();

        // Reload page after 3 seconds
        setTimeout(function() {
            window.location.reload();
        }, 3000);
    }

    /**
     * Restore error
     */
    function restoreError(message) {
        state.isRestoreRunning = false;
        state.restoreJobId = null;

        elements.restoreProgressFill.addClass('sb-progress-error');
        elements.restoreProgressText.text(speedBackups.strings.restoreFailed + ' ' + message);

        setTimeout(function() {
            elements.restoreProgress.hide();
            elements.uploadArea.show();
            elements.restoreProgressFill.removeClass('sb-progress-error');
        }, 5000);
    }

    /**
     * Cancel restore
     */
    function cancelRestore() {
        if (!state.restoreJobId) {
            return;
        }

        $.ajax({
            url: speedBackups.ajaxUrl,
            type: 'POST',
            data: {
                action: 'speed_backups_cancel_restore',
                nonce: speedBackups.nonce,
                job_id: state.restoreJobId
            },
            success: function() {
                state.isRestoreRunning = false;
                state.restoreJobId = null;

                elements.restoreProgress.hide();
                elements.uploadArea.show();
            }
        });
    }

    /**
     * Handle restore from backups list
     */
    function handleRestoreFromList(e) {
        e.preventDefault();

        const filePath = $(this).data('file');

        // Validate the backup first
        $.ajax({
            url: speedBackups.ajaxUrl,
            type: 'POST',
            data: {
                action: 'speed_backups_validate_backup',
                nonce: speedBackups.nonce,
                file_path: filePath
            },
            success: function(response) {
                if (response.success) {
                    // Scroll to restore section
                    $('html, body').animate({
                        scrollTop: $('#sb-restore-section').offset().top - 50
                    }, 500);

                    // Show validation
                    elements.uploadArea.hide();
                    showValidation({
                        file_path: filePath,
                        validation: response.data.validation
                    });
                } else {
                    alert(response.data.message);
                }
            },
            error: function() {
                alert(speedBackups.strings.errorOccurred);
            }
        });
    }

    /**
     * Handle delete backup
     */
    function handleDeleteBackup(e) {
        e.preventDefault();

        if (!confirm(speedBackups.strings.confirmDelete)) {
            return;
        }

        const $btn = $(this);
        const fileName = $btn.data('file');

        $btn.prop('disabled', true);

        $.ajax({
            url: speedBackups.ajaxUrl,
            type: 'POST',
            data: {
                action: 'speed_backups_delete_backup',
                nonce: speedBackups.nonce,
                file_name: fileName
            },
            success: function(response) {
                if (response.success) {
                    $btn.closest('tr').fadeOut(300, function() {
                        $(this).remove();

                        // Check if list is empty
                        if (elements.backupsList.find('tr').length === 0) {
                            elements.backupsList.closest('.sb-card-body').html(
                                '<p class="sb-no-backups">No backups found. Create your first backup above.</p>'
                            );
                        }
                    });
                } else {
                    alert(response.data.message);
                    $btn.prop('disabled', false);
                }
            },
            error: function() {
                alert(speedBackups.strings.errorOccurred);
                $btn.prop('disabled', false);
            }
        });
    }

    /**
     * Refresh backups list
     */
    function refreshBackupsList() {
        elements.refreshBackupsBtn.prop('disabled', true);

        $.ajax({
            url: speedBackups.ajaxUrl,
            type: 'POST',
            data: {
                action: 'speed_backups_get_backups',
                nonce: speedBackups.nonce
            },
            success: function(response) {
                elements.refreshBackupsBtn.prop('disabled', false);

                if (response.success) {
                    // Reload the page to refresh the list
                    window.location.reload();
                }
            },
            error: function() {
                elements.refreshBackupsBtn.prop('disabled', false);
            }
        });
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize when document is ready
    $(document).ready(init);

})(jQuery);
