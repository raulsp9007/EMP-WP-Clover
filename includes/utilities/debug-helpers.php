<?php
/**
 * Debug helpers for checking product meta data
 */

/**
 * Function to manually check product meta data for debugging
 *
 * @param int $product_id The product ID to check
 * @return array The meta data for the product
 */
function debug_check_product_meta($product_id) {
    $product = wc_get_product($product_id);

    if (!$product) {
        clover_log("debug_check_product_meta: Product with ID {$product_id} not found");
        return array('error' => 'Product not found');
    }

    $meta_data = get_post_meta($product_id);

    // Specifically check for our custom field
    $clover_modifiers = get_post_meta($product_id, '_clover_modifiers', true);

    $debug_info = array(
        'product_id' => $product_id,
        'product_name' => $product->get_name(),
        'sku' => $product->get_sku(),
        '_clover_modifiers' => $clover_modifiers,
        'all_meta_keys' => array_keys($meta_data)
    );

    clover_log("Debug product meta for ID {$product_id}: " . print_r($debug_info, true));

    return $debug_info;
}

/**
 * Function to manually trigger modifier import for a product (for debugging)
 *
 * @param string $clover_item_id The Clover item ID
 * @param int $wc_product_id The WooCommerce product ID
 */
function debug_manual_modifier_import($clover_item_id, $wc_product_id) {
    clover_log("Manual debug modifier import triggered for item {$clover_item_id}, product {$wc_product_id}");

    if (function_exists('import_modifiers_for_product')) {
        $result = import_modifiers_for_product($clover_item_id, $wc_product_id);
        clover_log("Manual debug modifier import result: " . ($result ? 'SUCCESS' : 'FAILED'));

        // Check the result
        $modifiers = get_post_meta($wc_product_id, '_clover_modifiers', true);
        clover_log("After manual import, modifiers for product {$wc_product_id}: " . ($modifiers ? $modifiers : 'NONE'));

        return $result;
    } else {
        clover_log("import_modifiers_for_product function not found!");
        return false;
    }
}