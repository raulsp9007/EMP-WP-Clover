<?php
/**
 * Import modifiers automatically for each item
 * This script helps map Clover modifiers to WooCommerce products
 */

// Function to import modifiers for a specific product
function import_clover_modifiers_for_product($product_id, $clover_modifiers) {
    // Convert the modifiers to our custom format
    $formatted_modifiers = array();

    foreach ($clover_modifiers as $modifier) {
        $formatted_modifiers[] = array(
            'id' => sanitize_text_field($modifier['id']), // Local ID for this modifier
            'name' => sanitize_text_field($modifier['name']),
            'price' => floatval($modifier['price'] / 100), // Convert from cents to dollars
            'clover_id' => sanitize_text_field($modifier['id']) // The Clover ID
        );
    }

    // Save the modifiers to the product
    update_post_meta($product_id, '_clover_modifiers', json_encode($formatted_modifiers));
}

// Function to get all modifiers for a product
function get_clover_modifiers_for_product($product_id) {
    $modifiers_json = get_post_meta($product_id, '_clover_modifiers', true);
    if (empty($modifiers_json)) {
        return array();
    }

    return json_decode($modifiers_json, true);
}

// Function to import modifiers for all products
function import_all_clover_modifiers($clover_items_with_modifiers) {
    foreach ($clover_items_with_modifiers as $item_data) {
        // Find the corresponding WooCommerce product by SKU (which should match Clover ID)
        $product_id = wc_get_product_id_by_sku(sanitize_text_field($item_data['id']));

        if ($product_id) {
            // Import modifiers for this product
            import_clover_modifiers_for_product($product_id, $item_data['modifiers']);
        }
    }
}

// Example usage:
/*
// Sample data structure
$clover_items_with_modifiers = array(
    array(
        'id' => 'ITEM123',
        'name' => 'Burger',
        'modifiers' => array(
            array(
                'id' => 'MOD1234567890123',
                'name' => 'Extra Cheese',
                'price' => 150 // in cents
            ),
            array(
                'id' => 'MOD9876543210987',
                'name' => 'Bacon',
                'price' => 200 // in cents
            )
        )
    )
);

import_all_clover_modifiers($clover_items_with_modifiers);
*/