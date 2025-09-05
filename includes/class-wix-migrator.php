<?php

class Wix_Migrator {
    
    private $wix_api;
    private $mapping_table;
    
    public function __construct($client_id) {
        $this->wix_api = new Wix_API($client_id);
        global $wpdb;
        $this->mapping_table = $wpdb->prefix . 'wix_migration_mapping';
    }
    
    public function migrate_all() {
        $results = array(
            'categories' => array('created' => 0, 'updated' => 0),
            'tags' => array('created' => 0, 'updated' => 0),
            'posts' => array('created' => 0, 'updated' => 0),
            'errors' => array()
        );
        
        // First migrate categories
        $category_result = $this->migrate_categories();
        if (is_wp_error($category_result)) {
            $results['errors'][] = 'Categories: ' . $category_result->get_error_message();
        } else {
            $results['categories'] = $category_result;
        }
        
        // Then migrate tags
        $tag_result = $this->migrate_tags();
        if (is_wp_error($tag_result)) {
            $results['errors'][] = 'Tags: ' . $tag_result->get_error_message();
        } else {
            $results['tags'] = $tag_result;
        }
        
        // Finally migrate posts
        $post_result = $this->migrate_posts();
        if (is_wp_error($post_result)) {
            $results['errors'][] = 'Posts: ' . $post_result->get_error_message();
        } else {
            $results['posts'] = $post_result;
        }
        
        return $results;
    }
    
    public function migrate_categories() {
        $results = array('created' => 0, 'updated' => 0, 'errors' => array());
        
        $offset = 0;
        $limit = 100;
        $has_more = true;
        
        while ($has_more) {
            $response = $this->wix_api->get_categories($offset, $limit);
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            if (empty($response['categories'])) {
                $has_more = false;
                continue;
            }
            
            foreach ($response['categories'] as $wix_category) {
                $result = $this->process_category($wix_category);
                
                if (is_wp_error($result)) {
                    $results['errors'][] = $result->get_error_message();
                    continue;
                }
                
                if ($result['action'] === 'created') {
                    $results['created']++;
                } else {
                    $results['updated']++;
                }
            }
            
            // Check if we have more pages
            $has_more = isset($response['pagingMetadata']['hasNext']) && $response['pagingMetadata']['hasNext'];
            $offset += $limit;
        }
        
        return $results;
    }
    
    public function migrate_tags() {
        $results = array('created' => 0, 'updated' => 0, 'errors' => array());
        
        $offset = 0;
        $limit = 100;
        $has_more = true;
        
        while ($has_more) {
            $response = $this->wix_api->get_tags($offset, $limit);
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            if (empty($response['tags'])) {
                $has_more = false;
                continue;
            }
            
            foreach ($response['tags'] as $wix_tag) {
                $result = $this->process_tag($wix_tag);
                
                if (is_wp_error($result)) {
                    $results['errors'][] = $result->get_error_message();
                    continue;
                }
                
                if ($result['action'] === 'created') {
                    $results['created']++;
                } else {
                    $results['updated']++;
                }
            }
            
            // Check if we have more pages
            $total = isset($response['metaData']['total']) ? $response['metaData']['total'] : 0;
            $current_count = $offset + count($response['tags']);
            $has_more = $current_count < $total;
            $offset += $limit;
        }
        
        return $results;
    }
    
