jQuery(document).ready(function($) {
    
    let totalPosts = 522; // Estimated total posts
    let totalProcessed = 0;
    let totalCreated = 0;
    let totalUpdated = 0;
    let totalSkipped = 0;
    let totalErrors = 0;
    let currentBatch = 0;
    
    function updateProgress(percent, text) {
        $('#progress-fill').css('width', percent + '%');
        $('#progress-text').text(text);
    }
    
    function addLogEntry(message, type = 'info') {
        const logEntry = $('<div class="log-entry log-' + type + '">').text(new Date().toLocaleTimeString() + ': ' + message);
        $('#migration-log').append(logEntry);
        $('#migration-log').scrollTop($('#migration-log')[0].scrollHeight);
    }
    
    function performMigration(action, buttonElement) {
        if (action === 'posts') {
            performPostsMigration(buttonElement);
            return;
        }
        
        // Regular migration for categories and tags
        const originalText = buttonElement.text();
        buttonElement.prop('disabled', true).text('Processing...');
        
        $('#migration-status').show();
        $('#migration-controls').hide();
        
        addLogEntry('Starting ' + action + ' migration...', 'info');
        updateProgress(0, 'Initializing...');
        
        $.ajax({
            url: wixMigrator.ajaxurl,
            type: 'POST',
            data: {
                action: 'wix_migrate',
                migrate_action: action,
                nonce: wixMigrator.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateProgress(100, 'Migration completed successfully!');
                    addLogEntry('Migration completed successfully', 'success');
                } else {
                    addLogEntry('Migration failed: ' + response.data, 'error');
                    updateProgress(0, 'Migration failed');
                }
            },
            error: function(xhr, status, error) {
                addLogEntry('AJAX error: ' + error, 'error');
                updateProgress(0, 'Migration failed');
            },
            complete: function() {
                buttonElement.prop('disabled', false).text(originalText);
                $('#migration-controls').show();
            }
        });
    }
    
    function performPostsMigration(buttonElement) {
        const originalText = buttonElement.text();
        buttonElement.prop('disabled', true).text('Migrating...');
        
        $('#migration-status').show();
        $('#migration-controls').hide();
        
        // Reset counters
        totalProcessed = 0;
        totalCreated = 0;
        totalUpdated = 0;
        totalSkipped = 0;
        totalErrors = 0;
        
        addLogEntry('Starting posts migration with batch processing...', 'info');
        updateProgress(0, 'Initializing batch migration...');
        
        migrateBatch(0, 100);
        
        function migrateBatch(offset, limit) {
            currentBatch = Math.floor(offset / limit) + 1;
            
            addLogEntry(`Processing batch ${currentBatch}: posts ${offset + 1}-${Math.min(offset + limit, totalPosts)}`, 'info');
            
            $.ajax({
                url: wixMigrator.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wix_migrate_batch',
                    migrate_action: 'posts',
                    offset: offset,
                    limit: limit,
                    nonce: wixMigrator.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        
                        // Update counters
                        totalProcessed += data.processed_in_batch || 0;
                        totalCreated += data.created || 0;
                        totalUpdated += data.updated || 0;
                        totalSkipped += data.skipped || 0;
                        totalErrors += (data.errors || []).length;
                        
                        // Update progress
                        const overallPercent = Math.min(100, (totalProcessed / totalPosts) * 100);
                        updateProgress(overallPercent, `Progress: ${totalProcessed}/${totalPosts} posts (${Math.round(overallPercent)}%)`);
                        
                        // Log batch results with detailed stats
                        let batchMessage = `Batch ${currentBatch} completed: ${data.created || 0} created, ${data.updated || 0} updated`;
                        if (data.skipped > 0) {
                            batchMessage += `, ${data.skipped} skipped`;
                        }
                        addLogEntry(batchMessage, 'success');
                        
                        if (data.errors && data.errors.length > 0) {
                            data.errors.forEach(error => {
                                addLogEntry(`Error: ${error}`, 'error');
                            });
                        }
                        
                        // Continue to next batch if there are more posts
                        if (data.has_more && totalProcessed < totalPosts && data.processed_in_batch > 0) {
                            setTimeout(() => {
                                migrateBatch(data.next_offset, limit);
                            }, 500); // Small delay between batches
                        } else {
                            // Migration complete - show detailed summary
                            updateProgress(100, `Migration completed! ${totalProcessed} posts processed`);
                            
                            let summaryMessage = `Migration completed: ${totalProcessed} processed`;
                            let summaryParts = [];
                            if (totalCreated > 0) summaryParts.push(`${totalCreated} created`);
                            if (totalUpdated > 0) summaryParts.push(`${totalUpdated} updated`);
                            if (totalSkipped > 0) summaryParts.push(`${totalSkipped} skipped`);
                            if (totalErrors > 0) summaryParts.push(`${totalErrors} errors`);
                            
                            if (summaryParts.length > 0) {
                                summaryMessage += ` (${summaryParts.join(', ')})`;
                            }
                            
                            addLogEntry(summaryMessage, 'success');
                            
                            // Show discrepancy warning if any
                            const totalActual = totalCreated + totalUpdated;
                            if (totalProcessed > totalActual + totalSkipped + totalErrors) {
                                const missing = totalProcessed - totalActual - totalSkipped - totalErrors;
                                addLogEntry(`Warning: ${missing} posts were processed but not accounted for`, 'error');
                            }
                            
                            // Re-enable button
                            buttonElement.prop('disabled', false).text(originalText);
                            $('#migration-controls').show();
                        }
                    } else {
                        addLogEntry(`Batch ${currentBatch} failed: ${response.data}`, 'error');
                        buttonElement.prop('disabled', false).text(originalText);
                        $('#migration-controls').show();
                    }
                },
                error: function(xhr, status, error) {
                    addLogEntry(`AJAX error in batch ${currentBatch}: ${error}`, 'error');
                    buttonElement.prop('disabled', false).text(originalText);
                    $('#migration-controls').show();
                }
            });
        }
    }
    
    $('#migrate-categories').on('click', function() {
        performMigration('categories', $(this));
    });
    
    $('#migrate-tags').on('click', function() {
        performMigration('tags', $(this));
    });
    
    $('#migrate-posts').on('click', function() {
        performMigration('posts', $(this));
    });
    
});