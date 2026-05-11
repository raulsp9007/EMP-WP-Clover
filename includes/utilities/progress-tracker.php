<?php
/**
 * Progress Tracker Utility
 * Handles real-time progress tracking for import processes using WordPress transients
 */

if (!defined('ABSPATH')) {
    exit;
}

class Clover_Progress_Tracker {
    
    private $process_id;
    private $transient_key;
    
    public function __construct($process_id = null) {
        $this->process_id = $process_id ?: uniqid('clover_import_', true);
        $this->transient_key = 'clover_import_progress_' . $this->process_id;
    }
    
    /**
     * Get the process ID
     */
    public function get_process_id() {
        return $this->process_id;
    }
    
    /**
     * Initialize the progress tracking
     */
    public function init($total_items, $initial_status = 'Initializing...') {
        $progress_data = array(
            'total_items' => (int) $total_items,
            'processed_items' => 0,
            'current_item' => null,
            'status' => $initial_status,
            'items_processed' => array(),
            'errors' => array(),
            'start_time' => time(),
            'last_update' => time(),
            'phase' => 'importing_products', // Phase 1: importing_products, Phase 2: importing_images
            'images_processed' => 0,
            'images_imported_success' => 0 // Count only successfully imported images
        );

        set_transient($this->transient_key, $progress_data, 30 * MINUTE_IN_SECONDS);

        return $progress_data;
    }
    
    /**
     * Update progress for a specific item
     */
    public function update_progress($item_id, $item_name, $status = 'processing', $message = '') {
        $progress_data = $this->get_progress();
        
        if (!$progress_data) {
            return false;
        }
        
        // Update current item
        $progress_data['current_item'] = array(
            'id' => $item_id,
            'name' => $item_name,
            'status' => $status,
            'message' => $message
        );
        
        // Increment processed count
        $progress_data['processed_items']++;
        
        // Add to items processed list
        $progress_data['items_processed'][] = array(
            'id' => $item_id,
            'name' => $item_name,
            'status' => $status,
            'message' => $message,
            'timestamp' => time()
        );
        
        // Update status
        $progress_data['status'] = sprintf(
            'Processing... %d of %d items (%d%%)',
            $progress_data['processed_items'],
            $progress_data['total_items'],
            $progress_data['total_items'] > 0 ? round(($progress_data['processed_items'] / $progress_data['total_items']) * 100) : 0
        );
        
        $progress_data['last_update'] = time();
        
        set_transient($this->transient_key, $progress_data, 30 * MINUTE_IN_SECONDS);
        
        return $progress_data;
    }
    
    /**
     * Record an error for a specific item
     */
    public function record_error($item_id, $item_name, $error_message) {
        $progress_data = $this->get_progress();
        
        if (!$progress_data) {
            return false;
        }
        
        // Add error to the errors array
        $progress_data['errors'][] = array(
            'id' => $item_id,
            'name' => $item_name,
            'error' => $error_message,
            'timestamp' => time()
        );
        
        // Update current item status
        $progress_data['current_item'] = array(
            'id' => $item_id,
            'name' => $item_name,
            'status' => 'error',
            'message' => $error_message
        );
        
        // Still increment processed count
        $progress_data['processed_items']++;
        
        // Add to items processed list
        $progress_data['items_processed'][] = array(
            'id' => $item_id,
            'name' => $item_name,
            'status' => 'error',
            'message' => $error_message,
            'timestamp' => time()
        );
        
        // Update status
        $progress_data['status'] = sprintf(
            'Processing... %d of %d items (%d%%)',
            $progress_data['processed_items'],
            $progress_data['total_items'],
            $progress_data['total_items'] > 0 ? round(($progress_data['processed_items'] / $progress_data['total_items']) * 100) : 0
        );
        
        $progress_data['last_update'] = time();
        
        set_transient($this->transient_key, $progress_data, 30 * MINUTE_IN_SECONDS);
        
        return $progress_data;
    }
    
    /**
     * Get current progress data
     */
    public function get_progress() {
        $progress_data = get_transient($this->transient_key);
        
        // Check if the process has timed out (more than 30 minutes since last update)
        if ($progress_data && isset($progress_data['last_update'])) {
            $time_since_last_update = time() - $progress_data['last_update'];
            if ($time_since_last_update > 30 * MINUTE_IN_SECONDS) {
                // Mark as timed out
                $progress_data['timed_out'] = true;
                $progress_data['status'] = 'Process timed out. Please restart the import.';
                
                // Update the transient with timeout status
                set_transient($this->transient_key, $progress_data, 5 * MINUTE_IN_SECONDS); // Shorter timeout for cleanup
                
                return $progress_data;
            }
        }
        
        return $progress_data;
    }
    