    public function migrate_posts() {
        $results = array(
            'created' => 0, 
            'updated' => 0, 
            'skipped' => 0,
            'errors' => array(),
            'failed_posts' => array(), // Track individual failed posts for manual retry
            'total_processed' => 0,
            'batches_processed' => 0
        );
        
        $offset = 0;
        $limit = 100; // Use max limit for better performance  
        $has_more = true;
        $consecutive_empty_responses = 0;
        $max_empty_responses = 3; // Stop after 3 consecutive empty responses
        
        try {
            while ($has_more && $consecutive_empty_responses < $max_empty_responses) {
            $response = $this->wix_api->get_posts($offset, $limit);
            
            if (is_wp_error($response)) {
                $results['errors'][] = 'API Error at offset ' . $offset . ': ' . $response->get_error_message();
                break;
            }
            
            // Check if response is empty
            if (empty($response['posts']) || !is_array($response['posts'])) {
                $consecutive_empty_responses++;
                if ($consecutive_empty_responses >= $max_empty_responses) {
                    $has_more = false;
                    break;
                }
                $offset += $limit;
                continue;
            } else {
                $consecutive_empty_responses = 0; // Reset counter
            }
            
            $batch_size = count($response['posts']);
            $results['batches_processed']++;
            
            foreach ($response['posts'] as $index => $wix_post) {
                $results['total_processed']++;
                
                // Skip if missing required fields
                if (empty($wix_post['id']) || empty($wix_post['title'])) {
                    $results['skipped']++;
                    $error_msg = 'Post missing ID or title at offset ' . ($offset + $index);
                    $results['errors'][] = $error_msg;
                    $results['failed_posts'][] = array(
                        'wix_id' => $wix_post['id'] ?? 'unknown',
                        'title' => $wix_post['title'] ?? 'No title',
                        'error' => $error_msg,
                        'retry_possible' => false
                    );
                    continue;
                }
                
                $result = $this->process_post($wix_post);
                
                if (is_wp_error($result)) {
                    $error_msg = 'Post ID ' . $wix_post['id'] . ': ' . $result->get_error_message();
                    $results['errors'][] = $error_msg;
                    $results['failed_posts'][] = array(
                        'wix_id' => $wix_post['id'],
                        'title' => $wix_post['title'],
                        'error' => $result->get_error_message(),
                        'retry_possible' => true,
                        'wix_data' => $wix_post // Store original data for retry
                    );
                    
                    // Throw critical error to stop batch if too many consecutive failures
                    static $consecutive_failures = 0;
                    $consecutive_failures++;
                    if ($consecutive_failures >= 5) {
                        throw new Exception("MIGRATION HALTED: 5 consecutive post sync failures detected. Last error: " . $result->get_error_message() . ". Check failed posts for manual retry.");
                    }
                    continue;
                } else {
                    // Reset consecutive failure counter on success
                    $consecutive_failures = 0;
                }
                
                if ($result['action'] === 'created') {
                    $results['created']++;
                } else {
                    $results['updated']++;
                }
                
                // Add small delay to prevent overwhelming the server
                if (($results['total_processed'] % 10) === 0) {
                    usleep(100000); // 0.1 second pause every 10 posts
                }
            }
            
            // Determine if we have more pages
            if (isset($response['pagingMetadata'])) {
                $has_more = isset($response['pagingMetadata']['hasNext']) && $response['pagingMetadata']['hasNext'];
            } else {
                // Fallback: if we got less than the limit, we're probably at the end
                $has_more = ($batch_size >= $limit);
            }
            
            $offset += $limit;
            
            // Safety check to prevent infinite loops
            if ($offset > 10000) { // Max 10k posts
                $results['errors'][] = 'Safety limit reached: Stopped at offset ' . $offset;
                break;
            }
            }
        } catch (Exception $e) {
            $results['errors'][] = "CRITICAL MIGRATION ERROR: " . $e->getMessage();
            error_log("Wix Migration: Critical migration error - " . $e->getMessage());
            
            // Return results with summary of what was processed before the error
            if (!empty($results['failed_posts'])) {
                $results['failed_posts_summary'] = $this->get_failed_posts_summary($results['failed_posts']);
            }
        }
        
        return $results;
    }
    
    public function migrate_posts_batch($offset = 0, $limit = 100) {
        $results = array(
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => array(),
            'failed_posts' => array(), // Track individual failed posts for manual retry
            'processed_in_batch' => 0,
            'offset' => $offset,
            'limit' => $limit,
            'has_more' => false,
            'next_offset' => $offset + $limit
        );
        
        $response = $this->wix_api->get_posts($offset, $limit);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Check if response has posts
        if (empty($response['posts']) || !is_array($response['posts'])) {
            $results['has_more'] = false;
            return $results;
        }
        
        $batch_size = count($response['posts']);
        $results['processed_in_batch'] = $batch_size;
        $results['has_more'] = $batch_size >= $limit; // Assume more if we got full batch
        
        foreach ($response['posts'] as $index => $wix_post) {
            // Skip if missing required fields
            if (empty($wix_post['id']) || empty($wix_post['title'])) {
                $results['skipped']++;
                $error_msg = 'Post missing ID or title at offset ' . ($offset + $index);
                $results['errors'][] = $error_msg;
                $results['failed_posts'][] = array(
                    'wix_id' => $wix_post['id'] ?? 'unknown',
                    'title' => $wix_post['title'] ?? 'No title',
                    'error' => $error_msg,
                    'retry_possible' => false
                );
                continue;
            }
            
            // Check if this post already exists using our mapping table
            $wp_id = $this->get_wp_id_by_wix_id($wix_post['id'], 'post');
            $is_existing = !empty($wp_id);
            
            $result = $this->process_post($wix_post);
            
            if (is_wp_error($result)) {
                $error_msg = 'Post ID ' . $wix_post['id'] . ' ("' . substr($wix_post['title'], 0, 50) . '"): ' . $result->get_error_message();
                $results['errors'][] = $error_msg;
                $results['failed_posts'][] = array(
                    'wix_id' => $wix_post['id'],
                    'title' => $wix_post['title'],
                    'error' => $result->get_error_message(),
                    'retry_possible' => true,
                    'wix_data' => $wix_post // Store original data for retry
                );
                continue;
            }
            
            // Verify the result was successful
            if (!is_array($result) || !isset($result['wp_id']) || empty($result['wp_id'])) {
                $results['errors'][] = 'Post ID ' . $wix_post['id'] . ' ("' . substr($wix_post['title'], 0, 50) . '"): Invalid result from process_post';
                continue;
            }
            
            // Track based on the actual action returned
            if ($result['action'] === 'created') {
                $results['created']++;
            } elseif ($result['action'] === 'updated') {
                $results['updated']++;
            } else {
                $results['errors'][] = 'Post ID ' . $wix_post['id'] . ': Unknown action "' . $result['action'] . '"';
            }
        }
        
        return $results;
    }
    
