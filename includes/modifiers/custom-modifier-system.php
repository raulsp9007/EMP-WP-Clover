<?php

/**
 * Custom Modifier System for Clover Integration
 *
 * This system replicates YITH add-ons functionality but includes a field to store Clover modifier IDs
 */
if (!defined('ABSPATH')) {
    exit;
}

class Custom_Modifier_System
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'init'));
    }

    public function init()
    {
        // Add custom fields to products
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_custom_fields'));
        add_action('woocommerce_process_product_meta', array($this, 'save_custom_fields'));

        // Display modifiers on product page
        add_action('woocommerce_before_add_to_cart_button', array($this, 'display_modifiers'));

        // Process modifiers during cart addition
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_modifiers_to_cart'), 10, 3);

        // Update cart item price based on modifiers - run at priority 1 BEFORE any filters
        add_action('woocommerce_before_calculate_totals', array($this, 'update_cart_item_price'), 1, 1);

        // Display modifiers in cart
        add_filter('woocommerce_get_item_data', array($this, 'display_modifiers_in_cart'), 10, 2);

        // Ensure custom price persists when loading cart from session
        add_filter('woocommerce_get_cart_item_from_session', array($this, 'restore_cart_item_price'), 20, 2);

        // Add modifier data to order items
        add_action('woocommerce_new_order_item', array($this, 'add_modifier_data_to_order'), 10, 3);
    }

    /**
     * Add custom fields to product edit page
     */
    public function add_custom_fields()
    {
        global $post;

        // Get existing modifiers value
        $existing_modifiers = get_post_meta($post->ID, '_clover_modifiers', true);
        if (empty($existing_modifiers)) {
            $existing_modifiers = '{}';
        } else {
            // Decode and re-encode to ensure proper display of quotes
            $modifiers_decoded = json_decode($existing_modifiers, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $existing_modifiers = json_encode($modifiers_decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }

        // Get servings count (for multi-portion products like "2 pizzas in 1 order")
        $servings_count = get_post_meta($post->ID, '_clover_servings', true);
        if (empty($servings_count)) {
            $servings_count = 1;
        }

        // Get modifier constraints
        $existing_constraints = get_post_meta($post->ID, '_clover_modifier_constraints', true);
        if (empty($existing_constraints)) {
            $existing_constraints = '{}';
        } else {
            // Decode and re-encode to ensure proper display of quotes
            $constraints_decoded = json_decode($existing_constraints, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $existing_constraints = json_encode($constraints_decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }

        echo '<div class="options_group">';

        // Servings count field
        echo '<p class="form-field">';
        echo '<label for="clover_servings">Clover Servings Count</label>';
        echo '<input type="number" id="clover_servings" name="clover_servings" class="short" value="' . esc_attr($servings_count) . '" min="1" step="1" />';
        echo '<span class="description">Number of portions/items this product contains (e.g., 2 for a "2 pizzas" combo). Each portion can have independent modifiers.</span>';
        echo '</p>';

        echo '<p class="form-field">';
        echo '<label for="clover_modifiers">Clover Modifiers</label>';
        echo '<textarea id="clover_modifiers" name="clover_modifiers" class="short" cols="50" rows="5" placeholder="Enter modifiers in JSON format">' . esc_textarea($existing_modifiers) . '</textarea>';
        echo '<span class="description">Enter modifiers in JSON format with ID, name, price, and clover_id</span>';
        echo '</p>';

        echo '<p class="form-field">';
        echo '<label for="clover_modifier_constraints">Clover Modifier Constraints</label>';
        echo '<textarea id="clover_modifier_constraints" name="clover_modifier_constraints" class="short" cols="50" rows="4" placeholder="Enter modifier constraints in JSON format">' . esc_textarea($existing_constraints) . '</textarea>';
        echo '<span class="description">Modifier group constraints (minRequired, maxAllowed) imported from Clover. Read-only - updated during import.</span>';
        echo '</p>';

        echo '</div>';
    }

    /**
     * Save custom fields
     */
    public function save_custom_fields($post_id)
    {
        // Save servings count
        $servings = isset($_POST['clover_servings']) ? intval($_POST['clover_servings']) : 1;
        if ($servings < 1) {
            $servings = 1;
        }
        update_post_meta($post_id, '_clover_servings', $servings);

        // Save modifiers
        $modifiers = isset($_POST['clover_modifiers']) ? sanitize_textarea_field($_POST['clover_modifiers']) : '';
        if (!empty($modifiers)) {
            update_post_meta($post_id, '_clover_modifiers', $modifiers);
        } else {
            delete_post_meta($post_id, '_clover_modifiers');
        }

        // Save modifier constraints
        $constraints = isset($_POST['clover_modifier_constraints']) ? sanitize_textarea_field($_POST['clover_modifier_constraints']) : '';
        if (!empty($constraints)) {
            update_post_meta($post_id, '_clover_modifier_constraints', $constraints);
        } else {
            delete_post_meta($post_id, '_clover_modifier_constraints');
        }
    }

    /**
     * Display modifiers on product page
     */
    public function display_modifiers()
    {
        global $product;

        $modifiers_json = get_post_meta($product->get_id(), '_clover_modifiers', true);
        if (empty($modifiers_json)) {
            return;
        }

        $modifiers = json_decode($modifiers_json, true);
        if (empty($modifiers) || !is_array($modifiers)) {
            return;
        }

        // Get servings count (for multi-portion products)
        $servings_count = get_post_meta($product->get_id(), '_clover_servings', true);
        if (empty($servings_count) || $servings_count < 1) {
            $servings_count = 1;
        }

        // Get modifier group constraints
        $constraints_json = get_post_meta($product->get_id(), '_clover_modifier_constraints', true);
        $constraints = !empty($constraints_json) ? json_decode($constraints_json, true) : array();

        // Get the original product price to store for calculations
        // Try multiple methods to get the price
        $original_product_price = $product->get_regular_price();

        // Fallback: if regular price is empty, try get_price()
        if (empty($original_product_price)) {
            $original_product_price = $product->get_price();
        }

        // Fallback: if still empty, get directly from post meta
        if (empty($original_product_price)) {
            $original_product_price = get_post_meta($product->get_id(), '_regular_price', true);
        }

        // Fallback: try _price meta
        if (empty($original_product_price)) {
            $original_product_price = get_post_meta($product->get_id(), '_price', true);
        }

        // Ensure it's a valid number
        if (empty($original_product_price) || !is_numeric($original_product_price)) {
            $original_product_price = 0;
        }

        // Group modifiers by their modifier group
        $grouped_modifiers = array();
        foreach ($modifiers as $modifier) {
            if (!isset($modifier['id'], $modifier['name'], $modifier['price'], $modifier['modifier_group_id'])) {
                continue;
            }

            $group_id = $modifier['modifier_group_id'];
            if (!isset($grouped_modifiers[$group_id])) {
                $grouped_modifiers[$group_id] = array();
            }
            $grouped_modifiers[$group_id][] = $modifier;
        }

        // Sort groups: required groups first, then optional groups
        $required_groups = array();
        $optional_groups = array();

        foreach ($grouped_modifiers as $group_id => $group_modifiers) {
            $is_required = false;
            if (!empty($constraints)) {
                foreach ($constraints as $constraint) {
                    if ($constraint['id'] === $group_id && isset($constraint['minRequired']) && intval($constraint['minRequired']) > 0) {
                        $is_required = true;
                        break;
                    }
                }
            }

            if ($is_required) {
                $required_groups[$group_id] = $group_modifiers;
            } else {
                $optional_groups[$group_id] = $group_modifiers;
            }
        }

        // Merge arrays: required first, then optional
        $sorted_groups = array_merge($required_groups, $optional_groups);

        // Get the first group ID for active tab
        $first_group_id = !empty($sorted_groups) ? key($sorted_groups) : '';

        // Get product name for portion labels
        $product_name = $product->get_name();

        // Helper function to extract size value from modifier name (e.g., "10"", "12 inches")
        function extract_size_from_name($name)
        {
            // Match patterns like: 10", 10'', 10 inches, 12", 12 inches, etc.
            // Only match at the START of the string to avoid matching numbers later in the name
            if (preg_match('/^(\d+)\s*(?:inches?|["\'])/i', $name, $matches)) {
                return $matches[1];  // Return just the number
            }
            return null;
        }

        // Identify the size group and extract size values
        $size_group_id = null;
        $size_group_modifiers = array();
        $available_sizes = array();

        foreach ($sorted_groups as $gid => $group_mods) {
            $gname = !empty($group_mods[0]['modifier_group_name']) ? $group_mods[0]['modifier_group_name'] : '';
            if (stripos($gname, 'size') !== false) {
                $size_group_id = $gid;
                $size_group_modifiers = $group_mods;
                foreach ($group_mods as $mod) {
                    $size_val = extract_size_from_name($mod['name']);
                    if ($size_val) {
                        $available_sizes[$size_val] = $mod['id'];
                    }
                }
                break;
            }
        }

        // Determine which groups are size-specific and which are universal
        $size_specific_groups = array();
        $universal_groups = array();

        foreach ($sorted_groups as $gid => $group_mods) {
            if ($gid === $size_group_id) {
                continue;  // Skip the size group itself
            }
            $gname = !empty($group_mods[0]['modifier_group_name']) ? $group_mods[0]['modifier_group_name'] : '';

            // Check if group name starts with a size pattern
            $group_size = extract_size_from_name($gname);
            if ($group_size) {
                $size_specific_groups[$gid] = $group_size;
            } else {
                $universal_groups[$gid] = $group_mods;
            }
        }

        echo '<div class="custom-modifiers-wrapper">';
        echo '<input type="hidden" id="original-product-price" value="' . esc_attr($original_product_price) . '">';
        echo '<input type="hidden" id="servings-count" value="' . esc_attr($servings_count) . '">';

        // Add size filtering data if we have a size group
        if ($size_group_id && !empty($available_sizes)) {
            $first_size = array_key_first($available_sizes);
            echo '<input type="hidden" id="size-group-id" value="' . esc_attr($size_group_id) . '">';
            echo '<input type="hidden" id="size-specific-groups" value="' . esc_attr(json_encode($size_specific_groups)) . '">';
            echo '<input type="hidden" id="default-size" value="' . esc_attr($first_size) . '">';
        }

        // If multiple servings, show serving selector
        if ($servings_count > 1) {
            echo '<div class="servings-header">';
            echo '<h3>' . esc_html($product_name) . ' - Multiple Servings</h3>';
            echo '<p class="servings-description">Select modifiers for each serving individually.</p>';
            echo '</div>';
        }

        // Render modifier sections for each serving
        for ($serving = 1; $serving <= $servings_count; $serving++) {
            $serving_label = $servings_count > 1 ? " #{$serving}" : '';
            $serving_class = $servings_count > 1 ? ' serving-section' : '';

            echo '<div class="modifier-serving' . esc_attr($serving_class) . '" data-serving="' . esc_attr($serving) . '">';

            if ($servings_count > 1) {
                echo '<h4 class="serving-title">' . esc_html($product_name . $serving_label) . '</h4>';
            }

            // Tab navigation
            echo '<div class="modifier-tabs" data-serving="' . esc_attr($serving) . '">';
            $tab_index = 0;
            foreach ($sorted_groups as $group_id => $group_modifiers) {
                $group_name = !empty($group_modifiers[0]['modifier_group_name']) ? $group_modifiers[0]['modifier_group_name'] : 'Modifier Group';
                $total_in_group = count($group_modifiers);

                // Determine if this is a size-specific group and get its size
                $group_size_value = '';
                if (isset($size_specific_groups[$group_id])) {
                    $group_size_value = $size_specific_groups[$group_id];
                }

                // Check if this group is required
                $is_required = false;
                if (!empty($constraints)) {
                    foreach ($constraints as $constraint) {
                        if ($constraint['id'] === $group_id) {
                            $min_req = isset($constraint['minRequired']) ? intval($constraint['minRequired']) : 0;
                            if ($min_req > 0) {
                                $is_required = true;
                                break;
                            }
                            break;
                        }
                    }
                }

                $required_indicator = $is_required ? ' required' : '';
                $active_class = ($tab_index === 0) ? ' active' : '';

                // Add size-specific class and style for tabs
                $size_specific_class = $group_size_value ? ' size-specific-tab' : '';
                $size_specific_style = $group_size_value ? ' style="display:none;"' : '';

                echo '<button type="button" class="modifier-tab' . $active_class . $size_specific_class . '" data-group-id="' . esc_attr($group_id) . '" data-serving="' . esc_attr($serving) . '" data-group-size="' . esc_attr($group_size_value) . '"' . $size_specific_style . $required_indicator . '>';
                echo '<span class="modifier-tab-name">' . esc_html($group_name) . '</span>';
                echo '<span class="modifier-tab-count" data-group-id="' . esc_attr($group_id) . '" data-serving="' . esc_attr($serving) . '"> (0/' . $total_in_group . ')</span>';
                echo '</button>';

                $tab_index++;
            }
            echo '</div>';

            // Tab content panels
            echo '<div class="modifier-tab-panels" data-serving="' . esc_attr($serving) . '">';
            foreach ($sorted_groups as $group_id => $group_modifiers) {
                $group_name = !empty($group_modifiers[0]['modifier_group_name']) ? $group_modifiers[0]['modifier_group_name'] : 'Modifier Group';
                $active_class = ($group_id === $first_group_id) ? ' active' : '';

                // Determine if this is a size-specific group and get its size
                $group_size_class = '';
                $group_size_value = '';
                if (isset($size_specific_groups[$group_id])) {
                    $group_size_value = $size_specific_groups[$group_id];
                    $group_size_class = ' size-specific-group';
                }

                echo '<div class="modifier-tab-panel' . $active_class . $group_size_class . '" data-group-id="' . esc_attr($group_id) . '" data-serving="' . esc_attr($serving) . '" data-group-size="' . esc_attr($group_size_value) . '"' . ($group_size_value ? ' style="display:none;"' : '') . '>';

                // Find constraints for this group
                $group_constraint = null;
                if (!empty($constraints)) {
                    foreach ($constraints as $constraint) {
                        if ($constraint['id'] === $group_id) {
                            $group_constraint = $constraint;
                            break;
                        }
                    }
                }

                // Display group header with constraints if they exist
                echo '<div class="modifier-group" data-group-id="' . esc_attr($group_id) . '" data-serving="' . esc_attr($serving) . '" data-group-size="' . esc_attr($group_size_value) . '">';
                echo '<h4>' . esc_html($group_name);

                if ($group_constraint) {
                    $min_req = isset($group_constraint['minRequired']) ? intval($group_constraint['minRequired']) : 0;
                    $max_allow = isset($group_constraint['maxAllowed']) ? intval($group_constraint['maxAllowed']) : 0;

                    $constraint_text = '';
                    if ($min_req > 0 && $max_allow > 0 && $min_req == $max_allow) {
                        if ($min_req == 1) {
                            $constraint_text = ' (One required)';
                        } else {
                            $constraint_text = " ({$min_req} required)";
                        }
                    } elseif ($min_req > 0 && $max_allow > 0) {
                        $constraint_text = " ({$min_req}-{$max_allow} required)";
                    } elseif ($min_req > 0) {
                        $constraint_text = " (At least {$min_req} required)";
                    } elseif ($max_allow > 0) {
                        $constraint_text = " (Max {$max_allow} allowed)";
                    }

                    echo '<span class="modifier-constraint">' . $constraint_text . '</span>';
                }
                echo '</h4>';

                foreach ($group_modifiers as $modifier) {
                    if (!isset($modifier['id'], $modifier['name'], $modifier['price'])) {
                        continue;
                    }

                    $modifier_id = esc_attr($modifier['id']);
                    $modifier_name = esc_html($modifier['name']);
                    $modifier_price = floatval($modifier['price']);
                    $original_modifier_price = isset($modifier['original_price']) ? floatval($modifier['original_price']) : $modifier_price;
                    $clover_id = isset($modifier['clover_id']) ? esc_attr($modifier['clover_id']) : '';

                    // Apply global discount if enabled
                    $discount_enabled = get_option('clover_global_discount_enabled', '0');
                    $discount_percent = get_option('clover_global_discount_percent', '10');
                    $apply_to_modifiers = get_option('clover_global_discount_apply_modifiers', '0');

                    $display_price = $modifier_price;
                    $show_discount = false;

                    if ($discount_enabled === '1' && $discount_percent > 0 && $apply_to_modifiers === '1') {
                        $discount = floatval($discount_percent) / 100;
                        $display_price = $original_modifier_price * (1 - $discount);
                        $show_discount = true;
                    }

                    // Extract size from modifier name if this is in the size group
                    $modifier_size = '';
                    if ($group_id === $size_group_id) {
                        if (preg_match('/(\d+)\s*(?:inches?|["\'])/i', $modifier['name'], $size_matches)) {
                            $modifier_size = $size_matches[1];
                        }
                    }

                    // Only show price if it's greater than 0
                    if ($show_discount && $original_modifier_price > 0) {
                        // Show original price crossed out + discounted price
                        $price_display = $display_price > 0 ? ' (<del style="text-decoration:line-through;opacity:0.6;">$' . number_format($original_modifier_price, 2) . '</del> $' . number_format($display_price, 2) . ')' : '';
                    } else {
                        $price_display = $modifier_price > 0 ? ' (+$' . number_format($modifier_price, 2) . ')' : '';
                    }

                    echo '<div class="modifier-item">';
                    echo '<label>';
                    echo '<input type="checkbox" name="custom_modifiers[' . esc_attr($serving) . '][]" value="' . $modifier_id . '" data-clover-id="' . $clover_id . '" data-price="' . $display_price . '" data-group-id="' . $group_id . '" data-serving="' . esc_attr($serving) . '"' . ($modifier_size ? ' data-modifier-size="' . esc_attr($modifier_size) . '"' : '') . '> ';
                    echo $modifier_name . $price_display;
                    echo '</label>';
                    echo '</div>';
                }

                echo '</div>';  // modifier-group
                echo '</div>';  // modifier-tab-panel
            }
            echo '</div>';  // modifier-tab-panels
            echo '</div>';  // modifier-serving
        }

        echo '</div>';  // custom-modifiers-wrapper

        // Add JavaScript to handle modifier selection and price calculation with constraints
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Tab switching functionality (now serving-aware)
            $(document).on('click', '.modifier-tab', function() {
                var groupId = $(this).data('group-id');
                var serving = $(this).data('serving');

                // Update tab active state within this serving
                var servingTabs = $('.modifier-tabs[data-serving="' + serving + '"] .modifier-tab');
                servingTabs.removeClass('active');
                $(this).addClass('active');

                // Update panel active state within this serving
                var servingPanels = $('.modifier-tab-panels[data-serving="' + serving + '"] .modifier-tab-panel');
                servingPanels.removeClass('active');
                var $selectedPanel = $('.modifier-tab-panel[data-group-id="' + groupId + '"][data-serving="' + serving + '"]');
                $selectedPanel.addClass('active');

                // Update panel visibility based on active state and size filtering
                servingPanels.each(function() {
                    var $panel = $(this);
                    var isActive = $panel.hasClass('active');
                    var isHidden = $panel.hasClass('size-filtered-hidden');

                    // Panel is visible only if it's active AND not filtered out by size
                    if (isActive && !isHidden) {
                        $panel.show();
                    } else {
                        $panel.hide();
                    }
                });
            });

            // Use event delegation to ensure it works even with dynamically loaded content
            $(document).on('change', '.modifier-item input[type="checkbox"]', function() {
                var serving = $(this).data('serving');
                var groupId = $(this).data('group-id');
                var $changedCheckbox = $(this);
                var modifierName = $(this).closest('label').text().trim();

                // Check if this is a "sizes" group (case-insensitive)
                var groupName = $(this).closest('.modifier-group').find('h4').text().toLowerCase();
                var isSizesGroup = groupName.indexOf('size') !== -1;

                // For "sizes" groups: enforce radio-button behavior
                if (isSizesGroup) {
                    // PREVENT unchecking - sizes must always have one selected
                    if (!$changedCheckbox.is(':checked')) {
                        // Re-check it immediately (prevent unchecking)
                        $changedCheckbox.prop('checked', true);
                        return;
                    }

                    // User is checking a size - uncheck all others (radio behavior)
                    var selectedSize = $changedCheckbox.data('modifier-size');

                    $('.modifier-group[data-group-id="' + groupId + '"][data-serving="' + serving + '"]')
                        .find('input[type="checkbox"][data-group-id="' + groupId + '"][data-serving="' + serving + '"]')
                        .not($changedCheckbox)
                        .prop('checked', false);

                    // Apply size filtering for this serving
                    if (selectedSize) {
                        filterGroupsBySize(serving, selectedSize);
                    }

                    // Update counts, validate, and update price
                    updateTabCounts(serving);
                    updateCheckboxStates(serving);
                    validateConstraints(serving);
                    updateProductPrice();
                    return;
                }

                // Normal flow for non-sizes groups
                updateTabCounts(serving);
                updateCheckboxStates(serving);
                validateConstraints(serving);
                updateProductPrice();
            });

            // Size filtering functionality
            function filterGroupsBySize(serving, selectedSize) {
                var $panelsContainer = $('.modifier-tab-panels[data-serving="' + serving + '"]');
                var $tabsContainer = $('.modifier-tabs[data-serving="' + serving + '"]');

                if ($panelsContainer.length === 0) {
                    return;
                }

                // Ensure selectedSize is a string for comparison
                selectedSize = String(selectedSize);

                // First, hide all size-specific panels (don't touch universal ones yet)
                $('.modifier-tab-panel[data-serving="' + serving + '"]', $panelsContainer).each(function() {
                    var $panel = $(this);
                    var groupSize = $panel.attr('data-group-size');
                    var groupId = $panel.data('group-id');

                    // Only hide/show size-specific panels, universal panels stay visible
                    if (groupSize && groupSize !== '') {
                        if (groupSize === selectedSize) {
                            $panel.removeClass('size-filtered-hidden').show();
                        } else {
                            $panel.addClass('size-filtered-hidden').hide();
                            $panel.removeClass('active');
                        }
                    }
                });

                // Update tab visibility based on size filtering
                $('.modifier-tab[data-serving="' + serving + '"]', $tabsContainer).each(function() {
                    var $tab = $(this);
                    var groupId = $tab.data('group-id');
                    var $correspondingPanel = $('.modifier-tab-panel[data-group-id="' + groupId + '"][data-serving="' + serving + '"]', $panelsContainer);

                    // Tab should match panel visibility
                    if ($correspondingPanel.length > 0 && !$correspondingPanel.hasClass('size-filtered-hidden')) {
                        $tab.show();
                    } else if ($correspondingPanel.length > 0) {
                        $tab.hide();
                    }
                });

                // If the active tab is hidden, switch to the first visible tab
                var $activeTab = $('.modifier-tab.active[data-serving="' + serving + '"]', $tabsContainer);
                if ($activeTab.length > 0 && !$activeTab.is(':visible')) {
                    var $firstVisibleTab = $('.modifier-tab[data-serving="' + serving + '"]:visible', $tabsContainer).first();
                    if ($firstVisibleTab.length > 0) {
                        $activeTab.removeClass('active');
                        $firstVisibleTab.addClass('active');
                    }
                } else if ($activeTab.length === 0) {
                    // No active tab, set first visible as active
                    var $firstVisibleTab = $('.modifier-tab[data-serving="' + serving + '"]:visible', $tabsContainer).first();
                    if ($firstVisibleTab.length > 0) {
                        $firstVisibleTab.addClass('active');
                    }
                }

                // Now update all panel visibilities based on active state and size filter
                $('.modifier-tab-panel[data-serving="' + serving + '"]', $panelsContainer).each(function() {
                    var $panel = $(this);
                    var groupId = $panel.data('group-id');
                    var isActive = $panel.hasClass('active');
                    var isHidden = $panel.hasClass('size-filtered-hidden');

                    // Panel is visible only if it's active AND not filtered out by size
                    if (isActive && !isHidden) {
                        $panel.show();
                    } else {
                        $panel.hide();
                    }
                });
            }

            // Auto-select first size modifier on page load and apply filter
            function initializeSizeFiltering() {
                var sizeGroupId = $('#size-group-id').val();
                var defaultSize = $('#default-size').val();

                if (!sizeGroupId || !defaultSize) {
                    // No size group - show all tabs and first panel, initialize validation
                    var servingsCount = parseInt($('#servings-count').val()) || 1;
                    for (var s = 1; s <= servingsCount; s++) {
                        // Show all tabs for this serving
                        $('.modifier-tab[data-serving="' + s + '"]').show();
                        // Hide all panels, then show only the active one
                        $('.modifier-tab-panel[data-serving="' + s + '"]').hide();
                        $('.modifier-tab-panel.active[data-serving="' + s + '"]').show();

                        updateTabCounts(s);
                        updateCheckboxStates(s);
                        validateConstraints(s);
                    }
                    updateProductPrice();
                    updateAddToCartButton();
                    return;
                }

                // Get servings count
                var servingsCount = parseInt($('#servings-count').val()) || 1;

                // Auto-select the first size modifier for each serving
                for (var s = 1; s <= servingsCount; s++) {
                    var $firstSizeCheckbox = $('.modifier-item input[type="checkbox"][data-group-id="' + sizeGroupId + '"][data-serving="' + s + '"][data-modifier-size="' + defaultSize + '"]').first();

                    if ($firstSizeCheckbox.length > 0) {
                        $firstSizeCheckbox.prop('checked', true);

                        // Apply filter for this serving
                        filterGroupsBySize(s, defaultSize);

                        // Update counts and validation
                        updateTabCounts(s);
                        updateCheckboxStates(s);
                        validateConstraints(s);
                    }
                }

                // Update price after all selections
                updateProductPrice();

                // IMPORTANT: Update Add to Cart button state
                updateAddToCartButton();
            }

            // Initialize size filtering on page load (run immediately on DOM ready)
            initializeSizeFiltering();

            // Before adding to cart, uncheck modifiers in hidden tabs
            $(document).on('click', 'button[name="add-to-cart"], .single_add_to_cart_button', function(e) {
                var servingsCount = parseInt($('#servings-count').val()) || 1;

                for (var s = 1; s <= servingsCount; s++) {
                    // Find all checkboxes in hidden tabs and uncheck them
                    $('.modifier-tab[data-serving="' + s + '"]:hidden').each(function() {
                        var groupId = $(this).data('group-id');
                        $('.modifier-item input[type="checkbox"][data-group-id="' + groupId + '"][data-serving="' + s + '"]').prop('checked', false);
                    });
                }
            });

            // Update tab counts when modifiers are selected/deselected (serving-aware)
            function updateTabCounts(serving) {
                $('.modifier-group[data-serving="' + serving + '"]').each(function() {
                    var groupId = $(this).data('group-id');
                    var checkboxes = $(this).find('input[type="checkbox"][data-group-id="' + groupId + '"][data-serving="' + serving + '"]');
                    var checkedCount = checkboxes.filter(':checked').length;
                    var totalCount = checkboxes.length;

                    // Update tab badge
                    $('.modifier-tab-count[data-group-id="' + groupId + '"][data-serving="' + serving + '"]').text(' (' + checkedCount + '/' + totalCount + ')');
                });
            }

            // Enable/disable checkboxes based on max allowed (serving-aware)
            function updateCheckboxStates(serving) {
                $('.modifier-group[data-serving="' + serving + '"]').each(function() {
                    var groupId = $(this).data('group-id');
                    var checkboxes = $(this).find('input[type="checkbox"][data-group-id="' + groupId + '"][data-serving="' + serving + '"]');
                    var checkedCount = checkboxes.filter(':checked').length;

                    // Check if this is a "sizes" group (case-insensitive)
                    var groupName = $(this).find('h4').text().toLowerCase();
                    var isSizesGroup = groupName.indexOf('size') !== -1;

                    // For "sizes" groups: don't disable checkboxes, allow user to switch selection
                    if (isSizesGroup) {
                        checkboxes.each(function() {
                            $(this).prop('disabled', false);
                            $(this).parent().css('opacity', '1');
                        });
                        return; // Skip to next group
                    }

                    // Find the constraint for this group
                    var constraintText = $(this).find('.modifier-constraint').text();
                    var rangeMatchRange = constraintText.match(/\((\d+)-(\d+) required\)/);
                    var rangeMatchExact = constraintText.match(/\((One|\d+) required\)/);
                    var maxAllowMatch = constraintText.match(/\(Max (\d+) allowed\)/);

                    var maxAllow = 0;

                    if (rangeMatchRange) {
                        maxAllow = parseInt(rangeMatchRange[2]);
                    } else if (rangeMatchExact) {
                        var reqValue = rangeMatchExact[1];
                        maxAllow = (reqValue.toLowerCase() === 'one') ? 1 : parseInt(reqValue);
                    } else if (maxAllowMatch) {
                        maxAllow = parseInt(maxAllowMatch[1]);
                    }

                    // If max is reached, disable unchecked checkboxes
                    if (maxAllow > 0 && checkedCount >= maxAllow) {
                        checkboxes.each(function() {
                            if (!$(this).is(':checked')) {
                                $(this).prop('disabled', true);
                                $(this).parent().css('opacity', '0.5');
                            }
                        });
                    } else {
                        // Re-enable all checkboxes
                        checkboxes.each(function() {
                            $(this).prop('disabled', false);
                            $(this).parent().css('opacity', '1');
                        });
                    }
                });
            }

            // Validate constraints (serving-aware)
            function validateConstraints(serving) {
                $('.modifier-group[data-serving="' + serving + '"]').each(function() {
                    var groupId = $(this).data('group-id');
                    var checkboxes = $(this).find('input[type="checkbox"][data-group-id="' + groupId + '"][data-serving="' + serving + '"]');
                    var checkedCount = checkboxes.filter(':checked').length;

                    // Find the constraint for this group
                    var constraintText = $(this).find('.modifier-constraint').text();
                    var minReqMatch = constraintText.match(/\(At least (\d+) required\)/);
                    var rangeMatchRange = constraintText.match(/\((\d+)-(\d+) required\)/);
                    var rangeMatchExact = constraintText.match(/\((One|\d+) required\)/);
                    var rangeMatch = rangeMatchRange || rangeMatchExact;
                    var maxAllowMatch = constraintText.match(/\(Max (\d+) allowed\)/);

                    var isValid = true;
                    var errorMsg = '';

                    if (rangeMatchRange) {
                        // Handle range format like "(1-3 required)"
                        var minReq = parseInt(rangeMatchRange[1]);
                        var maxAllow = parseInt(rangeMatchRange[2]);
                        if (minReq === maxAllow) {
                            // When minRequired equals maxAllowed, show a cleaner message
                            if (minReq === 1) {
                                if (checkedCount !== 1) {
                                    isValid = false;
                                    errorMsg = 'Must select one';
                                }
                            } else {
                                if (checkedCount !== minReq) {
                                    isValid = false;
                                    errorMsg = 'Must select ' + minReq;
                                }
                            }
                        } else {
                            // Standard range validation
                            if (checkedCount < minReq || checkedCount > maxAllow) {
                                isValid = false;
                                errorMsg = 'Must select between ' + minReq + ' and ' + maxAllow;
                            }
                        }
                    } else if (rangeMatchExact) {
                        // Handle exact format like "(One required)" or "(2 required)"
                        var reqValue = rangeMatchExact[1];
                        var exactReq = (reqValue.toLowerCase() === 'one') ? 1 : parseInt(reqValue);
                        if (checkedCount !== exactReq) {
                            isValid = false;
                            errorMsg = (exactReq === 1) ? 'Must select one' : 'Must select ' + exactReq;
                        }
                    } else if (minReqMatch) {
                        var minReq = parseInt(minReqMatch[1]);
                        if (checkedCount < minReq) {
                            isValid = false;
                            errorMsg = 'Must select at least ' + minReq;
                        }
                    } else if (maxAllowMatch) {
                        var maxAllow = parseInt(maxAllowMatch[1]);
                        if (checkedCount > maxAllow) {
                            isValid = false;
                            errorMsg = 'Can select at most ' + maxAllow;
                        }
                    }

                    // Show/hide error message
                    var errorDiv = $(this).find('.constraint-error');
                    if (!isValid) {
                        if (errorDiv.length === 0) {
                            $(this).append('<div class="constraint-error" style="color:red; font-size:0.9em;">' + errorMsg + '</div>');
                        } else {
                            errorDiv.text(errorMsg);
                        }
                    } else {
                        errorDiv.remove();
                    }

                    // Enable/disable "Add to Cart" button based on ALL servings' constraints
                    updateAddToCartButton();
                });
            }

            // Check all servings and update Add to Cart button
            function updateAddToCartButton() {
                var servingsCount = parseInt($('#servings-count').val()) || 1;
                var allValid = true;

                for (var s = 1; s <= servingsCount; s++) {
                    $('.modifier-group[data-serving="' + s + '"]').each(function() {
                        var groupConstraintText = $(this).find('.modifier-constraint').text();
                        var groupCheckboxes = $(this).find('input[type="checkbox"]');
                        var groupCheckedCount = groupCheckboxes.filter(':checked').length;

                        // Check if this is a size group
                        var groupName = $(this).find('h4').text().toLowerCase();
                        var isSizeGroup = groupName.indexOf('size') !== -1;

                        // Size groups MUST have at least one selected (treat as "One required")
                        if (isSizeGroup) {
                            if (groupCheckedCount !== 1) {
                                allValid = false;
                            }
                            return; // Continue to next group
                        }

                        // For non-size groups, check constraint text if it exists
                        if (groupConstraintText) {
                            var minReqMatch = groupConstraintText.match(/\(At least (\d+) required\)/);
                            var rangeMatchRange = groupConstraintText.match(/\((\d+)-(\d+) required\)/);
                            var rangeMatchExact = groupConstraintText.match(/\((One|\d+) required\)/);
                            var maxAllowMatch = groupConstraintText.match(/\(Max (\d+) allowed\)/);

                            if (rangeMatchRange) {
                                var minReq = parseInt(rangeMatchRange[1]);
                                var maxAllow = parseInt(rangeMatchRange[2]);
                                if (minReq === maxAllow) {
                                    if (minReq === 1) {
                                        if (groupCheckedCount !== 1) {
                                            allValid = false;
                                        }
                                    } else {
                                        if (groupCheckedCount !== minReq) {
                                            allValid = false;
                                        }
                                    }
                                } else {
                                    if (groupCheckedCount < minReq || groupCheckedCount > maxAllow) {
                                        allValid = false;
                                    }
                                }
                            } else if (rangeMatchExact) {
                                var reqValue = rangeMatchExact[1];
                                var exactReq = (reqValue.toLowerCase() === 'one') ? 1 : parseInt(reqValue);
                                if (groupCheckedCount !== exactReq) {
                                    allValid = false;
                                }
                            } else if (minReqMatch) {
                                var minReq = parseInt(minReqMatch[1]);
                                if (groupCheckedCount < minReq) {
                                    allValid = false;
                                }
                            } else if (maxAllowMatch) {
                                var maxAllow = parseInt(maxAllowMatch[1]);
                                if (groupCheckedCount > maxAllow) {
                                    allValid = false;
                                }
                            }
                        }
                    });

                    if (!allValid) {
                        break; // Break early
                    }
                }

                var addToCartBtn = $('button[name="addtocart"], .single_add_to_cart_button');

                if (!allValid) {
                    addToCartBtn.prop('disabled', true);
                    addToCartBtn.addClass('disabled');
                } else {
                    addToCartBtn.prop('disabled', false);
                    addToCartBtn.removeClass('disabled');
                }
            }

            function updateProductPrice() {
                // Get the original base product price from the hidden input
                var originalBasePriceVal = $('#original-product-price').val();
                var originalBasePrice = originalBasePriceVal !== '' ? parseFloat(originalBasePriceVal) : 0;

                // Calculate total modifiers price - ONLY from VISIBLE TABS
                var modifiersPrice = 0;
                var checkedCount = 0;

                $('.modifier-item input[type="checkbox"]:checked').each(function() {
                    // Check if this checkbox's tab is visible
                    var groupId = $(this).data('group-id');
                    var serving = $(this).data('serving');
                    var $tab = $('.modifier-tab[data-group-id="' + groupId + '"][data-serving="' + serving + '"]');
                    var isTabVisible = $tab.length > 0 && $tab.is(':visible');

                    if (isTabVisible) {
                        var modifierPrice = parseFloat($(this).data('price'));
                        if (!isNaN(modifierPrice)) {
                            modifiersPrice += modifierPrice;
                            checkedCount++;
                        }
                    }
                });

                var totalPrice = originalBasePrice + modifiersPrice;
                var formattedTotalPrice = totalPrice.toFixed(2);

                // Find the currency symbol from the original price display
                var currencySymbol = '$';
                var $priceElement = $('.woocommerce-Price-amount bdi').first();
                if ($priceElement.length === 0) {
                    $priceElement = $('.price').first();
                }
                if ($priceElement.length > 0) {
                    var originalText = $priceElement.text();
                    var symbolMatches = originalText.replace(/[0-9.,\s]/g, '').match(/[^\d\s]/);
                    if (symbolMatches) {
                        currencySymbol = symbolMatches[0];
                    }
                }

                // Update price - check if we're in quick view modal first
                var $modal = $('#clover-quick-view-modal');
                if ($modal.length > 0 && $modal.is(':visible')) {
                    // We're in quick view - update modal price in the button
                    var $addToCartBtn = $modal.find('.clover-quick-view-add-to-cart');
                    var $originalPriceElement = $modal.find('.clover-quick-view-price-wrapper .woocommerce-Price-amount bdi, .clover-quick-view-price-wrapper .price').first();

                    // Get original base price from button text or hidden input
                    var basePriceText = $addToCartBtn.text().replace(/Add to Cart - /i, '').trim();

                    // Update the price in the button
                    $addToCartBtn.html('Add to Cart - ' + currencySymbol + formattedTotalPrice);
                } else {
                    // We're on single product page - update page price
                    console.log('=== SINGLE PRODUCT PAGE - Updating price ===');

                    // When discount is enabled, price has structure: <del>original</del> <ins>sale</ins>
                    // We need to update ONLY the <ins> (sale price), not the <del> (original)
                    var $salePriceIns = $('ins.woocommerce-Price-amount.amount').first();

                    if ($salePriceIns.length > 0) {
                        console.log('Updating sale price (ins tag)');
                        $salePriceIns.text(currencySymbol + formattedTotalPrice);
                    } else {
                        // No sale price structure, update normal price
                        var $priceBdi = $('.woocommerce-Price-amount bdi').first();
                        if ($priceBdi.length > 0) {
                            console.log('Updating normal price');
                            $priceBdi.text(currencySymbol + formattedTotalPrice);
                        } else {
                            // Fallback: try to find any price element
                            var $priceAmount = $('.woocommerce-Price-amount.amount').first();
                            if ($priceAmount.length > 0) {
                                var $bdi = $priceAmount.find('bdi').first();
                                if ($bdi.length > 0) {
                                    $bdi.text(currencySymbol + formattedTotalPrice);
                                } else {
                                    $priceAmount.text(currencySymbol + formattedTotalPrice);
                                }
                            }
                        }
                    }
                }
            }

            // Initial validation and tab counts on page load
            var servingsCount = parseInt($('#servings-count').val()) || 1;
            for (var p = 1; p <= servingsCount; p++) {
                updateTabCounts(p);
                updateCheckboxStates(p);
                setTimeout(function(portion) {
                    validateConstraints(portion);
                }, 1000, p);
            }
        });
        </script>
        <style>
        /* Servings Header */
        .servings-header {
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 4px;
            border: 1px solid #ebebeb;
        }

        .servings-header h3 {
            margin: 0 0 5px 0;
            color: #333;
        }

        .servings-description {
            margin: 0;
            color: #666;
            font-size: 14px;
        }

        /* Serving Sections */
        .modifier-serving {
            margin-bottom: 30px;
            padding-bottom: 30px;
            border-bottom: 2px solid #ebebeb;
        }

        .modifier-serving:last-child {
            border-bottom: none;
        }

        .serving-title {
            margin-bottom: 15px;
            padding: 10px 15px;
            background-color: #f5f5f5;
            border-left: 4px solid #333;
            border-radius: 4px;
            color: #333;
        }

        /* Tab Navigation */
        .modifier-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-bottom: 0;
            border-bottom: 2px solid #ebebeb;
            padding-bottom: 0;
        }

        .modifier-tab {
            padding: 10px 16px;
            /* background-color: #f5f5f5; */
            border: 1px solid #ebebeb;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            /*color: #555;*/
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: -2px;
        }

        /* .modifier-tab:hover {
            background-color: #ebebeb;
            color: #333;
        } */

        .modifier-tab.active {
            background-color: #fafafa;
            border-color: #ebebeb;
            border-bottom: 2px solid #fafafa;
            color: #333;
            font-weight: 600;
        }

        .modifier-tab.required .modifier-tab-name::before {
            content: '* ';
            color: #d63638;
        }

        .modifier-tab-count {
            font-size: 12px;
            /* color: #777; */
            font-weight: normal;
            /* background-color: #e8e8e8; */
            padding: 2px 6px;
            border-radius: 10px;
            white-space: nowrap;
        }

        /* .modifier-tab.active .modifier-tab-count {
            background-color: #d8d8d8;
        } */

        /* Responsive tabs for mobile */
        @media (max-width: 480px) {
            .modifier-tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
                -webkit-overflow-scrolling: touch;
            }

            .modifier-tab {
                flex-shrink: 0;
                padding: 10px 12px;
                font-size: 13px;
            }

            .modifier-tab-name {
                max-width: 120px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
        }

        /* Tab Panels */
        .modifier-tab-panels {
            border: 1px solid #ebebeb;
            border-top: none;
            border-radius: 0 0 4px 4px;
        }

        .modifier-tab-panel {
            display: none;
        }

        .modifier-tab-panel.active {
            display: block;
        }

        /* Size filtering - hide panels that don't match selected size */
        .modifier-tab-panel.size-filtered-hidden {
            display: none !important;
        }

        /* Modifier Groups and Items */
        .modifier-constraint {
            font-weight: normal;
            font-size: 0.85em;
            color: #777;
            background-color: #f9f9f9;
            padding: 2px 6px;
            border-radius: 3px;
            border: 1px solid #e1e1e1;
            margin-left: 8px;
            vertical-align: middle;
        }
        .constraint-error {
            color: #d63638;
            font-size: 0.85em;
            background-color: #fcf0f1;
            padding: 4px 8px;
            border-radius: 3px;
            border-left: 2px solid #d63638;
        }
        .modifier-group {
            margin-bottom: 0;
            margin-top: 5 px;
            padding: 15px;
            border: none;
            border-radius: 0;
            background-color: #fafafa;
        }
        .modifier-group h4 {
            margin-top: 0;
            margin-bottom: 12px;
            color: #333;
        }
        .modifier-item label {
            display: block;
            margin-bottom: 8px;
            padding: 8px;
            background-color: #fff;
            border: 1px solid #ebebeb;
            border-radius: 3px;
        }
        .modifier-item label:hover {
            background-color: #f8f8f8;
        }
        </style>
        <?php
    }

    /**
     * Add modifiers to cart item data
     */
    public function add_modifiers_to_cart($cart_item_data, $product_id, $variation_id)
    {
        clover_log("CUSTOM MODIFIER SYSTEM: add_modifiers_to_cart called for product ID: {$product_id}, variation ID: {$variation_id}");
        clover_log('CUSTOM MODIFIER SYSTEM: POST data: ' . print_r($_POST, true));
        clover_log('CUSTOM MODIFIER SYSTEM: Cart item data before: ' . print_r($cart_item_data, true));

        if (isset($_POST['custom_modifiers']) && is_array($_POST['custom_modifiers'])) {
            clover_log('CUSTOM MODIFIER SYSTEM: Found custom_modifiers in POST: ' . print_r($_POST['custom_modifiers'], true));

            // Handle multi-serving modifiers (array of arrays) or single serving (flat array)
            $modifiers = array();
            foreach ($_POST['custom_modifiers'] as $serving_key => $serving_modifiers) {
                if (is_array($serving_modifiers)) {
                    $modifiers[$serving_key] = array_map('sanitize_text_field', $serving_modifiers);
                } else {
                    // Fallback for single serving (backward compatibility)
                    $modifiers['serving_1'] = array(sanitize_text_field($serving_modifiers));
                }
            }

            $cart_item_data['custom_modifiers'] = $modifiers;

            // Generate a unique hash to prevent duplicate entries
            $cart_item_data['custom_data_hash'] = md5(maybe_serialize($cart_item_data));
            clover_log('CUSTOM MODIFIER SYSTEM: Added custom_modifiers to cart item data: ' . print_r($modifiers, true));
        } else {
            clover_log('CUSTOM MODIFIER SYSTEM: No custom_modifiers found in POST data');
        }

        clover_log('CUSTOM MODIFIER SYSTEM: Cart item data after: ' . print_r($cart_item_data, true));
        return $cart_item_data;
    }

    /**
     * Update cart item price based on selected modifiers
     */
    public function update_cart_item_price($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        if (did_action('woocommerce_before_calculate_totals') >= 2) {
            return;
        }

        // Get global discount settings
        $discount_enabled = get_option('clover_global_discount_enabled', '0');
        $discount_percent = get_option('clover_global_discount_percent', '10');
        $apply_to_modifiers = get_option('clover_global_discount_apply_modifiers', '0');
        $apply_discount = ($discount_enabled === '1' && $discount_percent > 0 && $apply_to_modifiers === '1');
        $discount_multiplier = 1 - (floatval($discount_percent) / 100);

        foreach ($cart->get_cart() as $cart_item) {
            if (isset($cart_item['custom_modifiers']) && is_array($cart_item['custom_modifiers'])) {
                $product_id = $cart_item['product_id'];
                $modifiers_json = get_post_meta($product_id, '_clover_modifiers', true);

                if (!empty($modifiers_json)) {
                    $all_modifiers = json_decode($modifiers_json, true);
                    $selected_modifiers = $cart_item['custom_modifiers'];

                    $modifiers_total = 0;
                    $charged_modifiers = array();

                    // Handle multi-portion modifiers (array of arrays)
                    foreach ($selected_modifiers as $portion_key => $portion_modifiers) {
                        if (is_array($portion_modifiers)) {
                            foreach ($portion_modifiers as $modifier_id) {
                                foreach ($all_modifiers as $modifier) {
                                    if ($modifier['id'] == $modifier_id && isset($modifier['price'])) {
                                        $mod_price = floatval($modifier['price']);

                                        // Apply global discount to modifier if enabled
                                        if ($apply_discount) {
                                            $mod_price = $mod_price * $discount_multiplier;
                                        }

                                        $modifiers_total += $mod_price;

                                        // Store the actual charged price for this modifier
                                        $charged_modifiers[] = array(
                                            'id' => $modifier['id'],
                                            'clover_id' => isset($modifier['clover_id']) ? $modifier['clover_id'] : '',
                                            'name' => $modifier['name'],
                                            'price' => $mod_price,
                                            'original_price' => floatval($modifier['price']),
                                            'serving' => is_numeric($portion_key) ? intval($portion_key) : (preg_match('/serving_(\d+)/', $portion_key, $m) ? intval($m[1]) : 1)
                                        );

                                        break;
                                    }
                                }
                            }
                        }
                    }

                    // Store charged modifier data in cart item for persistence to order
                    $cart_item['clover_charged_modifiers'] = $charged_modifiers;

                    // Get base price from database (before ANY filters)
                    $base_price = get_post_meta($product_id, '_regular_price', true);
                    $product_price = floatval($base_price);

                    // Apply discount to product manually
                    if ($apply_discount) {
                        $product_price = $product_price * $discount_multiplier;
                    }

                    // Set final price: discounted product + discounted modifiers
                    $new_price = $product_price + $modifiers_total;

                    // Store FULL price (with modifiers) in global array so filter knows it's already calculated
                    $GLOBALS['clover_already_discounted'][$product_id] = $new_price;

                    // Store in cart item for persistence
                    $cart_item['data']->set_price($new_price);
                    $cart_item['price'] = $new_price;
                }
            }
        }
    }

    /**
     * Restore custom price when loading cart item from session
     */
    public function restore_cart_item_price($cart_item, $values)
    {
        // If cart item has a custom price stored, use it
        if (isset($cart_item['price'])) {
            $cart_item['data']->set_price($cart_item['price']);
        }
        return $cart_item;
    }

    /**
     * Display modifiers in cart
     */
    public function display_modifiers_in_cart($item_data, $cart_item)
    {
        if (isset($cart_item['custom_modifiers']) && is_array($cart_item['custom_modifiers'])) {
            $product_id = $cart_item['product_id'];
            $modifiers_json = get_post_meta($product_id, '_clover_modifiers', true);

            if (!empty($modifiers_json)) {
                $all_modifiers = json_decode($modifiers_json, true);
                $selected_modifiers = $cart_item['custom_modifiers'];
                $product = wc_get_product($product_id);
                $product_name = $product ? $product->get_name() : 'Product';

                // Get servings count to determine if we should show serving labels
                $servings_count = get_post_meta($product_id, '_clover_servings', true);
                $show_serving_labels = !empty($servings_count) && $servings_count > 1;

                // Check if global discount is enabled for modifiers
                $discount_enabled = get_option('clover_global_discount_enabled', '0');
                $discount_percent = get_option('clover_global_discount_percent', '10');
                $apply_to_modifiers = get_option('clover_global_discount_apply_modifiers', '0');
                $show_discount = ($discount_enabled === '1' && $discount_percent > 0 && $apply_to_modifiers === '1');

                foreach ($selected_modifiers as $serving_key => $serving_modifiers) {
                    // Extract serving number from key (e.g., "1" from "1" or "serving_1")
                    $serving_num = is_numeric($serving_key)
                        ? intval($serving_key)
                        : (preg_match('/serving_(\d+)/', $serving_key, $matches) ? intval($matches[1]) : 1);

                    if (is_array($serving_modifiers)) {
                        foreach ($serving_modifiers as $modifier_id) {
                            foreach ($all_modifiers as $modifier) {
                                if ($modifier['id'] == $modifier_id) {
                                    $modifier_name = $modifier['name'];
                                    $original_price = floatval($modifier['price']);

                                    // Build price display
                                    if ($show_discount && $original_price > 0) {
                                        // Calculate discounted price from original
                                        $discount_multiplier = 1 - (floatval($discount_percent) / 100);
                                        $discounted_price = $original_price * $discount_multiplier;
                                        
                                        // Use span with specific class - WooCommerce won't strip this
                                        $price_display = ' (<span class="clover-modifier-original-price">$' . number_format($original_price, 2) . '</span> $' . number_format($discounted_price, 2) . ')';
                                    } else {
                                        $price_display = $original_price > 0 ? ' (+$' . number_format($original_price, 2) . ')' : '';
                                    }

                                    if ($show_serving_labels) {
                                        // Multi-serving: Show serving label as key
                                        $item_data[] = array(
                                            'key' => $product_name . ' #' . $serving_num,
                                            'value' => $modifier_name . $price_display,
                                            'display' => '&nbsp;' . $modifier_name . $price_display,
                                        );
                                    } else {
                                        // Single serving: Only output value, no key (avoids "Modifier:" label)
                                        $item_data[] = array(
                                            'value' => $modifier_name . $price_display,
                                        );
                                    }
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $item_data;
    }

    /**
     * Add modifier data to order items
     */
    public function add_modifier_data_to_order($item_id, $values, $cart_item_key)
    {
        clover_log("CUSTOM MODIFIER SYSTEM: Processing order item ID: {$item_id}");
        clover_log('CUSTOM MODIFIER SYSTEM: Values: ' . print_r($values, true));
        clover_log("CUSTOM MODIFIER SYSTEM: Cart item key: {$cart_item_key}");

        // Prefer the charged modifier data stored at cart time (actual prices paid)
        $charged_modifiers = null;
        if (is_object($values) && isset($values['clover_charged_modifiers']) && is_array($values['clover_charged_modifiers'])) {
            $charged_modifiers = $values['clover_charged_modifiers'];
            clover_log('CUSTOM MODIFIER SYSTEM: Found clover_charged_modifiers in values: ' . print_r($charged_modifiers, true));
        } elseif (is_array($values) && isset($values['clover_charged_modifiers']) && is_array($values['clover_charged_modifiers'])) {
            $charged_modifiers = $values['clover_charged_modifiers'];
            clover_log('CUSTOM MODIFIER SYSTEM: Found clover_charged_modifiers in values array: ' . print_r($charged_modifiers, true));
        }

        // Fallback: legacy custom_modifiers path (reconstructs from JSON)
        if (!$charged_modifiers) {
            $custom_modifiers = null;
            if (is_object($values) && property_exists($values, 'legacy_values')) {
                if (isset($values->legacy_values['custom_modifiers']) && is_array($values->legacy_values['custom_modifiers'])) {
                    $custom_modifiers = $values->legacy_values['custom_modifiers'];
                    clover_log('CUSTOM MODIFIER SYSTEM: Found custom_modifiers in legacy_values: ' . print_r($custom_modifiers, true));
                }
            } elseif (is_array($values) && isset($values['custom_modifiers']) && is_array($values['custom_modifiers'])) {
                $custom_modifiers = $values['custom_modifiers'];
                clover_log('CUSTOM MODIFIER SYSTEM: Found custom_modifiers in values array: ' . print_r($custom_modifiers, true));
            }

            if ($custom_modifiers) {
                $product_id = is_object($values) ? $values->get_product_id() : ($values['product_id'] ?? 0);
                if (!$product_id && is_object($values) && property_exists($values, 'legacy_values')) {
                    $product_id = $values->legacy_values['product_id'] ?? 0;
                }

                $modifiers_json = get_post_meta($product_id, '_clover_modifiers', true);
                clover_log("CUSTOM MODIFIER SYSTEM: Modifiers JSON for product {$product_id}: " . $modifiers_json);

                if (!empty($modifiers_json)) {
                    $all_modifiers = json_decode($modifiers_json, true);
                    $selected_modifiers = $custom_modifiers;
                    clover_log('CUSTOM MODIFIER SYSTEM: All modifiers: ' . print_r($all_modifiers, true));
                    clover_log('CUSTOM MODIFIER SYSTEM: Selected modifiers: ' . print_r($selected_modifiers, true));

                    $charged_modifiers = array();

                    foreach ($selected_modifiers as $serving_key => $serving_modifiers) {
                        $serving_num = is_numeric($serving_key)
                            ? intval($serving_key)
                            : (preg_match('/serving_(\d+)/', $serving_key, $matches) ? intval($matches[1]) : 1);

                        if (is_array($serving_modifiers)) {
                            foreach ($serving_modifiers as $modifier_id) {
                                foreach ($all_modifiers as $modifier) {
                                    if ($modifier['id'] == $modifier_id && isset($modifier['clover_id'])) {
                                        $charged_modifiers[] = array(
                                            'id' => $modifier['id'],
                                            'clover_id' => $modifier['clover_id'],
                                            'name' => $modifier['name'],
                                            'price' => $modifier['price'],
                                            'original_price' => floatval($modifier['price']),
                                            'serving' => $serving_num
                                        );
                                        clover_log('CUSTOM MODIFIER SYSTEM: Added modifier to data (fallback): ' . print_r($charged_modifiers[count($charged_modifiers) - 1], true));
                                        break;
                                    }
                                }
                            }
                        }
                    }
                } else {
                    clover_log("CUSTOM MODIFIER SYSTEM: No modifiers JSON found for product {$product_id}");
                }
            } else {
                clover_log("CUSTOM MODIFIER SYSTEM: No custom_modifiers found in values for item {$item_id}");
                return;
            }
        }

        if (!empty($charged_modifiers)) {
            clover_log("CUSTOM MODIFIER SYSTEM: Saving charged modifier data to order item {$item_id}: " . print_r($charged_modifiers, true));
            wc_add_order_item_meta($item_id, '_custom_modifier_data', $charged_modifiers);
        } else {
            clover_log("CUSTOM MODIFIER SYSTEM: No modifier data to save for item {$item_id}");
        }
    }
}

// Initialize the custom modifier system
Custom_Modifier_System::get_instance();

// Function to get modifier data by Clover ID
function get_modifier_by_clover_id($clover_id)
{
    // This would search through all products to find a modifier with the given Clover ID
    // In a real implementation, you might want to create a more efficient lookup system

    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => 'publish'
    );

    $products = get_posts($args);

    foreach ($products as $product_post) {
        $modifiers_json = get_post_meta($product_post->ID, '_clover_modifiers', true);
        if (!empty($modifiers_json)) {
            $modifiers = json_decode($modifiers_json, true);
            if (is_array($modifiers)) {
                foreach ($modifiers as $modifier) {
                    if (isset($modifier['clover_id']) && $modifier['clover_id'] === $clover_id) {
                        return $modifier;
                    }
                }
            }
        }
    }

    return null;
}

// Function to get modifier data by name or value
function get_modifier_by_name_or_value($search_value)
{
    // This would search through all products to find a modifier with the given name or value
    // In a real implementation, you might want to create a more efficient lookup system

    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => 'publish'
    );

    $products = get_posts($args);

    foreach ($products as $product_post) {
        $modifiers_json = get_post_meta($product_post->ID, '_clover_modifiers', true);
        if (!empty($modifiers_json)) {
            $modifiers = json_decode($modifiers_json, true);
            if (is_array($modifiers)) {
                foreach ($modifiers as $modifier) {
                    // Check if the search value matches the modifier name
                    if (isset($modifier['name']) && stripos($modifier['name'], $search_value) !== false) {
                        return $modifier;
                    }

                    // Check if search value matches the display value format (e.g., "A (+$10)")
                    if (isset($modifier['name']) && isset($modifier['price'])) {
                        $display_format = $modifier['name'] . ' (+$' . number_format($modifier['price'], 2) . ')';
                        if (stripos($display_format, $search_value) !== false) {
                            return $modifier;
                        }
                    }

                    // Check if search value matches the clover_id
                    if (isset($modifier['clover_id']) && stripos($modifier['clover_id'], $search_value) !== false) {
                        return $modifier;
                    }
                }
            }
        }
    }

    return null;
}

?>
