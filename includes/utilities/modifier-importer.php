<?php
/**
 * Utility functions to import modifiers from Clover API
 */

/**
 * Import modifiers for a WooCommerce product based on Clover item ID
 *
 * @param string $clover_item_id The Clover item ID
 * @param int $wc_product_id The WooCommerce product ID
 * @param array|null $prefetchedData Optional pre-fetched item data with expanded modifier groups
 * @return bool True on success, false on failure
 */
function import_modifiers_for_product($clover_item_id, $wc_product_id, $prefetchedData = null) {
    clover_log("Importing modifiers for item {$clover_item_id} to product ID: {$wc_product_id}");

    try {
        // Get the modifier service
        $config = require CLOVER_PLUGIN_PATH . 'config/api.php';
        $modifierService = new \Src\Services\ModifierService($config);

        // Get all modifiers for the item (use pre-fetched data if available to avoid API call)
        $modifiersResponse = $modifierService->getAllItemModifiers($clover_item_id, $prefetchedData);

        if (!isset($modifiersResponse['modifiers']) || empty($modifiersResponse['modifiers'])) {
            clover_log("No modifiers found for item: {$clover_item_id}");
            clover_log("Full response: " . print_r($modifiersResponse, true)); // Debug the full response
            return true; // Not an error, just no modifiers to import
        }

        $modifiers = $modifiersResponse['modifiers'];
        $formatted_modifiers = array();

        foreach ($modifiers as $modifier) {
            if (isset($modifier['id'], $modifier['name'], $modifier['price'])) {
                clover_log("Importing modifier {$modifier['id']} of item {$clover_item_id}");

                $formatted_modifiers[] = array(
                    'id' => sanitize_text_field($modifier['id']), // Local ID for this modifier
                    'name' => sanitize_text_field($modifier['name']),
                    'price' => floatval($modifier['price'] / 100), // Convert from cents to dollars
                    'clover_id' => sanitize_text_field($modifier['id']), // The Clover ID
                    'modifier_group_id' => sanitize_text_field($modifier['modifierGroupId'] ?? ''),
                    'modifier_group_name' => sanitize_text_field($modifier['modifierGroupName'] ?? '')
                );
            }
        }

        // Save the modifiers to the product
        $modifiers_json = json_encode($formatted_modifiers);
        update_post_meta($wc_product_id, '_clover_modifiers', $modifiers_json);

        // Log the saved data for debugging
        clover_log("Successfully imported " . count($formatted_modifiers) . " modifiers for product ID: {$wc_product_id}");
        clover_log("Saved modifiers JSON for product {$wc_product_id}: " . $modifiers_json);

        // Double-check that the meta was saved by retrieving it
        $saved_modifiers = get_post_meta($wc_product_id, '_clover_modifiers', true);
        clover_log("Retrieved modifiers from DB for product {$wc_product_id}: " . $saved_modifiers);

        return true;
    } catch (Exception $e) {
        clover_log("Error importing modifiers for product {$wc_product_id}: " . $e->getMessage());
        return false;
    }
}

/**
 * Import modifiers for all products that have Clover IDs
 */
function import_modifiers_for_all_products() {
    // Get all products that have a Clover ID (SKU matches Clover item ID)
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => 'any',
        'meta_query' => array(
            array(
                'key' => '_sku',
                'value' => '',
                'compare' => '!='
            )
        )
    );

    $products = get_posts($args);
    $success_count = 0;
    $fail_count = 0;

    foreach ($products as $product_post) {
        $product = wc_get_product($product_post->ID);
        $clover_item_id = $product->get_sku(); // Assuming SKU is the Clover item ID

        if (!empty($clover_item_id)) {
            if (import_modifiers_for_product($clover_item_id, $product->get_id())) {
                $success_count++;
            } else {
                $fail_count++;
            }
        }
    }

    clover_log("Modifier import completed: {$success_count} successful, {$fail_count} failed");

    return array(
        'success' => $success_count,
        'failed' => $fail_count
    );
}

// Auto-import hooks have been disabled to prevent unnecessary API calls during batch import.
// Modifiers are now handled in the main import process with pre-fetched data.
// Use import_modifiers_for_product() manually if you need to import modifiers for a single product.