    public function retry_failed_post($wix_post_data) {
        error_log("Wix Migration: Manual retry attempt for post " . ($wix_post_data['id'] ?? 'unknown'));
        
        if (empty($wix_post_data['id'])) {
            throw new Exception("Cannot retry post without Wix ID");
        }
        
        try {
            $result = $this->process_post($wix_post_data);
            
            if (is_wp_error($result)) {
                throw new Exception("Post retry failed for " . $wix_post_data['id'] . ": " . $result->get_error_message());
            }
            
            error_log("Wix Migration: Manual retry SUCCESS for post " . $wix_post_data['id'] . " -> WP ID " . $result['wp_id']);
            return array(
                'success' => true,
                'wp_id' => $result['wp_id'],
                'action' => $result['action'],
                'wix_id' => $wix_post_data['id'],
                'title' => $wix_post_data['title'] ?? 'Unknown title'
            );
            
        } catch (Exception $e) {
            error_log("Wix Migration: Manual retry EXCEPTION for post " . $wix_post_data['id'] . ": " . $e->getMessage());
            throw new Exception("Manual retry exception: " . $e->getMessage());
        }
    }
    
    public function get_failed_posts_summary($failed_posts) {
        if (empty($failed_posts)) {
            return "No failed posts to report.";
        }
        
        $summary = "FAILED POSTS SUMMARY (" . count($failed_posts) . " posts failed):\n\n";
        
        foreach ($failed_posts as $index => $failed_post) {
            $retry_status = $failed_post['retry_possible'] ? "[RETRY POSSIBLE]" : "[NO RETRY - DATA ISSUE]";
            $summary .= ($index + 1) . ". $retry_status\n";
            $summary .= "   Wix ID: " . $failed_post['wix_id'] . "\n";
            $summary .= "   Title: " . substr($failed_post['title'], 0, 60) . "\n";
            $summary .= "   Error: " . $failed_post['error'] . "\n\n";
        }
        
        $summary .= "MANUAL MIGRATION INSTRUCTIONS:\n";
        $summary .= "1. Copy the Wix ID of failed posts\n";
        $summary .= "2. Use the retry function in admin to attempt manual migration\n";
        $summary .= "3. For posts marked [NO RETRY], check the original Wix data integrity\n";
        
        return $summary;
    }
    
    private function process_category($wix_category) {
        $wix_id = $wix_category['id'];
        
        // Check if category already exists in mapping
        $wp_id = $this->get_wp_id_by_wix_id($wix_id, 'category');
        
        $category_data = array(
            'name' => sanitize_text_field($wix_category['label']),
            'slug' => sanitize_title($wix_category['slug'] ?? $wix_category['label']),
            'description' => sanitize_textarea_field($wix_category['description'] ?? ''),
        );
        
        if ($wp_id) {
            // Update existing category
            $category_data['term_id'] = $wp_id;
            $result = wp_update_term($wp_id, 'category', $category_data);
            $action = 'updated';
        } else {
            // Create new category
            $result = wp_insert_term($category_data['name'], 'category', $category_data);
            $action = 'created';
        }
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        $term_id = is_array($result) ? $result['term_id'] : $result;
        
        // Update or create mapping
        if (!$wp_id) {
            $this->save_mapping($wix_id, $term_id, 'category');
        }
        
        return array('action' => $action, 'wp_id' => $term_id);
    }
    
    private function process_tag($wix_tag) {
        $wix_id = $wix_tag['id'];
        
        // Check if tag already exists in mapping
        $wp_id = $this->get_wp_id_by_wix_id($wix_id, 'tag');
        
        $tag_data = array(
            'name' => sanitize_text_field($wix_tag['label']),
            'slug' => sanitize_title($wix_tag['slug'] ?? $wix_tag['label']),
        );
        
        if ($wp_id) {
            // Update existing tag
            $tag_data['term_id'] = $wp_id;
            $result = wp_update_term($wp_id, 'post_tag', $tag_data);
            $action = 'updated';
        } else {
            // Create new tag
            $result = wp_insert_term($tag_data['name'], 'post_tag', $tag_data);
            $action = 'created';
        }
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        $term_id = is_array($result) ? $result['term_id'] : $result;
        
        // Update or create mapping
        if (!$wp_id) {
            $this->save_mapping($wix_id, $term_id, 'tag');
        }
        
        return array('action' => $action, 'wp_id' => $term_id);
    }
    
    private function process_post($wix_post) {
        $wix_id = $wix_post['id'] ?? 'unknown';
        $post_title = $wix_post['title'] ?? 'Untitled';
        
        // Log start of processing (only for debugging if needed)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Wix Migration: Starting to process post $wix_id");
        }
        