    /**
     * Complete the process and store final results
     */
    public function complete($final_message = 'Import completed successfully') {
        $progress_data = $this->get_progress();
        
        if (!$progress_data) {
            return false;
        }
        
        $progress_data['status'] = $final_message;
        $progress_data['completed'] = true;
        $progress_data['end_time'] = time();
        $progress_data['duration'] = $progress_data['end_time'] - $progress_data['start_time'];
        
        // Update the transient with completion status
        set_transient($this->transient_key, $progress_data, 30 * MINUTE_IN_SECONDS);
        
        // Clean up after a certain time
        wp_schedule_single_event(time() + 30 * MINUTE_IN_SECONDS, 'clover_cleanup_progress_transient', array($this->transient_key));
        
        return $progress_data;
    }
    
    /**
     * Cancel the process
     */
    public function cancel($cancel_message = 'Import cancelled') {
        $progress_data = $this->get_progress();
        
        if (!$progress_data) {
            return false;
        }
        
        $progress_data['status'] = $cancel_message;
        $progress_data['cancelled'] = true;
        $progress_data['end_time'] = time();
        $progress_data['duration'] = $progress_data['end_time'] - $progress_data['start_time'];
        
        set_transient($this->transient_key, $progress_data, 30 * MINUTE_IN_SECONDS);
        
        // Clean up
        wp_schedule_single_event(time() + 30 * MINUTE_IN_SECONDS, 'clover_cleanup_progress_transient', array($this->transient_key));
        
        return $progress_data;
    }
    
    /**
     * Get the last N activity logs
     */
    public function get_recent_activities($count = 8) {
        $progress_data = $this->get_progress();
        
        if (!$progress_data || empty($progress_data['items_processed'])) {
            return array();
        }
        
        // Return the last N items from the items_processed array
        $activities = array_slice($progress_data['items_processed'], -$count, $count);
        return array_reverse($activities); // Reverse to show most recent first
    }
    
    /**
     * Calculate progress percentage
     */
    public function get_percentage() {
        $progress_data = $this->get_progress();
        
        if (!$progress_data || $progress_data['total_items'] <= 0) {
            return 0;
        }
        
        return round(($progress_data['processed_items'] / $progress_data['total_items']) * 100);
    }
    
    /**
     * Update progress for a specific item with detailed steps
     */
    public function update_detailed_progress($item_id, $item_name, $step, $status = 'processing', $message = '') {
        $progress_data = $this->get_progress();

        if (!$progress_data) {
            return false;
        }

        // Update current item with step information
        $progress_data['current_item'] = array(
            'id' => $item_id,
            'name' => $item_name,
            'step' => $step,
            'status' => $status,
            'message' => $message,
            'timestamp' => time()
        );

        // Always add a new entry to show complete history (cumulative logging)
        $progress_data['items_processed'][] = array(
            'id' => $item_id,
            'name' => $item_name,
            'step' => $step,
            'status' => $status,
            'message' => $message,
            'timestamp' => time()
        );

        // Update status with more detailed information
        $percentage = $progress_data['total_items'] > 0 ? round(($progress_data['processed_items'] / $progress_data['total_items']) * 100) : 0;
        $remaining = $progress_data['total_items'] - $progress_data['processed_items'];

        $progress_data['status'] = sprintf(
            'Step: %s - %s - Current: %s',
            $step,
            $message,
            $item_name
        );

        $progress_data['percentage'] = $percentage;
        $progress_data['remaining'] = $remaining;
        $progress_data['last_update'] = time();

        set_transient($this->transient_key, $progress_data, 30 * MINUTE_IN_SECONDS);

        return $progress_data;
    }
    
