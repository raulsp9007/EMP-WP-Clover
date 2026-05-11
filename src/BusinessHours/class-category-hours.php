<?php
/**
 * Category-Specific Business Hours
 * Allows setting custom business hours per product category (optional)
 * Store-wide hours remain the default
 */

namespace Src\BusinessHours;

if (!defined('ABSPATH')) {
    exit;
}

class Category_Hours {

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Add fields to product category edit page
        add_action('product_cat_add_form_fields', array($this, 'add_category_hours_fields'));
        add_action('product_cat_edit_form_fields', array($this, 'edit_category_hours_fields'));

        // Save category hours
        add_action('created_product_cat', array($this, 'save_category_hours'));
        add_action('edited_product_cat', array($this, 'save_category_hours'));
        
        // Enqueue scripts for category hours UI
        add_action('admin_enqueue_scripts', array($this, 'enqueue_category_hours_scripts'));
    }

    /**
     * Enqueue scripts for category hours UI
     */
    public function enqueue_category_hours_scripts($hook) {
        if ($hook === 'edit-tags.php' || $hook === 'term.php') {
            wp_enqueue_script('jquery');
            $script = '
                jQuery(document).ready(function($) {
                    // Add interval row
                    $(document).on("click", ".add-interval-btn", function(e) {
                        e.preventDefault();
                        var day = $(this).data("day");
                        var row = \'<div class="interval-row">\' +
                            \'<input type="time" name="category_hours[\' + day + \'][start][]" class="interval-start" required>\' +
                            \'<span> - </span>\' +
                            \'<input type="time" name="category_hours[\' + day + \'][end][]" class="interval-end" required>\' +
                            \'<button type="button" class="remove-interval-btn button">×</button>\' +
                            \'</div>\';
                        $(this).siblings(".intervals-container").append(row);
                    });

                    // Remove interval row
                    $(document).on("click", ".remove-interval-btn", function() {
                        $(this).parent().remove();
                    });
                });
            ';
            wp_add_inline_script('jquery', $script);
        }
    }

    /**
     * Render the hours UI for a specific day
     */
    private function render_day_hours($day, $intervals = array()) {
        $day_name = ucfirst($day);
        ?>
        <div class="category-hours-day">
            <h4><?php echo $day_name; ?></h4>
            <div class="intervals-container">
                <?php if (empty($intervals)) : ?>
                    <div class="interval-row">
                        <input type="time" name="category_hours[<?php echo $day; ?>][start][]" class="interval-start">
                        <span> - </span>
                        <input type="time" name="category_hours[<?php echo $day; ?>][end][]" class="interval-end">
                        <button type="button" class="remove-interval-btn button">×</button>
                    </div>
                <?php else : ?>
                    <?php foreach ($intervals as $interval) : ?>
                        <div class="interval-row">
                            <input type="time" name="category_hours[<?php echo $day; ?>][start][]" class="interval-start" value="<?php echo esc_attr($interval['start']); ?>" required>
                            <span> - </span>
                            <input type="time" name="category_hours[<?php echo $day; ?>][end][]" class="interval-end" value="<?php echo esc_attr($interval['end']); ?>" required>
                            <button type="button" class="remove-interval-btn button">×</button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <button type="button" class="add-interval-btn button" data-day="<?php echo $day; ?>">+ Add Interval</button>
        </div>
        <?php
    }

    /**
     * Add hours fields when creating new category
     */
    public function add_category_hours_fields() {
        ?>
        <div class="form-field">
            <label for="category_hours_enabled"><?php _e('Enable Category Hours', 'woocommerce'); ?></label>
            <select name="category_hours_enabled" id="category_hours_enabled">
                <option value="no"><?php _e('No - Use Store Hours', 'woocommerce'); ?></option>
                <option value="yes"><?php _e('Yes - Use Category Hours', 'woocommerce'); ?></option>
            </select>
            <p class="description"><?php _e('If enabled, this category will use its own hours instead of store hours.', 'woocommerce'); ?></p>
        </div>
        <div class="form-field">
            <label><?php _e('Opening Hours', 'woocommerce'); ?></label>
            <p class="description"><?php _e('Set specific business hours for this category. Leave empty to use default store hours.', 'woocommerce'); ?></p>
            <div class="category-hours-wrapper">
                <?php
                $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                foreach ($days as $day) {
                    $this->render_day_hours($day);
                }
                ?>
            </div>
        </div>
        <style>
            .category-hours-wrapper { background: #fff; padding: 15px; border: 1px solid #ddd; }
            .category-hours-day { margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
            .category-hours-day h4 { margin: 0 0 10px 0; }
            .interval-row { display: flex; align-items: center; gap: 5px; margin-bottom: 5px; }
            .interval-row input[type="time"] { padding: 5px; }
            .remove-interval-btn { padding: 5px 10px; margin-left: 5px; }
        </style>
        <?php
    }

    /**
     * Edit hours fields when editing existing category
     */
    public function edit_category_hours_fields($term) {
        $term_id = $term->term_id;
        $hours_json = get_term_meta($term_id, 'category_opening_hours', true);
        $enabled = get_term_meta($term_id, 'category_hours_enabled', true);
        
        // Parse existing hours
        $parsed_hours = array();
        if (!empty($hours_json)) {
            $data = json_decode($hours_json, true);
            if (isset($data['elements'][0])) {
                $parsed_hours = $data['elements'][0];
            }
        }

        // Helper to format time for input
        $format_time = function($val) {
            $val = (int)$val;
            $h = floor($val / 100);
            $m = $val % 100;
            return sprintf('%02d:%02d', $h, $m);
        };
        ?>
        <tr class="form-field">
            <th scope="row" valign="top">
                <label for="category_hours_enabled"><?php _e('Enable Category Hours', 'woocommerce'); ?></label>
            </th>
            <td>
                <select name="category_hours_enabled" id="category_hours_enabled">
                    <option value="no" <?php selected($enabled, 'no'); ?>><?php _e('No - Use Store Hours', 'woocommerce'); ?></option>
                    <option value="yes" <?php selected($enabled, 'yes'); ?>><?php _e('Yes - Use Category Hours', 'woocommerce'); ?></option>
                </select>
                <p class="description"><?php _e('If enabled, this category will use its own hours instead of store hours.', 'woocommerce'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row" valign="top">
                <label><?php _e('Opening Hours', 'woocommerce'); ?></label>
            </th>
            <td>
                <p class="description"><?php _e('Set specific business hours for this category. Leave empty to use default store hours.', 'woocommerce'); ?></p>
                <div class="category-hours-wrapper">
                    <?php
                    $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                    foreach ($days as $day) {
                        $intervals = array();
                        if (isset($parsed_hours[$day]['elements'])) {
                            foreach ($parsed_hours[$day]['elements'] as $el) {
                                $intervals[] = array(
                                    'start' => $format_time($el['start']),
                                    'end' => $format_time($el['end'])
                                );
                            }
                        }
                        $this->render_day_hours($day, $intervals);
                    }
                    ?>
                </div>
            </td>
        </tr>
        <style>
            .category-hours-wrapper { background: #fff; padding: 15px; border: 1px solid #ddd; }
            .category-hours-day { margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
            .category-hours-day h4 { margin: 0 0 10px 0; }
            .interval-row { display: flex; align-items: center; gap: 5px; margin-bottom: 5px; }
            .interval-row input[type="time"] { padding: 5px; }
            .remove-interval-btn { padding: 5px 10px; margin-left: 5px; }
        </style>
        <?php
    }

    /**
     * Save category hours
     */
    public function save_category_hours($term_id) {
        if (isset($_POST['category_hours'])) {
            $hours_data = array('elements' => array());
            $day_elements = array();
            
            $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            foreach ($days as $day) {
                if (isset($_POST['category_hours'][$day]['start']) && isset($_POST['category_hours'][$day]['end'])) {
                    $starts = $_POST['category_hours'][$day]['start'];
                    $ends = $_POST['category_hours'][$day]['end'];
                    
                    $elements = array();
                    for ($i = 0; $i < count($starts); $i++) {
                        if (!empty($starts[$i]) && !empty($ends[$i])) {
                            // Convert HH:MM to integer (e.g., 09:00 -> 900)
                            $start_parts = explode(':', $starts[$i]);
                            $end_parts = explode(':', $ends[$i]);
                            
                            $start_int = (int)$start_parts[0] * 100 + (int)$start_parts[1];
                            $end_int = (int)$end_parts[0] * 100 + (int)$end_parts[1];
                            
                            $elements[] = array('start' => $start_int, 'end' => $end_int);
                        }
                    }
                    
                    if (!empty($elements)) {
                        $day_elements[$day] = array('elements' => $elements);
                    }
                }
            }
            
            if (!empty($day_elements)) {
                $hours_data['elements'][] = $day_elements;
                update_term_meta($term_id, 'category_opening_hours', wp_json_encode($hours_data));
            } else {
                delete_term_meta($term_id, 'category_opening_hours');
            }
        }

        if (isset($_POST['category_hours_enabled'])) {
            update_term_meta($term_id, 'category_hours_enabled', sanitize_text_field($_POST['category_hours_enabled']));
        } else {
            update_term_meta($term_id, 'category_hours_enabled', 'no');
        }
    }

    /**
     * Get category hours for a specific category
     * Returns false if category uses store hours
     */
    public function get_category_hours($category_id) {
        $enabled = get_term_meta($category_id, 'category_hours_enabled', true);

        if ($enabled !== 'yes') {
            return false; // Use store hours
        }

        $hours_json = get_term_meta($category_id, 'category_opening_hours', true);

        if (empty($hours_json)) {
            return false; // No hours set, use store hours
        }

        $hours = json_decode($hours_json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return false; // Invalid JSON, use store hours
        }

        return $hours;
    }

    /**
     * Check if a category has custom hours enabled
     */
    public function has_category_hours($category_id) {
        $enabled = get_term_meta($category_id, 'category_hours_enabled', true);
        $hours = get_term_meta($category_id, 'category_opening_hours', true);

        return ($enabled === 'yes' && !empty($hours));
    }
}