        try {
            // Check if post already exists in mapping
            $wp_id = $this->get_wp_id_by_wix_id($wix_id, 'post');
            
            // Convert rich content with detailed error handling
            $post_content = '';
            $content_issues = [];
            
            try {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Wix Migration: Converting rich content for post $wix_id");
                }
                $post_content = $this->convert_rich_content($wix_post['richContent'] ?? array());
                
                if (empty($post_content)) {
                    $content_issues[] = 'Rich content conversion returned empty result';
                    if (!defined('WIX_MIGRATION_QUIET_MODE')) {
                        error_log("Wix Migration: Rich content conversion returned empty for post $wix_id");
                    }
                    
                    // Check if we have excerpt as fallback
                    $excerpt = sanitize_textarea_field($wix_post['excerpt'] ?? '');
                    if (empty($excerpt)) {
                        return new WP_Error('empty_content', 
                            "No content available for migration for Wix ID: $wix_id\n" .
                            "Title: \"$post_title\"\n" .
                            "Both rich content and excerpt are empty.\n" .
                            "This post requires manual content review and retry."
                        );
                    } else {
                        // Use excerpt as content if available
                        $post_content = $excerpt;
                        if (!defined('WIX_MIGRATION_QUIET_MODE')) {
                            error_log("Wix Migration: Using excerpt as content for post $wix_id");
                        }
                    }
                } else {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("Wix Migration: Rich content converted successfully for post $wix_id (" . strlen($post_content) . " chars)");
                    }
                }
                
                // Limit content length to prevent database issues
                if (strlen($post_content) > 50000) {
                    $content_issues[] = 'Content truncated due to length (' . strlen($post_content) . ' chars)';
                    $post_content = substr($post_content, 0, 50000) . '...[content truncated]';
                    // Don't log truncation - too verbose
                }
                
                // Basic HTML validation - log warning but continue processing
                // Can be disabled by setting WIX_MIGRATION_SKIP_HTML_VALIDATION constant
                if ($post_content && !defined('WIX_MIGRATION_SKIP_HTML_VALIDATION') && !$this->is_valid_html($post_content)) {
                    $content_issues[] = 'Minor HTML validation issues detected (continuing with processing)';
                    // Don't log HTML validation issues - too verbose
                    // Continue processing - WordPress can handle minor HTML issues
                }
                