    /**
     * Update progress for a specific item and increment the processed items counter (for final step)
     */
    public function update_detailed_progress_final($item_id, $item_name, $step, $status = 'success', $message = '') {
        $progress_data = $this->get_progress();
        
        if (!$progress_data) {
            return false;
        }
        
        // Update current item with step information
        $progress_data['current_item'] = array(
            'id' => $item_id,
            'name' => $item_name,
            'step' => $step,
            'status' => $status,
            'message' => $message,
            'timestamp' => time()
        );
        
        // Add to items processed list with step information
        $progress_data['items_processed'][] = array(
            'id' => $item_id,
            'name' => $item_name,
            'step' => $step,
            'status' => $status,
            'message' => $message,
            'timestamp' => time()
        );
        
        // ONLY increment the processed count for the final step
        $progress_data['processed_items']++;
        
        // Update status with more detailed information
        $percentage = $progress_data['total_items'] > 0 ? round(($progress_data['processed_items'] / $progress_data['total_items']) * 100) : 0;
        $remaining = $progress_data['total_items'] - $progress_data['processed_items'];
        
        $progress_data['status'] = sprintf(
            'Step: %s - %s - Current: %s',
            $step,
            $message,
            $item_name
        );
        
        $progress_data['percentage'] = $percentage;
        $progress_data['remaining'] = $remaining;
        $progress_data['last_update'] = time();
        
        set_transient($this->transient_key, $progress_data, 30 * MINUTE_IN_SECONDS);

        return $progress_data;
    }

    /**
     * Get the current phase
     */
    public function get_phase() {
        $progress_data = $this->get_progress();
        return $progress_data['phase'] ?? 'importing_products';
    }

    /**
     * Set the current phase
     */
    public function set_phase($phase) {
        $progress_data = $this->get_progress();

        if (!$progress_data) {
            return false;
        }

        $progress_data['phase'] = $phase;
        $progress_data['last_update'] = time();

        set_transient($this->transient_key, $progress_data, 30 * MINUTE_IN_SECONDS);

        return $progress_data;
    }

    /**
     * Update image processing progress
     */
    public function update_image_progress($item_id, $item_name, $step, $status = 'processing', $message = '') {
        $progress_data = $this->get_progress();

        if (!$progress_data) {
            return false;
        }

        // Update current item with step information
        $progress_data['current_item'] = array(
            'id' => $item_id,
            'name' => $item_name,
            'step' => $step,
            'status' => $status,
            'message' => $message,
            'timestamp' => time()
        );

        // Increment images processed count
        $progress_data['images_processed']++;
        
        // Increment success counter only for successful imports
        if ($status === 'success') {
            $progress_data['images_imported_success']++;
        }

        // Add to items processed list with step information
        $progress_data['items_processed'][] = array(
            'id' => $item_id,
            'name' => $item_name,
            'step' => $step,
            'status' => $status,
            'message' => $message,
            'timestamp' => time()
        );

        // Update status with more detailed information
        $total_items = $progress_data['total_items'];
        $products_done = $progress_data['processed_items'];
        $images_done = $progress_data['images_processed'];
        $images_success = $progress_data['images_imported_success'];
        $total_steps = $total_items * 2; // Products + Images
        $current_step = $products_done + $images_done;
        $percentage = $total_steps > 0 ? round(($current_step / $total_steps) * 100) : 0;

        $progress_data['status'] = sprintf(
            'Phase 2: Importing images - %d of %d (%d%%) - Success: %d',
            $images_done,
            $total_items,
            $percentage,
            $images_success
        );

        $progress_data['percentage'] = $percentage;
        $progress_data['remaining'] = $total_items - $images_done;
        $progress_data['last_update'] = time();

        set_transient($this->transient_key, $progress_data, 30 * MINUTE_IN_SECONDS);

        return $progress_data;
    }
}

// Schedule cleanup event
if (!wp_next_scheduled('clover_cleanup_progress_transient')) {
    wp_schedule_event(time(), 'hourly', 'clover_cleanup_progress_transient');
}

// Cleanup function for expired transients
function clover_cleanup_expired_progress_transients() {
    global $wpdb;
    
    // WordPress doesn't provide a direct way to list all transients
    // So we'll clean up based on our naming convention
    $transients = $wpdb->get_col("
        SELECT option_name 
        FROM {$wpdb->options} 
        WHERE option_name LIKE '_transient_timeout_clover_import_progress_%'
        AND option_value < UNIX_TIMESTAMP()
    ");
    
    foreach ($transients as $transient) {
        $transient_name = str_replace('_transient_timeout_', '_transient_', $transient);
        delete_option($transient_name);
        delete_option($transient);
    }
}

add_action('clover_cleanup_progress_transient', 'clover_cleanup_expired_progress_transients');