                // Check for images in content (only log if debugging)
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $image_count = substr_count($post_content, '<img');
                    if ($image_count > 0) {
                        error_log("Wix Migration: Found $image_count images in content for post $wix_id");
                    } else {
                        error_log("Wix Migration: No images found in content for post $wix_id");
                    }
                }
                
            } catch (Exception $e) {
                $content_issues[] = 'Rich content conversion exception: ' . $e->getMessage();
                error_log("Wix Migration: Rich content conversion FAILED for post $wix_id: " . $e->getMessage());
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Wix Migration: Stack trace: " . $e->getTraceAsString());
                }
                
                // Return error instead of creating post with fallback content
                return new WP_Error('content_conversion_failed', 
                    "Rich content conversion failed for Wix ID: $wix_id\n" .
                    "Title: \"$post_title\"\n" .
                    "Error: " . $e->getMessage() . "\n" .
                    "This post requires manual content review and retry."
                );
            }
            
            // Log content issues if any (only in debug mode)
            if (!empty($content_issues) && defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Wix Migration: Content issues for post $wix_id: " . implode('; ', $content_issues));
            }
            
            $post_data = array(
                'post_title' => sanitize_text_field($wix_post['title']),
                'post_content' => $post_content,
                'post_excerpt' => sanitize_textarea_field($wix_post['excerpt'] ?? ''),
                'post_status' => !empty($wix_post['firstPublishedDate']) ? 'publish' : 'draft',
                'post_author' => get_current_user_id(),
                'post_date' => $this->convert_wix_date($wix_post['firstPublishedDate'] ?? ''),
                'post_modified' => $this->convert_wix_date($wix_post['lastPublishedDate'] ?? ''),
                'meta_input' => array(
                    'wix_post_id' => $wix_id,
                    'wix_slug' => $wix_post['slug'] ?? '',
                    'wix_featured' => $wix_post['featured'] ?? false,
                    'wix_pinned' => $wix_post['pinned'] ?? false,
                    'wix_minutes_to_read' => $wix_post['minutesToRead'] ?? 0,
                    'wix_hashtags' => implode(',', $wix_post['hashtags'] ?? array()),
                )
            );
            
            if ($wp_id) {
                // Update existing post
                $post_data['ID'] = $wp_id;
                $result = wp_update_post($post_data);
                $action = 'updated';
            } else {
                // Create new post - check for duplicates first
                $existing_posts = get_posts(array(
                    'title' => $post_data['post_title'],
                    'post_type' => 'post',
                    'post_status' => array('publish', 'draft', 'private'),
                    'numberposts' => 1
                ));
                
                if (!empty($existing_posts)) {
                    // Found duplicate by title, update it instead
                    $post_data['ID'] = $existing_posts[0]->ID;
                    $result = wp_update_post($post_data);
                    $action = 'updated';
                    // Update mapping for this post
                    $this->save_mapping($wix_id, $existing_posts[0]->ID, 'post');
                } else {
                    // Add unique slug to prevent conflicts
                    if (empty($post_data['post_name'])) {
                        $post_data['post_name'] = sanitize_title($post_data['post_title']) . '-' . substr($wix_id, 0, 8);
                    }
                    $result = wp_insert_post($post_data);
                    $action = 'created';
                }
            }
            
            if (is_wp_error($result)) {
                return $result;
            }
            
            if (empty($result) || !is_numeric($result) || $result == 0) {
                // Log the WordPress error for debugging
                global $wpdb;
                $error_msg = $wpdb->last_error ? $wpdb->last_error : 'Unknown database error';
                
                error_log("Wix Migration: POST CREATION FAILED for post $wix_id - $error_msg");
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Wix Migration: Title: \"" . $post_data['post_title'] . "\"");
                    error_log("Wix Migration: Content length: " . strlen($post_content) . " characters");
                    if (!empty($content_issues)) {
                        error_log("Wix Migration: Content issues: " . implode('; ', $content_issues));
                    }
                }
                
                return new WP_Error('post_creation_failed', 
                    "WordPress failed to create post for Wix ID: $wix_id\n" . 
                    "Title: \"" . $post_data['post_title'] . "\"\n" .
                    "Database Error: $error_msg\n" .
                    (!empty($content_issues) ? "Content Issues: " . implode('; ', $content_issues) . "\n" : "") .
                    "This post requires manual investigation and retry."
                );
            } else {
                // Only log success in debug mode to reduce log spam
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Wix Migration: SUCCESS - Created post $result for Wix ID $wix_id");
                }
            }
            
            $post_id = intval($result);
        
            // Handle categories with error handling
            if (!empty($wix_post['categoryIds']) && is_array($wix_post['categoryIds'])) {
                try {
                    $category_ids = array();
                    foreach ($wix_post['categoryIds'] as $wix_category_id) {
                        $wp_category_id = $this->get_wp_id_by_wix_id($wix_category_id, 'category');
                        if ($wp_category_id) {
                            $category_ids[] = intval($wp_category_id);
                        }
                    }
                    if (!empty($category_ids)) {
                        wp_set_post_categories($post_id, $category_ids);
                    }
                } catch (Exception $e) {
                    if (!defined('WIX_MIGRATION_QUIET_MODE')) {
                        error_log('Wix Migration: Category assignment failed for post ' . $wix_id . ': ' . $e->getMessage());
                    }
                }
            }
            
            // Handle tags with error handling
            if (!empty($wix_post['tagIds']) && is_array($wix_post['tagIds'])) {
                try {
                    $tag_ids = array();
                    foreach ($wix_post['tagIds'] as $wix_tag_id) {
                        $wp_tag_id = $this->get_wp_id_by_wix_id($wix_tag_id, 'tag');
                        if ($wp_tag_id) {
                            $tag_ids[] = intval($wp_tag_id);
                        }
                    }
                    if (!empty($tag_ids)) {
                        wp_set_post_tags($post_id, $tag_ids);
                    }
                } catch (Exception $e) {
                    if (!defined('WIX_MIGRATION_QUIET_MODE')) {
                        error_log('Wix Migration: Tag assignment failed for post ' . $wix_id . ': ' . $e->getMessage());
                    }
                }
            }
            
            // Handle featured image with error handling (reduced logging)
            try {
                $cover_image_url = $this->extract_cover_image_url($wix_post['coverMedia'] ?? array());
                if (!empty($cover_image_url)) {
                    $featured_result = $this->set_featured_image($post_id, $cover_image_url);
                    if (is_wp_error($featured_result)) {
                        // Only log errors, not successes
                        error_log("Wix Migration: Featured image FAILED for post $wix_id: " . $featured_result->get_error_message());
                        add_post_meta($post_id, 'wix_featured_image_failed', $cover_image_url);
                        add_post_meta($post_id, 'wix_featured_image_error', $featured_result->get_error_message());
                    }
                }
            } catch (Exception $e) {
                error_log("Wix Migration: Featured image EXCEPTION for post $wix_id: " . $e->getMessage());
                add_post_meta($post_id, 'wix_featured_image_failed', 'exception');
                add_post_meta($post_id, 'wix_featured_image_error', $e->getMessage());
            }
            
            // Update or create mapping with error handling
            try {
                if (!$wp_id) {
                    $this->save_mapping($wix_id, $post_id, 'post');
                }
            } catch (Exception $e) {
                if (!defined('WIX_MIGRATION_QUIET_MODE')) {
                    error_log('Wix Migration: Mapping save failed for post ' . $wix_id . ': ' . $e->getMessage());
                }
                // Continue processing even if mapping fails
            }
            
            return array('action' => $action, 'wp_id' => $post_id);
            
        } catch (Exception $e) {
            error_log('Wix Migration: Unexpected error processing post ' . ($wix_post['id'] ?? 'unknown') . ': ' . $e->getMessage());
            return new WP_Error('post_processing_failed', 'Unexpected error: ' . $e->getMessage());
        }
    }
    
    private function convert_rich_content($rich_content) {
        if (empty($rich_content) || !is_array($rich_content)) {
            return '';
        }
        
        // Convert Wix rich content format to HTML
        if (isset($rich_content['nodes']) && is_array($rich_content['nodes'])) {
            return $this->parse_rich_content_nodes($rich_content['nodes']);
        }
        
        return '';
    }
    
    private function parse_rich_content_nodes($nodes) {
        $content = '';
        
        if (!is_array($nodes)) {
            return '';
        }
        
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['type'])) {
                continue;
            }
            
            switch ($node['type']) {
                case 'PARAGRAPH':
                    $paragraph_content = $this->parse_rich_content_nodes($node['nodes'] ?? array());
                    if (!empty(trim($paragraph_content))) {
                        $content .= '<p>' . $paragraph_content . '</p>' . "\n";
                    }
                    break;
                    
                case 'HEADING':
                    $level = isset($node['headingData']['level']) ? intval($node['headingData']['level']) : 2;
                    $level = max(1, min(6, $level)); // Ensure valid heading level
                    $heading_content = $this->parse_rich_content_nodes($node['nodes'] ?? array());
                    if (!empty(trim($heading_content))) {
                        $content .= '<h' . $level . '>' . $heading_content . '</h' . $level . '>' . "\n";
                    }
                    break;
                    
                case 'TEXT':
                    $text = $node['textData']['text'] ?? '';
                    if (!empty($text)) {
                        $formatted_text = $this->apply_text_decorations($text, $node['textData']['decorations'] ?? array());
                        $content .= $formatted_text;
                    }
                    break;
                    
                case 'IMAGE':
                    $src = $this->extract_image_url($node['imageData'] ?? array());
                    if (!empty($src) && is_string($src)) {
                        $alt = $node['imageData']['altText'] ?? '';
                        // Try to download and use local image
                        $attachment_id = $this->download_and_attach_image($src);
                        if ($attachment_id && !is_wp_error($attachment_id)) {
                            $local_src = wp_get_attachment_url($attachment_id);
                            $content .= '<img src="' . esc_url($local_src) . '" alt="' . esc_attr($alt) . '" class="wix-migrated-image wp-image-' . $attachment_id . '" />' . "\n";
                        } else {
                            // Fallback to original URL
                            $content .= '<img src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '" class="wix-migrated-image" />' . "\n";
                        }
                    }
                    break;
                    
                case 'LIST':
                    $list_type = ($node['listData']['type'] ?? 'UNORDERED') === 'ORDERED' ? 'ol' : 'ul';
                    $list_content = $this->parse_rich_content_nodes($node['nodes'] ?? array());
                    if (!empty(trim($list_content))) {
                        $content .= '<' . $list_type . '>' . $list_content . '</' . $list_type . '>' . "\n";
                    }
                    break;
                    
                case 'LIST_ITEM':
                    $item_content = $this->parse_rich_content_nodes($node['nodes'] ?? array());
                    if (!empty(trim($item_content))) {
                        $content .= '<li>' . $item_content . '</li>' . "\n";
                    }
                    break;
                    
                default:
                    // Handle unknown node types by processing child nodes
                    if (isset($node['nodes']) && is_array($node['nodes'])) {
                        $content .= $this->parse_rich_content_nodes($node['nodes']);
                    }
                    break;
            }
        }
        
        return $content;
    }
    
    private function apply_text_decorations($text, $decorations) {
        $formatted_text = esc_html($text);
        
        if (!is_array($decorations)) {
            return $formatted_text;
        }
        
        $link_url = '';
        $link_target = '';
        $styles = array();
        
        foreach ($decorations as $decoration) {
            if (!is_array($decoration) || !isset($decoration['type'])) {
                continue;
            }
            
            switch ($decoration['type']) {
                case 'BOLD':
                    $formatted_text = '<strong>' . $formatted_text . '</strong>';
                    break;
                    
                case 'ITALIC':
                    $formatted_text = '<em>' . $formatted_text . '</em>';
                    break;
                    
                case 'UNDERLINE':
                    $styles[] = 'text-decoration: underline';
                    break;
                    
                case 'COLOR':
                    if (isset($decoration['colorData']['foreground'])) {
                        $color = sanitize_hex_color($decoration['colorData']['foreground']);
                        if ($color) {
                            $styles[] = 'color: ' . $color;
                        }
                    }
                    if (isset($decoration['colorData']['background'])) {
                        $bg_color = sanitize_hex_color($decoration['colorData']['background']);
                        if ($bg_color && $bg_color !== '#ffffff') { // Don't add white background
                            $styles[] = 'background-color: ' . $bg_color;
                        }
                    }
                    break;
                    
                case 'LINK':
                    if (isset($decoration['linkData']['link']['url'])) {
                        $link_url = esc_url($decoration['linkData']['link']['url']);
                        $link_target = ($decoration['linkData']['link']['target'] ?? '') === 'BLANK' ? '_blank' : '';
                    }
                    break;
            }
        }
        
        // Apply inline styles
        if (!empty($styles)) {
            $style_attr = implode('; ', $styles);
            $formatted_text = '<span style="' . esc_attr($style_attr) . '">' . $formatted_text . '</span>';
        }
        
        // Apply link wrapper
        if (!empty($link_url)) {
            $target_attr = !empty($link_target) ? ' target="' . esc_attr($link_target) . '" rel="noopener noreferrer"' : '';
            $formatted_text = '<a href="' . $link_url . '"' . $target_attr . '>' . $formatted_text . '</a>';
        }
        
        return $formatted_text;
    }
    
    private function convert_wix_date($wix_date) {
        if (empty($wix_date)) {
            return current_time('mysql');
        }
        
        return date('Y-m-d H:i:s', strtotime($wix_date));
    }
    
    private function set_featured_image($post_id, $image_url) {
        if (empty($image_url)) {
            return false;
        }
        
        // Check if image already exists
        $existing_attachment = $this->get_attachment_by_url($image_url);
        if ($existing_attachment) {
            set_post_thumbnail($post_id, $existing_attachment);
            return $existing_attachment;
        }
        
        $attach_id = $this->download_and_attach_image($image_url, $post_id);
        
        if ($attach_id && !is_wp_error($attach_id)) {
            set_post_thumbnail($post_id, $attach_id);
            return $attach_id;
        }
        
        return false;
    }
    
    private function extract_cover_image_url($cover_media) {
        if (!is_array($cover_media)) {
            return '';
        }
        
        // Try different possible structures for cover media
        $possible_paths = array(
            'url',
            'src', 
            'image.url',
            'image.src'
        );
        
        foreach ($possible_paths as $path) {
            $parts = explode('.', $path);
            $current = $cover_media;
            
            foreach ($parts as $part) {
                if (is_array($current) && isset($current[$part])) {
                    $current = $current[$part];
                } else {
                    $current = null;
                    break;
                }
            }
            
            if (is_string($current) && !empty($current) && filter_var($current, FILTER_VALIDATE_URL)) {
                return $current;
            }
        }
        
        return '';
    }
    
    private function extract_image_url($image_data) {
        if (!is_array($image_data)) {
            return '';
        }
        
        // Try different possible structures from Wix API
        $possible_paths = array(
            'image.src',
            'image.url', 
            'src',
            'url',
            'image'
        );
        
        foreach ($possible_paths as $path) {
            $parts = explode('.', $path);
            $current = $image_data;
            
            foreach ($parts as $part) {
                if (is_array($current) && isset($current[$part])) {
                    $current = $current[$part];
                } else {
                    $current = null;
                    break;
                }
            }
            
            if (is_string($current) && !empty($current) && filter_var($current, FILTER_VALIDATE_URL)) {
                return $current;
            }
        }
        
        // Handle Wix image ID format: image.src.id
        if (isset($image_data['image']['src']['id']) && is_string($image_data['image']['src']['id'])) {
            $image_id = $image_data['image']['src']['id'];
            // Convert Wix image ID to URL format
            // Wix images are typically served from static.wixstatic.com
            $wix_image_url = "https://static.wixstatic.com/media/$image_id";
            return $wix_image_url;
        }
        
        // Handle other Wix ID formats
        if (isset($image_data['src']['id']) && is_string($image_data['src']['id'])) {
            $image_id = $image_data['src']['id'];
            $wix_image_url = "https://static.wixstatic.com/media/$image_id";
            return $wix_image_url;
        }
        
        // Handle direct ID format
        if (isset($image_data['id']) && is_string($image_data['id'])) {
            $image_id = $image_data['id'];
            $wix_image_url = "https://static.wixstatic.com/media/$image_id";
            return $wix_image_url;
        }
        
        return '';
    }
    
    private function download_and_attach_image($image_url, $post_id = 0, $retry_count = 0) {
        // Validate URL first
        if (!is_string($image_url) || empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', 'Invalid image URL provided: ' . var_export($image_url, true));
        }
        
        // Check if this image was already downloaded before
        $existing_attachment = $this->get_attachment_by_url($image_url);
        if ($existing_attachment) {
            return $existing_attachment;
        }
        
        $max_retries = 3;
        $retry_delay = 1; // seconds
        
        // Use WordPress HTTP API with retry mechanism
        $response = wp_remote_get($image_url, array(
            'timeout' => 45, // Increased timeout
            'redirection' => 5,
            'user-agent' => 'Mozilla/5.0 (WordPress Wix Migrator) AppleWebKit/537.36',
            'headers' => array(
                'Accept' => 'image/*,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache'
            )
        ));
        
        if (is_wp_error($response)) {
            if ($retry_count < $max_retries) {
                error_log("Wix Migration: Image download failed (attempt " . ($retry_count + 1) . "): " . $response->get_error_message() . ". Retrying in {$retry_delay}s...");
                sleep($retry_delay);
                return $this->download_and_attach_image($image_url, $post_id, $retry_count + 1);
            }
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            if ($retry_count < $max_retries && in_array($response_code, [403, 429, 500, 502, 503, 504])) {
                error_log("Wix Migration: HTTP $response_code for image (attempt " . ($retry_count + 1) . "). Retrying in {$retry_delay}s...");
                sleep($retry_delay);
                return $this->download_and_attach_image($image_url, $post_id, $retry_count + 1);
            }
            return new WP_Error('image_download_failed', 'Failed to download image: HTTP ' . $response_code);
        }
        
        $image_data = wp_remote_retrieve_body($response);
        if (empty($image_data)) {
            return new WP_Error('image_download_failed', 'Empty image data received');
        }
        
        // Validate content type
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        $valid_image_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        
        if ($content_type && !in_array(strtolower($content_type), $valid_image_types)) {
            // Check if it's a generic content type that might still be an image
            if (!in_array($content_type, ['application/octet-stream', 'binary/octet-stream'])) {
                return new WP_Error('invalid_content_type', 'Invalid image content type: ' . $content_type);
            }
        }
        
        // Validate image data by checking file signature
        $image_signatures = [
            "\xFF\xD8\xFF" => 'jpg',    // JPEG
            "\x89PNG\r\n\x1A\n" => 'png', // PNG
            "GIF87a" => 'gif',          // GIF87a
            "GIF89a" => 'gif',          // GIF89a
            "RIFF" => 'webp'            // WebP (partial check)
        ];
        
        $is_valid_image = false;
        foreach ($image_signatures as $signature => $type) {
            if (substr($image_data, 0, strlen($signature)) === $signature) {
                $is_valid_image = true;
                break;
            }
        }
        
        if (!$is_valid_image && strlen($image_data) > 100) {
            return new WP_Error('invalid_image_data', 'Downloaded data does not appear to be a valid image');
        }
        
        // Get file info
        $filename = $this->generate_unique_filename($image_url);
        $upload_dir = wp_upload_dir();
        
        if ($upload_dir['error']) {
            return new WP_Error('upload_dir_error', 'Upload directory error: ' . $upload_dir['error']);
        }
        
        $file_path = $upload_dir['path'] . '/' . $filename;
        
        // Save file
        $saved = file_put_contents($file_path, $image_data);
        if ($saved === false) {
            return new WP_Error('file_save_failed', 'Failed to save image file');
        }
        
        // Verify file was saved correctly
        if (!file_exists($file_path) || filesize($file_path) !== strlen($image_data)) {
            return new WP_Error('file_verification_failed', 'File save verification failed');
        }
        
        // Check file type
        $filetype = wp_check_filetype($filename, null);
        if (!$filetype['type']) {
            unlink($file_path); // Clean up
            return new WP_Error('invalid_file_type', 'Invalid image file type');
        }
        
        // Create attachment
        $attachment = array(
            'post_mime_type' => $filetype['type'],
            'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
            'meta_input' => array(
                'wix_source_url' => $image_url
            )
        );
        
        $attach_id = wp_insert_attachment($attachment, $file_path, $post_id);
        
        if (is_wp_error($attach_id)) {
            unlink($file_path); // Clean up
            return $attach_id;
        }
        
        // Generate thumbnails
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
        wp_update_attachment_metadata($attach_id, $attach_data);
        
        return $attach_id;
    }
    
    private function generate_unique_filename($image_url) {
        $parsed_url = parse_url($image_url);
        $path_info = pathinfo($parsed_url['path'] ?? '');
        
        $filename = $path_info['filename'] ?? 'wix_image_' . time();
        $extension = $path_info['extension'] ?? 'jpg';
        
        // Sanitize filename
        $filename = sanitize_file_name($filename);
        
        // Ensure unique filename
        $upload_dir = wp_upload_dir();
        $counter = 1;
        $original_filename = $filename;
        
        while (file_exists($upload_dir['path'] . '/' . $filename . '.' . $extension)) {
            $filename = $original_filename . '_' . $counter;
            $counter++;
        }
        
        return $filename . '.' . $extension;
    }
    
    private function is_valid_html($html) {
        if (empty($html) || !is_string($html)) {
            return false;
        }
        
        // For plain text without HTML tags, consider it valid
        if (strip_tags($html) === $html) {
            return true;
        }
        
        // Check for obvious malformed patterns first
        $html_lower = strtolower(trim($html));
        
        // Check for incomplete tags like "<>" or "</"
        if (preg_match('/<\s*>|<\s*\/\s*>|<\s*\w+\s*[^>]*$/', $html)) {
            return false;
        }
        
        // Basic validation for critical tags only (more lenient)
        $critical_tags = ['script', 'style']; // Only check truly critical tags
        foreach ($critical_tags as $tag) {
            $open_count = substr_count($html_lower, "<$tag");
            $close_count = substr_count($html_lower, "</$tag");
            
            // Only reject if there's a significant imbalance (script/style must be properly closed)
            if ($open_count !== $close_count) {
                return false;
            }
        }
        
        // Use DOMDocument for more thorough validation
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        
        // Try to load the HTML
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        // Get any errors
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        
        // If loading failed, consider invalid
        if (!$loaded) {
            return false;
        }
        
        // Check for fatal XML/HTML errors only (ignore warnings and minor errors)
        foreach ($errors as $error) {
            if ($error->level === LIBXML_ERR_FATAL) {
                // Only reject if it's a truly critical error that would break parsing
                if (strpos($error->message, 'parser error') !== false || 
                    strpos($error->message, 'not well-formed') !== false) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    private function get_attachment_by_url($image_url) {
        global $wpdb;
        
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'wix_source_url' AND meta_value = %s",
            $image_url
        ));
        
        return $attachment_id ? intval($attachment_id) : false;
    }
    
    private function get_wp_id_by_wix_id($wix_id, $content_type) {
        global $wpdb;
        
        $wp_id = $wpdb->get_var($wpdb->prepare(
            "SELECT wp_id FROM {$this->mapping_table} WHERE wix_id = %s AND content_type = %s",
            $wix_id,
            $content_type
        ));
        
        return $wp_id ? intval($wp_id) : false;
    }
    
    private function save_mapping($wix_id, $wp_id, $content_type) {
        global $wpdb;
        
        $wpdb->replace(
            $this->mapping_table,
            array(
                'wix_id' => $wix_id,
                'wp_id' => $wp_id,
                'content_type' => $content_type
            ),
            array('%s', '%d', '%s')
        );
    }
}