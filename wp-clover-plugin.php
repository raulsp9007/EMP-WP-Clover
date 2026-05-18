<?php

/**
 * Plugin Name: WPCloverSync
 * Description: A plugin to sync WP and Clover via API
 * Version: 1.0.1
 * Author: Ermis Media Production
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CLOVER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CLOVER_PLUGIN_PATH', plugin_dir_path(__FILE__));
require_once __DIR__ . '/src/Core/Plugin.php';

// Logger (must load early to catch errors)
require_once CLOVER_PLUGIN_PATH . 'includes/utilities/class-clover-logger.php';

// Auto-intercept PHP errors when logging enabled
if (get_option('clover_enable_logs', '0') === '1') {
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        // Only log errors from our plugin files
        $plugin_dir = CLOVER_PLUGIN_PATH;
        $plugin_dir_relative = str_replace(ABSPATH, '', $plugin_dir);
        $errfile_relative = str_replace(ABSPATH, '', $errfile);

        // Check if error is from our plugin
        if (strpos($errfile_relative, $plugin_dir_relative) !== 0) {
            return false; // Pass to PHP's default handler
        }

        $levels = [
            E_ERROR => 'ERROR',
            E_WARNING => 'WARNING',
            E_PARSE => 'PARSE',
            E_NOTICE => 'NOTICE',
            E_CORE_ERROR => 'CORE_ERROR',
            E_CORE_WARNING => 'CORE_WARNING',
            E_COMPILE_ERROR => 'COMPILE_ERROR',
            E_COMPILE_WARNING => 'COMPILE_WARNING',
            E_USER_ERROR => 'USER_ERROR',
            E_USER_WARNING => 'USER_WARNING',
            E_USER_NOTICE => 'USER_NOTICE',
            E_STRICT => 'STRICT',
            E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
            E_DEPRECATED => 'DEPRECATED',
            E_USER_DEPRECATED => 'USER_DEPRECATED',
        ];
        $level = $levels[$errno] ?? 'UNKNOWN';
        clover_log("[$level] $errstr in $errfile on line $errline", 'ERROR');
        return false;
    });

    // Catch fatal errors from our plugin on shutdown
    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $plugin_dir = CLOVER_PLUGIN_PATH;
            $plugin_dir_relative = str_replace(ABSPATH, '', $plugin_dir);
            $errfile_relative = str_replace(ABSPATH, '', $error['file']);

            if (strpos($errfile_relative, $plugin_dir_relative) === 0) {
                clover_log("[FATAL] " . $error['message'] . " in " . $error['file'] . " on line " . $error['line'], 'ERROR');
            }
        }
    });
}

// Include required files
// require_once CLOVER_PLUGIN_PATH . 'includes/class-http-client.php';
require_once CLOVER_PLUGIN_PATH . 'includes/class-admin.php';

require_once CLOVER_PLUGIN_PATH . 'src/Services/BaseService.php';
require_once CLOVER_PLUGIN_PATH . 'src/Services/OrderService.php';
require_once CLOVER_PLUGIN_PATH . 'src/Services/ModifierService.php';

require_once CLOVER_PLUGIN_PATH . 'src/Customers/Services/CustomerSyncService.php';
require_once CLOVER_PLUGIN_PATH . 'src/Customers/Mappers/UserToCloverMapper.php';

require_once CLOVER_PLUGIN_PATH . 'src/Services/CustomerService.php';
require_once plugin_dir_path(__FILE__) . 'src/Domain/Address.php';
require_once __DIR__ . '/src/Domain/Address.php';
require_once __DIR__ . '/src/Domain/Customer.php';
require_once __DIR__ . '/src/Domain/Phone.php';

require_once CLOVER_PLUGIN_PATH . 'src/Core/HttpClient.php';
require_once CLOVER_PLUGIN_PATH . 'includes/class-orders.php';

require_once CLOVER_PLUGIN_PATH . 'includes/modifiers/custom-modifier-system.php';
require_once CLOVER_PLUGIN_PATH . 'includes/utilities/modifier-importer.php';
require_once CLOVER_PLUGIN_PATH . 'includes/utilities/debug-helpers.php';
require_once CLOVER_PLUGIN_PATH . 'includes/utilities/progress-tracker.php';

// Business Hours Module
require_once CLOVER_PLUGIN_PATH . 'src/BusinessHours/class-business-hours.php';
require_once CLOVER_PLUGIN_PATH . 'src/BusinessHours/class-shortcodes.php';
require_once CLOVER_PLUGIN_PATH . 'src/BusinessHours/class-banner-display.php';
require_once CLOVER_PLUGIN_PATH . 'src/BusinessHours/class-admin-settings.php';
require_once CLOVER_PLUGIN_PATH . 'src/BusinessHours/class-ajax-handlers.php';
require_once CLOVER_PLUGIN_PATH . 'src/BusinessHours/class-category-hours.php';
require_once CLOVER_PLUGIN_PATH . 'src/BusinessHours/class-module-loader.php';

// require_once CLOVER_PLUGIN_PATH . 'src/Services/ItemsService.php';
use Src\Domain\Customer;

/*add_action('plugins_loaded', function () {
    \Src\Core\Plugin::init();
});*/

// Initialize the plugin
function clover_plugin_init()
{
    // Initialize admin functionality
    if (is_admin()) {
        new Clover_Admin();
    }
    \Src\Core\Plugin::init();
    
    // Initialize Business Hours module
    \Src\BusinessHours\Module_Loader::get_instance();

    // Enqueue frontend script for cart/checkout pages
    add_action('wp_enqueue_scripts', 'clover_enqueue_frontend_scripts');

    // Add AJAX handlers for API requests
    add_action('wp_ajax_clover_make_request', 'clover_handle_api_request');
    add_action('wp_ajax_clover_test_connection', 'clover_test_connection');
    add_action('wp_ajax_clover_import_items', 'clover_import_items_ajax');
    add_action('wp_ajax_clover_reload_tenders', 'clover_reload_tenders');
    add_action('wp_ajax_nopriv_clover_reload_tenders', 'clover_reload_tenders');

    // Logs AJAX handlers
    add_action('wp_ajax_clover_get_logs', 'clover_get_logs');
    add_action('wp_ajax_clover_clear_logs', 'clover_clear_logs');

    // Quick View AJAX handlers
    add_action('wp_ajax_clover_quick_view_product', 'clover_quick_view_product_handler');
    add_action('wp_ajax_nopriv_clover_quick_view_product', 'clover_quick_view_product_handler');
    add_action('wp_ajax_clover_quick_view_add_to_cart', 'clover_quick_view_add_to_cart_handler');
    add_action('wp_ajax_nopriv_clover_quick_view_add_to_cart', 'clover_quick_view_add_to_cart_handler');
}

add_action('plugins_loaded', 'clover_plugin_init');

// Track which products have been discounted to prevent double discount
$GLOBALS['clover_already_discounted'] = array();

// Smart discount filter - prevents double discount
add_filter('woocommerce_product_get_price', 'clover_apply_global_discount_smart', 20, 2);
add_filter('woocommerce_product_get_regular_price', 'clover_apply_global_discount_smart', 20, 2);

function clover_apply_global_discount_smart($price, $product)
{
    $enabled = get_option('clover_global_discount_enabled', '0');
    $percent = get_option('clover_global_discount_percent', '10');
    
    if ($enabled !== '1' || $percent <= 0 || !is_numeric($price) || $price <= 0) {
        return $price;
    }
    
    $product_id = $product->get_id();
    
    // Check if this product was already discounted in cart calculation
    if (isset($GLOBALS['clover_already_discounted'][$product_id])) {
        // Return the already-calculated full price (includes modifiers)
        return $GLOBALS['clover_already_discounted'][$product_id];
    }
    
    // Apply discount and store it
    $discount = floatval($percent) / 100;
    $discounted_price = $price * (1 - $discount);
    
    return $discounted_price;
}

// For product page display with strikethrough
add_filter('woocommerce_get_price_html', 'clover_show_global_discount_sale', 10, 2);

function clover_show_global_discount_sale($price_html, $product)
{
    $enabled = get_option('clover_global_discount_enabled', '0');
    $percent = get_option('clover_global_discount_percent', '10');
    
    if ($enabled === '1' && $percent > 0) {
        // Get the regular price from database
        $regular_price = get_post_meta($product->get_id(), '_regular_price', true);
        
        if (empty($regular_price)) {
            return $price_html;
        }
        
        // Calculate discounted price
        $discount = floatval($percent) / 100;
        $sale_price = floatval($regular_price) * (1 - $discount);
        
        // Format both prices
        $formatted_regular = wc_price($regular_price, array('currency' => $product->get_meta('_currency')));
        $formatted_sale = wc_price($sale_price, array('currency' => $product->get_meta('_currency')));
        
        // Build HTML with strikethrough
        $price_html = '<del aria-hidden="true" class="woocommerce-Price-amount amount">' . strip_tags($formatted_regular) . '</del> <ins class="woocommerce-Price-amount amount">' . strip_tags($formatted_sale) . '</ins>';
    }
    
    return $price_html;
}

// Force WooCommerce to show sale price HTML
add_filter('woocommerce_product_is_on_sale', 'clover_force_product_on_sale', 10, 2);

function clover_force_product_on_sale($is_on_sale, $product)
{
    $enabled = get_option('clover_global_discount_enabled', '0');
    $percent = get_option('clover_global_discount_percent', '10');
    
    if ($enabled === '1' && $percent > 0 && $product->get_price() > 0) {
        return true;
    }
    
    return $is_on_sale;
}

// ── Clover Discount tab: visual price display ────────────────────────────────

add_filter('woocommerce_product_get_price', 'clover_apply_tab_discount_price', 25, 2);
add_filter('woocommerce_product_get_regular_price', 'clover_apply_tab_discount_price', 25, 2);

function clover_apply_tab_discount_price($price, $product)
{
    if (is_admin()) return $price;
    // Global discount takes priority — avoid double discount
    if (get_option('clover_global_discount_enabled', '0') === '1') return $price;

    $apply   = get_option('clover_discount_apply_to_orders', '0');
    $percent = floatval(get_option('clover_discount_cached_percent', '0'));

    if ($apply !== '1' || $percent <= 0 || !is_numeric($price) || $price <= 0) return $price;

    return $price * (1 - $percent / 100);
}

add_filter('woocommerce_get_price_html', 'clover_tab_discount_price_html', 15, 2);

function clover_tab_discount_price_html($price_html, $product)
{
    if (get_option('clover_global_discount_enabled', '0') === '1') return $price_html;

    $apply   = get_option('clover_discount_apply_to_orders', '0');
    $percent = floatval(get_option('clover_discount_cached_percent', '0'));

    clover_log("TAB DISCOUNT HTML — apply={$apply} percent={$percent} product=" . $product->get_id());

    if ($apply !== '1' || $percent <= 0) return $price_html;

    $regular_price = get_post_meta($product->get_id(), '_regular_price', true);
    if (empty($regular_price)) return $price_html;

    $sale_price = floatval($regular_price) * (1 - $percent / 100);
    $price_html = '<del aria-hidden="true" class="woocommerce-Price-amount amount">' . strip_tags(wc_price($regular_price)) . '</del> <ins class="woocommerce-Price-amount amount">' . strip_tags(wc_price($sale_price)) . '</ins>';

    return $price_html;
}

add_filter('woocommerce_product_is_on_sale', 'clover_tab_discount_is_on_sale', 15, 2);

function clover_tab_discount_is_on_sale($is_on_sale, $product)
{
    if (get_option('clover_global_discount_enabled', '0') === '1') return $is_on_sale;

    $apply   = get_option('clover_discount_apply_to_orders', '0');
    $percent = floatval(get_option('clover_discount_cached_percent', '0'));

    if ($apply === '1' && $percent > 0 && $product->get_price() > 0) return true;

    return $is_on_sale;
}

// Auto-sync discount cached percent when discount ID is saved
add_action('updated_option', function ($option, $old, $new) {
    if ($option !== 'clover_discount_id' || empty($new)) return;
    try {
        $config       = require CLOVER_PLUGIN_PATH . 'config/api.php';
        $orderService = new \Src\Services\OrderService($config);
        $response     = $orderService->getDiscounts();
        if (isset($response['data']['elements'])) {
            foreach ($response['data']['elements'] as $d) {
                if (($d['id'] ?? '') === $new) {
                    $percent = isset($d['percentage']) ? intval($d['percentage']) : 0;
                    update_option('clover_discount_cached_percent', $percent);
                    clover_log("DISCOUNT SYNC: cached percent={$percent} for discount {$new}");
                    break;
                }
            }
        }
    } catch (Exception $e) {
        clover_log('DISCOUNT SYNC ERROR: ' . $e->getMessage());
    }
}, 10, 3);

// ── Service charge as WooCommerce cart fee ────────────────────────────────────
add_action('woocommerce_cart_calculate_fees', 'clover_add_service_charge_fee');
function clover_add_service_charge_fee($cart)
{
    if (is_admin() && !defined('DOING_AJAX')) return;

    $enabled_ids = get_option('clover_enabled_tax_rates', []);
    if (!is_array($enabled_ids) || empty($enabled_ids)) return;

    $all_rates = json_decode(get_option('clover_tax_rates_cache', '[]'), true) ?: [];

    foreach ($all_rates as $tr) {
        if (empty($tr['id'])) continue;
        if (!in_array($tr['id'], $enabled_ids)) continue;

        $pct_display = $tr['rate'] / 100000; // e.g. 3.0 for 3%
        if ($pct_display <= 0) continue;

        $fee_amount = $cart->get_subtotal() * ($pct_display / 100);
        $label = ($tr['name'] ?? 'Fee') . ' (' . rtrim(rtrim(number_format($pct_display, 4), '0'), '.') . '%)';
        $cart->add_fee($label, $fee_amount, false);
    }
}

// ── Settings audit log ───────────────────────────────────────────────────────

add_action('updated_option', 'clover_settings_audit_log', 10, 3);

function clover_settings_audit_log($option_name, $old_value, $new_value)
{
    if (strpos($option_name, 'clover_') !== 0) return;

    $log_dir  = CLOVER_PLUGIN_PATH . 'logs/';
    $log_file = $log_dir . 'settings-audit.log';

    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }

    $user     = wp_get_current_user();
    $username = ($user && $user->ID) ? $user->user_login : 'system';
    $date     = date('Y-m-d H:i:s');
    $old_str  = is_array($old_value) ? json_encode($old_value) : (string) $old_value;
    $new_str  = is_array($new_value) ? json_encode($new_value) : (string) $new_value;

    if ($old_str === $new_str) return;

    $entry = "[{$date}] [{$username}] {$option_name}: \"{$old_str}\" → \"{$new_str}\"\n";
    @file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
}

// Enqueue store-closed script on checkout — works for classic and Blocks checkout
add_action('wp_enqueue_scripts', 'clover_enqueue_checkout_closed_script');
function clover_enqueue_checkout_closed_script()
{
    if (!is_checkout()) {
        return;
    }
    if (get_option('clover_prevent_orders_when_closed', '0') !== '1') {
        return;
    }

    $business_hours = new \Src\BusinessHours\Business_Hours();
    $status         = $business_hours->get_business_status();

    wp_enqueue_script(
        'clover-checkout-closed',
        CLOVER_PLUGIN_URL . 'public/js/checkout-closed.js',
        array(),
        '1.0.2',
        true
    );

    wp_localize_script('clover-checkout-closed', 'cloverStoreStatus', array(
        'open'    => (bool) $status['open'],
        'message' => !empty($status['message']) ? $status['message'] : 'We are currently closed',
        'error'   => !empty($status['error']),
    ));
}

// Hard block for WooCommerce Blocks (Store API) checkout
// Fires after order is created but before payment — throws RouteException to abort
add_action('woocommerce_store_api_checkout_order_processed', 'clover_block_order_when_closed_blocks');
function clover_block_order_when_closed_blocks($order)
{
    if (get_option('clover_prevent_orders_when_closed', '0') !== '1') {
        return;
    }

    $business_hours = new \Src\BusinessHours\Business_Hours();
    $status         = $business_hours->get_business_status();

    if (!empty($status['error']) || $status['open']) {
        return;
    }

    $when    = !empty($status['message']) ? $status['message'] : 'We are currently closed';
    $message = $when . '. Please place your order during business hours.';

    // RouteException is the WooCommerce Blocks-native way to abort checkout with a user-facing error
    if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
        throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
            'store_closed',
            $message,
            400
        );
    }

    // Fallback for older WC versions without RouteException
    throw new \Exception(esc_html($message));
}

// Enqueue frontend scripts for cart/checkout and product pages
function clover_enqueue_frontend_scripts()
{
    if (is_cart() || is_checkout()) {
        wp_enqueue_script(
            'clover-remove-modifier-label',
            CLOVER_PLUGIN_URL . 'public/js/remove-modifier-label.js',
            array(),
            '1.0.0',
            true
        );
    }

    // ALWAYS load quick view on frontend - for debugging
    wp_enqueue_style(
        'clover-quick-view',
        CLOVER_PLUGIN_URL . 'public/css/quick-view.css',
        array(),
        '1.0.0'
    );

    wp_enqueue_script(
        'clover-quick-view',
        CLOVER_PLUGIN_URL . 'public/js/quick-view.js',
        array('jquery'),
        '1.0.0',
        true
    );

    wp_localize_script('clover-quick-view', 'quickViewParams', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('clover_make_request')
    ));
}

// Show closed-store notice above the Place Order button in checkout
add_action('woocommerce_review_order_before_submit', 'clover_checkout_closed_notice');
function clover_checkout_closed_notice()
{
    if (get_option('clover_prevent_orders_when_closed', '0') !== '1') {
        return;
    }

    $business_hours = new \Src\BusinessHours\Business_Hours();
    $status         = $business_hours->get_business_status();

    // API error or store open — show nothing
    if (!empty($status['error']) || $status['open']) {
        return;
    }

    $when    = !empty($status['message']) ? $status['message'] : 'We are currently closed';
    $message = esc_html($when) . '. Orders cannot be placed while the store is closed.';

    echo '<div class="woocommerce-error clover-store-closed-notice" style="margin-bottom:16px;">'
        . $message
        . '</div>';
}

// Hard block at order submission when store is closed
add_action('woocommerce_checkout_process', 'clover_block_order_when_closed');
function clover_block_order_when_closed()
{
    if (get_option('clover_prevent_orders_when_closed', '0') !== '1') {
        return;
    }

    $business_hours = new \Src\BusinessHours\Business_Hours();
    $status         = $business_hours->get_business_status();

    // API error → fail open (don't block due to API downtime)
    if (!empty($status['error']) || $status['open']) {
        return;
    }

    $when = !empty($status['message']) ? $status['message'] : 'We are currently closed';
    wc_add_notice($when . '. Please place your order during business hours.', 'error');
}

// Helper function to check if a category is currently closed based on custom hours
function clover_is_category_closed($category_id)
{
    $enabled = get_term_meta($category_id, 'category_hours_enabled', true);
    if ($enabled !== 'yes') {
        return false; // No custom hours, so considered open
    }

    $hours_json = get_term_meta($category_id, 'category_opening_hours', true);
    if (empty($hours_json)) {
        return false;
    }

    $hours_data = json_decode($hours_json, true);
    if (!$hours_data || !isset($hours_data['elements'])) {
        return false;
    }

    $now = current_time('timestamp');
    $current_day = strtolower(date('l', $now));
    $current_minutes = (int)date('H', $now) * 60 + (int)date('i', $now);

    // Find day config
    $day_config = null;
    foreach ($hours_data['elements'] as $element) {
        if (isset($element[$current_day])) {
            $day_config = $element[$current_day];
            break;
        }
    }

    // If no config for today, it's closed
    if (!$day_config || !isset($day_config['elements'])) {
        return true;
    }

    foreach ($day_config['elements'] as $interval) {
        if (isset($interval['start']) && isset($interval['end'])) {
            // Stored as HHMM integer (e.g. 900 = 9:00 AM, 2200 = 10:00 PM).
            // Convert to minutes-since-midnight before comparing with $current_minutes.
            $start = (int)floor($interval['start'] / 100) * 60 + ($interval['start'] % 100);
            $end   = (int)floor($interval['end']   / 100) * 60 + ($interval['end']   % 100);

            if ($end < $start) { // Overnight
                if ($current_minutes >= $start || $current_minutes < $end) {
                    return false; // Open
                }
            } else {
                if ($current_minutes >= $start && $current_minutes < $end) {
                    return false; // Open
                }
            }
        }
    }

    return true; // Closed
}

/**
 * Get product availability based on category-specific hours only.
 * Returns ['available' => bool, 'message' => string].
 *
 * Rule: if a category has "Enable Category Hours = yes", its Opening Hours
 * determine availability. Categories without custom hours never restrict.
 */
function clover_get_product_availability($product_id)
{
    $categories = get_the_terms($product_id, 'product_cat');
    if (!$categories || is_wp_error($categories)) {
        return ['available' => true, 'message' => ''];
    }

    foreach ($categories as $cat) {
        // Only categories with custom hours enabled are evaluated
        $enabled = get_term_meta($cat->term_id, 'category_hours_enabled', true);
        if ($enabled !== 'yes') {
            continue;
        }

        if (clover_is_category_closed($cat->term_id)) {
            $business_hours = new \Src\BusinessHours\Business_Hours();
            $cat_status     = $business_hours->get_business_status($cat->term_id);
            $msg            = !empty($cat_status['message']) ? $cat_status['message'] : 'Currently unavailable';
            return ['available' => false, 'message' => $msg];
        }
    }

    return ['available' => true, 'message' => ''];
}

// ── Single product page & quick view ─────────────────────────────────────────
// Outputs a hidden input (read by modifier-system JS) + visible notice.
add_action('woocommerce_before_add_to_cart_button', 'clover_output_availability_check', 5);
function clover_output_availability_check()
{
    global $product;
    if (!$product) return;

    $avail = clover_get_product_availability($product->get_id());
    if (!$avail['available']) {
        echo '<input type="hidden" id="category-hours-closed" value="1">';
        echo '<input type="hidden" id="category-hours-message" value="' . esc_attr($avail['message']) . '">';
        echo '<p class="category-hours-notice" style="color:#dc3545;font-size:0.9em;font-weight:500;margin:0 0 10px 0;">' . esc_html($avail['message']) . '</p>';
    }
}

// ── Shop loop product cards ───────────────────────────────────────────────────
// Adds CSS class + aria-disabled to the loop Add to Cart button.
add_filter('woocommerce_loop_add_to_cart_args', 'clover_loop_add_to_cart_args', 10, 2);
function clover_loop_add_to_cart_args($args, $product)
{
    $avail = clover_get_product_availability($product->get_id());
    if (!$avail['available']) {
        $args['class'] .= ' category-unavailable';
        $args['attributes']['aria-disabled']        = 'true';
        $args['attributes']['data-unavailable-msg'] = $avail['message'];
    }
    return $args;
}

// Outputs unavailability message below loop button (priority 11 = after WC button at 10).
add_action('woocommerce_after_shop_loop_item', 'clover_loop_unavailability_message', 11);
function clover_loop_unavailability_message()
{
    global $product;
    if (!$product) return;

    $avail = clover_get_product_availability($product->get_id());
    if (!$avail['available']) {
        echo '<p class="category-hours-notice" style="color:#dc3545;font-size:0.8em;margin:4px 0 0;text-align:center;">' . esc_html($avail['message']) . '</p>';
    }
}

// AJAX handler for API requests
function clover_handle_api_request()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_die('Security check failed');
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }

    $method = sanitize_text_field($_POST['method']);
    $endpoint = sanitize_text_field($_POST['endpoint']);
    $body = isset($_POST['body']) ? json_decode(stripslashes($_POST['body']), true) : array();

    // Validate method
    if (!in_array(strtoupper($method), array('GET', 'POST', 'PUT', 'DELETE'))) {
        wp_send_json_error(array('error' => 'Invalid HTTP method'));
    }

    $client = new Clover_HttpClient();

    switch (strtoupper($method)) {
        case 'GET':
            $response = $client->get($endpoint);
            break;
        case 'POST':
            $response = $client->post($endpoint, $body);
            break;
        case 'PUT':
            $response = $client->put($endpoint, $body);
            break;
        case 'DELETE':
            $response = $client->delete($endpoint);
            break;
        default:
            wp_send_json_error(array('error' => 'Unsupported method'));
    }

    if (is_wp_error($response)) {
        wp_send_json_error(array('error' => $response->get_error_message()));
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    wp_send_json_success(array(
        'status_code' => $status_code,
        'body' => $body
    ));
}

// Register activation hook
register_activation_hook(__FILE__, 'clover_plugin_activate');

function clover_plugin_activate()
{
    // Set default options
    add_option('clover_merchid', '');
    add_option('clover_token', '');
    add_option('clover_api_base_url', 'https://api.clover.com/v3/merchants/');
    add_option('clover_auto_print_orders', '1');  // Default to enabled
}

// Register deactivation hook
register_deactivation_hook(__FILE__, 'clover_plugin_deactivate');

function clover_plugin_deactivate()
{
    // Cleanup if needed
}

// AJAX handler for importing items
function clover_import_items_ajax()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_import_products_nonce')) {
        wp_send_json_error(array('error' => 'Nonce inválido'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'No tienes permisos'));
    }

    // Validate WooCommerce
    if (!class_exists('WooCommerce')) {
        wp_send_json_error(array('error' => 'WooCommerce no está activo.'));
    }

    // Get selected categories
    $selected_categories = $_POST['categories'] ?? [];
    $selected_categories = array_map('sanitize_text_field', $selected_categories);

    // Validate that there's a selection
    if (empty($selected_categories)) {
        wp_send_json_error(array('error' => 'Debes seleccionar al menos una categoría para importar.'));
    }

    // Include the admin class to access the import methods
    require_once CLOVER_PLUGIN_PATH . 'includes/class-admin.php';
    $admin = new Clover_Admin();

    // Process each selected category
    $total_imported = 0;
    $errors = array();

    foreach ($selected_categories as $cat) {
        try {
            // Get the service instance
            $config = require CLOVER_PLUGIN_PATH . 'config/api.php';
            $itemService = new \Src\Services\OrderService($config);

            $resp = $itemService->getItemsByCategory($cat);
            $data = $resp['data'];
            $result = $data['elements'] ?? [];

            $categoryItem = $itemService->getCategory($cat);

            if (empty($result)) {
                $errors[] = 'No se encontraron productos en la categoría: ' . ($categoryItem['data']['name'] ?? $cat);
                continue;
            }

            foreach ($result as $i) {
                $admin->create_product_from_item($i, $categoryItem['data']['name']);
                $total_imported++;
            }
        } catch (Exception $e) {
            $errors[] = "Error procesando la categoría {$cat}: " . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        wp_send_json_error(array(
            'error' => implode('<br>', $errors),
            'total_imported' => $total_imported
        ));
    } else {
        wp_send_json_success(array(
            'message' => "Productos importados correctamente. Total: {$total_imported}",
            'total_imported' => $total_imported
        ));
    }
}

// AJAX handler for testing API connection
function clover_test_connection()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    // Get API credentials from options
    $merchid = get_option('clover_merchid');
    $token = get_option('clover_token');
    $base_url = get_option('clover_api_base_url', 'https://api.clover.com/v3/merchants/');

    // Validate credentials
    if (empty($merchid) || empty($token)) {
        wp_send_json_error(array('error' => 'Missing Merchid or Token'));
    }

    // Prepare headers
    $headers = array(
        'Authorization' => 'Bearer ' . $token,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json'
    );

    // Construct the test URL - try to get merchant info
    $url = rtrim($base_url, '/') . '/' . $merchid;

    // Prepare request arguments
    $args = array(
        'method' => 'GET',
        'headers' => $headers,
        'timeout' => 30,
        'httpversion' => '1.1',
    );

    // Make the request using WordPress HTTP API
    $response = wp_remote_request($url, $args);

    if (is_wp_error($response)) {
        wp_send_json_error(array('error' => $response->get_error_message()));
    }

    $status_code = wp_remote_retrieve_response_code($response);

    if ($status_code === 200) {
        wp_send_json_success(array(
            'message' => 'Connection successful!',
            'status_code' => $status_code
        ));
    } else {
        // Different status codes might indicate different types of errors
        $body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($body, true);

        $error_message = 'Connection failed';
        if ($decoded_body && isset($decoded_body['message'])) {
            $error_message .= ': ' . $decoded_body['message'];
        } elseif (!empty($body)) {
            $error_message .= ': ' . $body;
        }

        wp_send_json_error(array(
            'error' => $error_message,
            'status_code' => $status_code
        ));
    }
}

/**
 * AJAX handler to reload employees from Clover API
 */
function clover_reload_employees()
{
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    $config = require CLOVER_PLUGIN_PATH . 'config/api.php';
    $orderService = new \Src\Services\OrderService($config);

    try {
        $employeesResponse = $orderService->getEmployees();
        $employees = array();

        if (isset($employeesResponse['data']['elements']) && is_array($employeesResponse['data']['elements'])) {
            foreach ($employeesResponse['data']['elements'] as $employee) {
                $full_name = trim(
                    isset($employee['name']) && $employee['name'] !== ''
                        ? $employee['name']
                        : (($employee['firstName'] ?? '') . ' ' . ($employee['lastName'] ?? ''))
                );
                $employees[] = array(
                    'id'   => $employee['id'] ?? '',
                    'name' => $full_name ?: ($employee['id'] ?? ''),
                );
            }
        }

        wp_send_json_success(array('employees' => $employees));
    } catch (Exception $e) {
        wp_send_json_error(array('error' => $e->getMessage()));
    }
}

/**
 * AJAX handler to get plugin logs
 */
function clover_get_logs()
{
    if (!wp_verify_nonce($_POST['nonce'], 'clover_logs_nonce')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    require_once CLOVER_PLUGIN_PATH . 'includes/utilities/class-clover-logger.php';

    $logs = Clover_Logger::get_logs(7);
    $combined = '';

    foreach (array_reverse($logs) as $date => $content) {
        if (!empty($content)) {
            $combined .= "=== {$date} ===\n" . $content . "\n";
        }
    }

    wp_send_json_success(array('logs' => $combined));
}

/**
 * AJAX handler to clear logs
 */
function clover_clear_logs()
{
    if (!wp_verify_nonce($_POST['nonce'], 'clover_logs_nonce')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    require_once CLOVER_PLUGIN_PATH . 'includes/utilities/class-clover-logger.php';

    $type = $_POST['type'] ?? 'old';

    if ($type === 'current') {
        Clover_Logger::clear_current_log();
        wp_send_json_success(array('message' => 'Current log cleared'));
    } else {
        Clover_Logger::clear_old_logs(30);
        wp_send_json_success(array('message' => 'Old logs cleared'));
    }
}

add_action('wp_ajax_clover_reload_employees', 'clover_reload_employees');

/**
 * AJAX handler to reload Clover devices (for printer selection)
 */
function clover_reload_devices()
{
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    $config       = require CLOVER_PLUGIN_PATH . 'config/api.php';
    $orderService = new \Src\Services\OrderService($config);

    try {
        $response = $orderService->getDevices();
        $devices  = array();

        if (isset($response['data']['elements']) && is_array($response['data']['elements'])) {
            foreach ($response['data']['elements'] as $device) {
                $label     = $device['name'] ?? ($device['serial'] ?? ($device['id'] ?? ''));
                $serial    = $device['serial'] ?? '';
                if (!empty($serial)) {
                    $label .= ' (' . $serial . ')';
                }
                $devices[] = array(
                    'id'    => $device['id']    ?? '',
                    'label' => $label,
                    'serial'=> $serial,
                );
            }
        }

        wp_send_json_success(array('devices' => $devices));
    } catch (Exception $e) {
        wp_send_json_error(array('error' => $e->getMessage()));
    }
}

add_action('wp_ajax_clover_reload_devices', 'clover_reload_devices');

/**
 * AJAX handler to reload Clover order types
 */
function clover_reload_order_types()
{
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    $config       = require CLOVER_PLUGIN_PATH . 'config/api.php';
    $orderService = new \Src\Services\OrderService($config);

    try {
        $response    = $orderService->getOrderTypes();
        $order_types = array();

        if (isset($response['data']['elements']) && is_array($response['data']['elements'])) {
            foreach ($response['data']['elements'] as $ot) {
                $order_types[] = array(
                    'id'    => $ot['id'] ?? '',
                    'label' => $ot['label'] ?? ($ot['name'] ?? ($ot['id'] ?? '')),
                );
            }
        }

        wp_send_json_success(array('order_types' => $order_types));
    } catch (Exception $e) {
        wp_send_json_error(array('error' => $e->getMessage()));
    }
}

add_action('wp_ajax_clover_reload_order_types', 'clover_reload_order_types');

/**
 * AJAX handler to reload tenders
 */
function clover_reload_tenders()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    $config = require CLOVER_PLUGIN_PATH . 'config/api.php';
    $orderService = new \Src\Services\OrderService($config);

    try {
        $tendersResponse = $orderService->getTenders();
        $tenders = array();

        if (isset($tendersResponse['data']['elements']) && is_array($tendersResponse['data']['elements'])) {
            foreach ($tendersResponse['data']['elements'] as $tender) {
                $tenders[] = array(
                    'id' => $tender['id'] ?? '',
                    'label' => $tender['label'] ?? ($tender['name'] ?? ''),
                    'name' => $tender['name'] ?? ''
                );
            }
        }

        wp_send_json_success(array('tenders' => $tenders));
    } catch (Exception $e) {
        wp_send_json_error(array('error' => $e->getMessage()));
    }
}

add_action('wp_ajax_clover_reload_tax_rates', 'clover_reload_tax_rates');

function clover_reload_tax_rates()
{
    if (!wp_verify_nonce($_POST['nonce'], 'clover_reload_tax_rates')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    $config       = require CLOVER_PLUGIN_PATH . 'config/api.php';
    $orderService = new \Src\Services\OrderService($config);

    try {
        $response  = $orderService->getTaxRates();
        $tax_rates = array();

        if (isset($response['data']['elements']) && is_array($response['data']['elements'])) {
            foreach ($response['data']['elements'] as $t) {
                $rate_raw   = isset($t['rate']) ? intval($t['rate']) : 0;
                $percent    = round($rate_raw / 100000, 4) + 0;
                $tax_rates[] = array(
                    'id'         => $t['id'] ?? '',
                    'name'       => $t['name'] ?? 'Unnamed',
                    'percent'    => $percent,
                    'is_default' => !empty($t['isDefault']),
                );
            }
        }

        wp_send_json_success(array('tax_rates' => $tax_rates));
    } catch (Exception $e) {
        wp_send_json_error(array('error' => $e->getMessage()));
    }
}

add_action('wp_ajax_clover_reload_discounts', 'clover_reload_discounts');

function clover_reload_discounts()
{
    if (!wp_verify_nonce($_POST['nonce'], 'clover_reload_discounts')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    $config       = require CLOVER_PLUGIN_PATH . 'config/api.php';
    $orderService = new \Src\Services\OrderService($config);

    try {
        $response  = $orderService->getDiscounts();
        $discounts = array();

        if (isset($response['data']['elements']) && is_array($response['data']['elements'])) {
            foreach ($response['data']['elements'] as $d) {
                $discounts[] = array(
                    'id'         => $d['id'] ?? '',
                    'name'       => $d['name'] ?? ($d['id'] ?? ''),
                    'percentage' => isset($d['percentage']) ? intval($d['percentage']) : null,
                    'amount'     => isset($d['amount']) ? intval($d['amount']) : null,
                );
            }
        }

        wp_send_json_success(array('discounts' => $discounts));
    } catch (Exception $e) {
        wp_send_json_error(array('error' => $e->getMessage()));
    }
}

// AJAX handlers for getting items, categories, modifiers, and modifier groups

function clover_get_items()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    $config = require CLOVER_PLUGIN_PATH . 'config/api.php';
    $service = new \Src\Services\OrderService($config);

    try {
        // Fetch items with categories expanded
        $response = $service->getItems(array(
            'limit' => 1000,
            'expand' => 'categories'
        ));
        $items = array();

        if (isset($response['data']['elements']) && is_array($response['data']['elements'])) {
            foreach ($response['data']['elements'] as $element) {
                $items[] = array(
                    'id' => $element['id'] ?? '',
                    'name' => $element['name'] ?? 'Sin nombre',
                    'price' => $element['price'] ?? 0,
                    'categories' => $element['categories'] ?? null
                );
            }
        } elseif (is_array($response['data'])) {
            // Handle case where elements are directly in data
            foreach ($response['data'] as $element) {
                $items[] = array(
                    'id' => $element['id'] ?? '',
                    'name' => $element['name'] ?? 'Sin nombre',
                    'price' => $element['price'] ?? 0,
                    'categories' => $element['categories'] ?? null
                );
            }
        }

        wp_send_json_success(array('items' => $items));
    } catch (Exception $e) {
        wp_send_json_error(array('error' => $e->getMessage()));
    }
}

function clover_get_categories()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    $headers = array(
        'Authorization' => 'Bearer ' . get_option('clover_token'),
        'Content-Type' => 'application/json',
        'Accept' => 'application/json'
    );

    $url = rtrim(get_option('clover_api_base_url', 'https://api.clover.com/v3/merchants/'), '/') . '/' . get_option('clover_merchid') . '/categories';
    $response = wp_remote_get($url, array(
        'headers' => $headers,
        'timeout' => 30
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array('error' => $response->get_error_message()));
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    $categories = array();
    if (isset($data['elements']) && is_array($data['elements'])) {
        foreach ($data['elements'] as $element) {
            $categories[] = array(
                'id' => $element['id'] ?? '',
                'name' => $element['name'] ?? $element['label'] ?? 'Sin nombre'
            );
        }
    }

    wp_send_json_success(array('items' => $categories));
}

function clover_get_modifiers()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    $headers = array(
        'Authorization' => 'Bearer ' . get_option('clover_token'),
        'Content-Type' => 'application/json',
        'Accept' => 'application/json'
    );

    $url = rtrim(get_option('clover_api_base_url', 'https://api.clover.com/v3/merchants/'), '/') . '/' . get_option('clover_merchid') . '/modifiers';
    $response = wp_remote_get($url, array(
        'headers' => $headers,
        'timeout' => 30
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array('error' => $response->get_error_message()));
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    $modifiers = array();
    if (isset($data['elements']) && is_array($data['elements'])) {
        foreach ($data['elements'] as $element) {
            $modifiers[] = array(
                'id' => $element['id'] ?? '',
                'name' => $element['name'] ?? $element['label'] ?? 'Sin nombre'
            );
        }
    }

    wp_send_json_success(array('items' => $modifiers));
}

function clover_get_modifier_groups()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    $headers = array(
        'Authorization' => 'Bearer ' . get_option('clover_token'),
        'Content-Type' => 'application/json',
        'Accept' => 'application/json'
    );

    $url = rtrim(get_option('clover_api_base_url', 'https://api.clover.com/v3/merchants/'), '/') . '/' . get_option('clover_merchid') . '/modifier_groups';
    $response = wp_remote_get($url, array(
        'headers' => $headers,
        'timeout' => 30
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array('error' => $response->get_error_message()));
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    $modifier_groups = array();
    if (isset($data['elements']) && is_array($data['elements'])) {
        foreach ($data['elements'] as $element) {
            $modifier_groups[] = array(
                'id' => $element['id'] ?? '',
                'name' => $element['name'] ?? $element['label'] ?? 'Sin nombre'
            );
        }
    }

    wp_send_json_success(array('items' => $modifier_groups));
}

// AJAX handler to start the import process and return a process ID
function clover_start_import_items()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    $selected_items = isset($_POST['items']) ? $_POST['items'] : array();
    $selected_categories = isset($_POST['categories']) ? $_POST['categories'] : array();

    if (empty($selected_items)) {
        wp_send_json_error(array('error' => 'No items selected for import'));
    }

    // Create a progress tracker instance
    $tracker = new Clover_Progress_Tracker();
    $process_id = $tracker->get_process_id();

    // Initialize progress tracking
    $tracker->init(count($selected_items), 'Starting import process...');

    // Store the items to be processed in another transient
    $items_transient_key = 'clover_import_items_' . $process_id;
    set_transient($items_transient_key, $selected_items, 30 * MINUTE_IN_SECONDS);

    // Store selected categories if provided
    if (!empty($selected_categories)) {
        set_transient('clover_import_categories_' . $process_id, $selected_categories, 30 * MINUTE_IN_SECONDS);
    }

    // Fetch all selected items with expanded data in a single API call
    $config = require CLOVER_PLUGIN_PATH . 'config/api.php';
    $itemService = new \Src\Services\OrderService($config);

    // Create a comma-separated list of selected item IDs for the API call
    $item_ids = array_column($selected_items, 'id');
    $ids_param = implode(',', $item_ids);

    // Create the filter string for multiple item IDs
    $item_ids = array_column($selected_items, 'id');
    $ids_list = "'" . implode("','", $item_ids) . "'";

    // Build query parameters properly
    $query_params = array(
        'expand' => 'modifierGroups,categories,modifierGroups.modifiers',
        'filter' => "id in ({$ids_list})",
        'limit' => 1000
    );

    $query_string = http_build_query($query_params);
    $full_response = $itemService->getData('/items?' . $query_string);

    // Store the expanded data for later use
    $expanded_data_transient_key = 'clover_import_expanded_data_' . $process_id;
    set_transient($expanded_data_transient_key, $full_response, 30 * MINUTE_IN_SECONDS);

    // Return the process ID to the client
    // The frontend polling will handle all processing via clover_import_items_async
    wp_send_json_success(array(
        'process_id' => $process_id,
        'total_items' => count($selected_items)
    ));
}

// Function to process the next batch of items
function clover_process_next_item_batch($process_id)
{
    // Get the progress tracker
    $tracker = new Clover_Progress_Tracker($process_id);
    $progress_data = $tracker->get_progress();

    if (!$progress_data) {
        return array('error' => 'Invalid process ID');
    }

    // Get the items to process
    $items_transient_key = 'clover_import_items_' . $process_id;
    $selected_items = get_transient($items_transient_key);

    if (empty($selected_items)) {
        return array('error' => 'No items to process');
    }

    // Include the admin class to access the import methods
    require_once CLOVER_PLUGIN_PATH . 'includes/class-admin.php';
    $admin = new Clover_Admin();

    // Process the next batch of items (we'll process one at a time for better progress tracking)
    $processed_count = $progress_data['processed_items'];

    if ($processed_count < count($selected_items)) {
        $item = $selected_items[$processed_count];

        try {
            clover_log("Importing item {$item['id']}: {$item['name']}");

            // Get the pre-fetched expanded data
            $expanded_data_transient_key = 'clover_import_expanded_data_' . $process_id;
            $expanded_data_response = get_transient($expanded_data_transient_key);

            // Find the item data in the pre-fetched expanded response
            $formatted_item = null;
            if (isset($expanded_data_response['data']['elements']) && is_array($expanded_data_response['data']['elements'])) {
                foreach ($expanded_data_response['data']['elements'] as $element) {
                    if (isset($element['id']) && $element['id'] === $item['id']) {
                        $formatted_item = $element;
                        break;
                    }
                }
            }

            if (!$formatted_item) {
                // Fallback to minimal data if item not found in expanded response
                $formatted_item = array(
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'price' => 0
                );
                $total_modifier_count = 0;
                clover_log("Item {$item['id']} not found in expanded response, using fallback data");
            } else {
                clover_log("Successfully retrieved full item data for {$item['id']} from expanded response");

                // Check if categories filter is applied and validate item belongs to selected categories
                $selected_categories = get_transient('clover_import_categories_' . $process_id);
                if (!empty($selected_categories) && is_array($selected_categories)) {
                    $item_belongs_to_category = false;
                    $item_has_categories = false;

                    // Check if item has categories (must have non-empty elements array)
                    if (isset($formatted_item['categories']) && isset($formatted_item['categories']['elements']) && !empty($formatted_item['categories']['elements'])) {
                        $item_has_categories = true;
                        foreach ($formatted_item['categories']['elements'] as $cat) {
                            if (in_array($cat['id'], $selected_categories)) {
                                $item_belongs_to_category = true;
                                break;
                            }
                        }
                    }

                    // Skip this item ONLY if it has categories but none match the selected ones
                    // Items WITHOUT categories should ALWAYS be imported (assigned to Uncategorized)
                    if ($item_has_categories && !$item_belongs_to_category) {
                        clover_log("Item {$item['id']} has categories but none match selected categories, skipping...");

                        // Update processed count and continue to next item
                        $tracker->update_progress($item['id'], $item['name'], 'skipped', 'Item categories not selected');

                        // Return status - next poll will process next item
                        return array(
                            'status' => 'processing',
                            'progress' => $tracker->get_progress()
                        );
                    }

                    // Item either has no categories OR belongs to selected categories
                    if ($item_has_categories) {
                        clover_log("Item {$item['id']} belongs to selected categories, proceeding with import");
                    } else {
                        clover_log("Item {$item['id']} has NO categories, will be imported and assigned to Uncategorized");
                    }
                }

                // Extract modifier groups information to reuse later
                $modifier_groups_data = null;
                $total_modifier_count = 0;

                // First, get the total number of modifiers from the item data
                // With the new expansion (modifierGroups.modifiers), we can get the count directly from the modifiers array
                if (isset($formatted_item['modifierGroups']) && isset($formatted_item['modifierGroups']['elements']) && is_array($formatted_item['modifierGroups']['elements'])) {
                    $modifier_groups_data = $formatted_item['modifierGroups'];  // Store for later use
                    foreach ($formatted_item['modifierGroups']['elements'] as $element) {
                        // Count modifiers directly from the expanded modifiers array
                        if (isset($element['modifiers']) && isset($element['modifiers']['elements']) && is_array($element['modifiers']['elements'])) {
                            $total_modifier_count += count($element['modifiers']['elements']);
                        }
                    }
                } elseif (isset($formatted_item['modifiers']) && is_array($formatted_item['modifiers'])) {
                    $total_modifier_count = count($formatted_item['modifiers']);
                } elseif (isset($formatted_item['itemModifiers']) && is_array($formatted_item['itemModifiers'])) {
                    $total_modifier_count = count($formatted_item['itemModifiers']);
                }
            }

            // Update progress to indicate we're creating the product
            $tracker->update_detailed_progress($item['id'], $item['name'], 'Creating Product', 'processing', 'Creating WooCommerce product...');

            // Create the product with complete item data
            $admin->create_product_from_item($formatted_item);

            // Update progress to indicate we're importing modifiers
            $tracker->update_detailed_progress($item['id'], $item['name'], 'Importing Modifiers', 'processing', 'Importing modifiers for product...');

            // Import modifiers for this product after saving
            $product_id = wc_get_product_id_by_sku($item['id']);
            $imported_modifier_count = 0;

            // Use the modifier groups data that was extracted earlier to avoid a second API call

            if ($product_id) {
                try {
                    // Add detailed logging
                    clover_log("Starting modifier import for item {$item['id']} (product ID: {$product_id}), total modifiers: {$total_modifier_count}");

                    // Add a timeout mechanism to prevent hanging
                    $tracker->update_detailed_progress($item['id'], $item['name'], 'Importing Modifiers', 'processing', "Importing modifiers for product... ({$total_modifier_count} total modifiers)");

                    // Process modifiers using the data we already have from the initial API call
                    $imported_modifier_count = 0;

                    if (isset($modifier_groups_data) && $modifier_groups_data) {
                        // Format the modifiers from the data we already have
                        $formatted_modifiers = array();
                        
                        // Get import fee settings
                        $fee_enabled = get_option('clover_import_fee_enabled', '0');
                        $fee_percent = get_option('clover_import_fee_percent', '20');
                        $apply_fee = ($fee_enabled === '1' && $fee_percent > 0);
                        $fee_multiplier = 1 + (floatval($fee_percent) / 100);

                        if (isset($modifier_groups_data['elements']) && is_array($modifier_groups_data['elements'])) {
                            foreach ($modifier_groups_data['elements'] as $element) {
                                // Process modifiers that are already available in the expanded response
                                if (isset($element['modifiers']) && isset($element['modifiers']['elements']) && is_array($element['modifiers']['elements'])) {
                                    foreach ($element['modifiers']['elements'] as $modifier) {
                                        if (isset($modifier['id'], $modifier['name'], $modifier['price'])) {
                                            $modifier_price = floatval($modifier['price'] / 100);  // Convert from cents to dollars
                                            $original_modifier_price = $modifier_price;  // Store original before fee
                                            
                                            // Apply import fee if enabled
                                            if ($apply_fee) {
                                                $modifier_price = $modifier_price * $fee_multiplier;
                                            }
                                            
                                            $formatted_modifiers[] = array(
                                                'id' => sanitize_text_field($modifier['id']),
                                                'name' => wp_slash(sanitize_text_field($modifier['name'])),  // Escape quotes for JSON
                                                'price' => $modifier_price,  // Price with fee applied
                                                'original_price' => $original_modifier_price,  // Original price from Clover (before fee)
                                                'clover_id' => sanitize_text_field($modifier['id']),
                                                'modifier_group_id' => sanitize_text_field($element['id'] ?? ''),
                                                'modifier_group_name' => wp_slash(sanitize_text_field($element['name'] ?? ''))  // Escape quotes for JSON
                                            );

                                            $imported_modifier_count++;
                                        }
                                    }
                                } else {
                                    clover_log("No modifiers found in group {$element['id']} for item {$item['id']} in the expanded response");
                                }
                            }
                        }

                        // Process modifier group constraints (minRequired and maxAllowed)
                        $modifier_constraints = array();
                        if (isset($formatted_item['modifierGroups']) && isset($formatted_item['modifierGroups']['elements']) && is_array($formatted_item['modifierGroups']['elements'])) {
                            foreach ($formatted_item['modifierGroups']['elements'] as $element) {
                                $constraint = array();
                                if (isset($element['minRequired'])) {
                                    $constraint['minRequired'] = $element['minRequired'];
                                }
                                if (isset($element['maxAllowed'])) {
                                    $constraint['maxAllowed'] = $element['maxAllowed'];
                                }

                                // Only add constraint if at least one limit is defined
                                if (!empty($constraint)) {
                                    $constraint['id'] = $element['id'] ?? '';
                                    // Properly escape name for JSON (handles quotes like 18")
                                    $constraint['name'] = wp_slash(sanitize_text_field($element['name'] ?? ''));
                                    $modifier_constraints[] = $constraint;
                                }
                            }
                        }

                        // Save the modifiers to the product
                        $modifiers_json = json_encode($formatted_modifiers, JSON_UNESCAPED_SLASHES);
                        update_post_meta($product_id, '_clover_modifiers', $modifiers_json);

                        // Save modifier group constraints if they exist
                        if (!empty($modifier_constraints)) {
                            update_post_meta($product_id, '_clover_modifier_constraints', json_encode($modifier_constraints, JSON_UNESCAPED_SLASHES));
                        }

                        clover_log("Successfully imported {$imported_modifier_count} modifiers for product ID: {$product_id}");
                        clover_log("Saved modifiers JSON for product {$product_id}: " . $modifiers_json);
                        if (!empty($modifier_constraints)) {
                            clover_log("Saved modifier constraints for product {$product_id}: " . json_encode($modifier_constraints));
                        }
                    } else {
                        clover_log("No modifier groups data available for item {$item['id']}");
                    }

                    $tracker->update_detailed_progress($item['id'], $item['name'], 'Importing Modifiers', 'success', "Modifiers imported successfully ({$imported_modifier_count}/{$total_modifier_count})");
                } catch (Exception $e) {
                    clover_log("Error importing modifiers for product {$product_id} (SKU: {$item['id']}): " . $e->getMessage() . ' in file ' . $e->getFile() . ' on line ' . $e->getLine());
                    $tracker->update_detailed_progress($item['id'], $item['name'], 'Importing Modifiers', 'warning', 'Modifiers import failed: ' . $e->getMessage());
                }
            } else {
                clover_log("Skipping modifier import for item {$item['id']}, product ID not found");
                $tracker->update_detailed_progress($item['id'], $item['name'], 'Importing Modifiers', 'skipped', 'No modifiers to import or product not found');
            }

            // Update progress for successful import - this should increment the processed count
            $tracker->update_detailed_progress_final($item['id'], $item['name'], 'Completed', 'success', "Product and {$imported_modifier_count}/{$total_modifier_count} modifiers imported successfully");

            clover_log("Successfully created product and imported modifiers for item {$item['id']}");
        } catch (Throwable $e) {
            clover_log("Error importing item {$item['id']}: " . $e->getMessage() . ' in file ' . $e->getFile() . ' on line ' . $e->getLine());
            // Record error but continue processing
            $tracker->record_error($item['id'], $item['name'], $e->getMessage());
        }

        // Check if we've completed all items (Phase 1 - products)
        $updated_progress = $tracker->get_progress();
        if ($updated_progress['processed_items'] >= $updated_progress['total_items']) {
            // Check current phase
            $current_phase = $tracker->get_phase();

            if ($current_phase === 'importing_products') {
                // Phase 1 complete, transition to Phase 2 (images)
                $tracker->set_phase('importing_images');
                $tracker->complete('Products imported. Now importing images...');

                return array(
                    'status' => 'importing_images',
                    'progress' => $updated_progress,
                    'phase' => 'importing_images'
                );
            } else {
                // Phase 2 complete, truly done
                $tracker->complete('Import completed successfully');
                delete_transient($items_transient_key);

                return array(
                    'status' => 'completed',
                    'progress' => $updated_progress
                );
            }
        }

        return array(
            'status' => 'processing',
            'progress' => $updated_progress
        );
    } else {
        // No more items to process in Phase 1
        $current_phase = $tracker->get_phase();

        if ($current_phase === 'importing_products') {
            // Phase 1 complete, transition to Phase 2
            $tracker->set_phase('importing_images');
            $tracker->complete('Products imported. Now importing images...');

            return array(
                'status' => 'importing_images',
                'progress' => $tracker->get_progress(),
                'phase' => 'importing_images'
            );
        } else {
            // Phase 2 complete
            if (empty($progress_data['completed'])) {
                $tracker->complete('Import completed successfully');
                delete_transient($items_transient_key);
            }

            return array(
                'status' => 'completed',
                'progress' => $tracker->get_progress()
            );
        }
    }
}

// AJAX handler for importing items asynchronously
function clover_import_items_async()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    $process_id = sanitize_text_field($_POST['process_id']);

    if (empty($process_id)) {
        wp_send_json_error(array('error' => 'Process ID is required'));
    }

    // Get the progress tracker
    $tracker = new Clover_Progress_Tracker($process_id);
    $progress_data = $tracker->get_progress();

    if (!$progress_data) {
        wp_send_json_error(array('error' => 'Invalid process ID'));
    }

    // Get the items to process
    $items_transient_key = 'clover_import_items_' . $process_id;
    $selected_items = get_transient($items_transient_key);

    if (empty($selected_items)) {
        // If no items transient exists, check if process is already complete
        if ($progress_data['processed_items'] >= $progress_data['total_items']) {
            $tracker->complete('Import completed successfully');
            wp_send_json_success(array(
                'status' => 'completed',
                'progress' => $progress_data
            ));
        } else {
            wp_send_json_error(array('error' => 'No items to process'));
        }
    }

    // Include the admin class to access the import methods
    require_once CLOVER_PLUGIN_PATH . 'includes/class-admin.php';
    $admin = new Clover_Admin();

    // Check current phase
    $current_phase = $tracker->get_phase();

    // Phase 2: Import images
    if ($current_phase === 'importing_images') {
        $image_start = isset($progress_data['images_processed']) ? $progress_data['images_processed'] : 0;

        if ($image_start < count($selected_items)) {
            $result = $admin->import_product_images_batch($selected_items, $tracker, $image_start);

            $updated_progress = $tracker->get_progress();

            if ($result['status'] === 'completed') {
                // All images processed, mark as complete
                $tracker->complete('Import completed successfully');
                delete_transient($items_transient_key);

                wp_send_json_success(array(
                    'status' => 'completed',
                    'progress' => $updated_progress
                ));
            } else {
                // Still processing images
                wp_send_json_success(array(
                    'status' => 'importing_images',
                    'progress' => $updated_progress,
                    'phase' => 'importing_images'
                ));
            }
        } else {
            // All images processed
            $tracker->complete('Import completed successfully');
            delete_transient($items_transient_key);

            wp_send_json_success(array(
                'status' => 'completed',
                'progress' => $progress_data
            ));
        }
    }

    // Phase 1: Process products (one per call)
    $result = clover_process_next_item_batch($process_id);

    // Send the result to the frontend
    wp_send_json_success($result);
}

// AJAX handler to get current progress
function clover_get_import_progress()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    $process_id = sanitize_text_field($_POST['process_id']);

    if (empty($process_id)) {
        wp_send_json_error(array('error' => 'Process ID is required'));
    }

    // Get the progress tracker
    $tracker = new Clover_Progress_Tracker($process_id);
    $progress_data = $tracker->get_progress();

    if (!$progress_data) {
        wp_send_json_error(array('error' => 'Invalid process ID'));
    }

    // Check if the process has timed out
    if (isset($progress_data['timed_out']) && $progress_data['timed_out']) {
        wp_send_json_error(array('error' => 'Process has timed out. Please restart the import.', 'progress' => $progress_data));
    }

    // Get recent activities
    $recent_activities = $tracker->get_recent_activities(8);

    wp_send_json_success(array(
        'progress' => $progress_data,
        'percentage' => $tracker->get_percentage(),
        'recent_activities' => $recent_activities
    ));
}

// AJAX handlers for importing selected items
function clover_import_selected_items()
{
    // This function now just starts the process and returns a process ID
    // The actual processing happens in clover_import_items_async

    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    $selected_items = isset($_POST['items']) ? $_POST['items'] : array();

    if (empty($selected_items)) {
        wp_send_json_error(array('error' => 'No items selected for import'));
    }

    // Create a progress tracker instance
    $tracker = new Clover_Progress_Tracker();
    $process_id = $tracker->get_process_id();

    // Initialize progress tracking
    $tracker->init(count($selected_items), 'Starting import process...');

    // Store the items to be processed in another transient
    $items_transient_key = 'clover_import_items_' . $process_id;
    set_transient($items_transient_key, $selected_items, 30 * MINUTE_IN_SECONDS);

    // Return the process ID to the client
    wp_send_json_success(array(
        'process_id' => $process_id,
        'total_items' => count($selected_items)
    ));
}

function clover_import_selected_categories()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    $selected_items = isset($_POST['items']) ? $_POST['items'] : array();

    if (empty($selected_items)) {
        wp_send_json_error(array('error' => 'No categories selected for import'));
    }

    $total_imported = 0;
    $errors = array();

    foreach ($selected_items as $item) {
        try {
            // Create or update WooCommerce product category
            $term = get_term_by('slug', sanitize_title($item['name']), 'product_cat');

            if (!$term) {
                $result = wp_insert_term(
                    $item['name'],
                    'product_cat',
                    array(
                        'slug' => sanitize_title($item['name'])
                    )
                );

                if (!is_wp_error($result)) {
                    $total_imported++;
                } else {
                    $errors[] = "Error creating category {$item['name']}: " . $result->get_error_message();
                }
            } else {
                // Category already exists
                $total_imported++;
            }
        } catch (Exception $e) {
            $errors[] = "Error importing category {$item['id']}: " . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        wp_send_json_error(array(
            'error' => implode('<br>', $errors),
            'total_imported' => $total_imported
        ));
    } else {
        wp_send_json_success(array(
            'message' => "Categories imported successfully. Total: {$total_imported}",
            'total_imported' => $total_imported
        ));
    }
}

function clover_import_selected_modifiers()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    $selected_items = isset($_POST['items']) ? $_POST['items'] : array();

    if (empty($selected_items)) {
        wp_send_json_error(array('error' => 'No modifiers selected for import'));
    }

    // For now, just return success since modifiers would require more complex implementation
    // This would typically involve creating custom fields or using a plugin that supports modifiers
    wp_send_json_success(array(
        'message' => 'Modifiers selected for import (implementation pending)',
        'total_imported' => count($selected_items)
    ));
}

function clover_import_selected_modifier_groups()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    $selected_items = isset($_POST['items']) ? $_POST['items'] : array();

    if (empty($selected_items)) {
        wp_send_json_error(array('error' => 'No modifier groups selected for import'));
    }

    // For now, just return success since modifier groups would require more complex implementation
    wp_send_json_success(array(
        'message' => 'Modifier groups selected for import (implementation pending)',
        'total_imported' => count($selected_items)
    ));
}

// Add the new AJAX actions to the initialization function
function clover_add_new_ajax_handlers()
{
    add_action('wp_ajax_clover_get_items', 'clover_get_items');
    add_action('wp_ajax_clover_get_categories', 'clover_get_categories');
    add_action('wp_ajax_clover_get_modifiers', 'clover_get_modifiers');
    add_action('wp_ajax_clover_get_modifier_groups', 'clover_get_modifier_groups');
    add_action('wp_ajax_clover_import_selected_items', 'clover_import_selected_items');
    add_action('wp_ajax_clover_start_import_items', 'clover_start_import_items');
    add_action('wp_ajax_clover_import_items_async', 'clover_import_items_async');
    add_action('wp_ajax_clover_get_import_progress', 'clover_get_import_progress');
    add_action('wp_ajax_clover_import_selected_categories', 'clover_import_selected_categories');
    add_action('wp_ajax_clover_import_selected_modifiers', 'clover_import_selected_modifiers');
    add_action('wp_ajax_clover_import_selected_modifier_groups', 'clover_import_selected_modifier_groups');
    add_action('wp_ajax_clover_cancel_import', 'clover_cancel_import');
}

add_action('plugins_loaded', 'clover_add_new_ajax_handlers');

// Función AJAX para importar todos los clientes

function clover_import_all_customers()
{
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(['error' => 'Security check failed']);
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['error' => 'Insufficient permissions']);
    }

    if (!class_exists('WooCommerce')) {
        wp_send_json_error(['error' => 'WooCommerce is not active.']);
    }
    clover_import_all_customers_logic();
}

function clover_import_all_customers_logic()
{
    $config = require CLOVER_PLUGIN_PATH . 'config/api.php';
    $service = new \Src\Services\CustomerService($config);

    try {
        //  Solo customers con teléfono
        $customers = $service->getCustomersWithEmail();
        $total = count($customers);
        clover_log('Total de clientes ' . $total);

        if (empty($customers)) {
            wp_send_json_error(['error' => 'No customers with phone found.']);
        }

        $total_imported = 0;
        $errors = [];
        // clover_log(print_r($customers,true));
        // clover_log(gettype($customers));
        update_option('_clover_is_importing', 1);
        foreach ($customers as $customerData) {
            try {
                // $customer = \Src\Domain\Customer::fromArray($customerData);
                $user_id = create_customer_from_clover_data($customerData);
                clover_log(print_r('IDs ' . $customerData->getId(), true));

                if ($user_id) {
                    $total_imported++;
                } else {
                    clover_log(print_r('No se anadio ' . $customerData->getId(), true));
                }
            } catch (Exception $e) {
                $errors[] = "Error importing customer {$customerData->getId()}: " . $e->getMessage();
            }
        }
        delete_option('_clover_is_importing');

        if (!empty($errors)) {
            wp_send_json_error([
                'error' => implode('<br>', $errors),
                'total_imported' => $total_imported
            ]);
        }

        wp_send_json_success([
            'message' => "Customers imported successfully. Total: {$total_imported}",
            'total_imported' => $total_imported
        ]);
    } catch (Exception $e) {
        wp_send_json_error(['error' => 'Error retrieving customers: ' . $e->getMessage()]);
    }
}

function create_customer_from_clover_data(Customer $customer)
{
    $clover_id = $customer->getId();

    $first_name = $customer->getFirstName();
    $last_name = $customer->getLastName();
    $email = sanitize_email($customer->getEmail());

    $primaryPhone = $customer->getPrimaryPhone();

    $phone = $primaryPhone ? $primaryPhone->getPhoneNumber() : '';
    $phoneId = $primaryPhone ? $primaryPhone->getId() : '';

    $address = $customer->getAddresses();

    if (empty($clover_id)) {
        return false;
    }

    /*
     * if (empty($email)) {
     *         $email = generate_unique_email(
     *         $customer->getFirstName()
     *     );
     * }
     * clover_log('email '.$email);
     */

    /*
     * |--------------------------------------------------------------------------
     * | 1. Buscar por clover_customer_id
     * |--------------------------------------------------------------------------
     */
    $users = get_users([
        'meta_key' => 'clover_customer_id',
        'meta_value' => $clover_id,
        'number' => 1,
        'count_total' => false
    ]);

    $existing_user = !empty($users)
        ? $users[0]
        : get_user_by('email', $email);

    // Comprobar si el usuario encontrado por email es distinto del cliente Clover

    /**
     * $existing_clover_id = get_user_meta($existing_user->ID, 'clover_customer_id', true);
     * if ($existing_user && $existing_clover_id && $existing_clover_id !== $clover_id)
     */
    if ($existing_user && $existing_user->ID && $existing_user->clover_customer_id !== $clover_id) {
        // Usuario diferente con el mismo correo
        clover_log("Otro usuario ya existe con el correo $email. ID existente: {$existing_user->ID}, Clover ID actual: $clover_id");
    }

    /*
     * |--------------------------------------------------------------------------
     * | 2. Si existe → actualizar
     * |--------------------------------------------------------------------------
     */
    if ($existing_user) {
        clover_log('Actualizando el usuario ' . $email);
        wp_update_user([
            'ID' => $existing_user->ID,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => trim("$first_name $last_name"),
        ]);

        update_user_meta($existing_user->ID, 'billing_first_name', $first_name);
        update_user_meta($existing_user->ID, 'billing_last_name', $last_name);
        update_user_meta($existing_user->ID, 'billing_email', $email);

        if ($primaryPhone) {
            update_user_meta($existing_user->ID, 'billing_phone', $phone);
            update_user_meta($existing_user->ID, 'clover_phone_id', $phoneId);
        }

        if ($address) {
            update_user_meta($existing_user->ID, 'billing_address_1', $address[0]->getAddress1());
            // update_user_meta($user_id, 'billing_address_2', $address->getAddress2());
            update_user_meta($existing_user->ID, 'billing_postcode', $address[0]->getZip());
            update_user_meta($existing_user->ID, 'billing_state', $address[0]->getState());
            update_user_meta($existing_user->ID, 'billing_city', $address[0]->getCity());
            update_user_meta($existing_user->ID, 'billing_country', $address[0]->getCountry());
            update_user_meta($existing_user->ID, 'clover_address_id', $address[0]->getId());
        }
        update_user_meta($existing_user->ID, 'clover_customer_id', $clover_id);

        return $existing_user->ID;
    }

    /*
     * |--------------------------------------------------------------------------
     * | 3. Crear nuevo usuario
     * |--------------------------------------------------------------------------
     */

    $username = sanitize_user($email, true);
    $original = $username;
    $counter = 1;

    while (username_exists($username)) {
        $username = $original . $counter++;
    }

    $password = wp_generate_password();
    $user_id = wp_create_user($username, $password, $email);

    if (is_wp_error($user_id)) {
        return false;
    }

    wp_update_user([
        'ID' => $user_id,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'display_name' => trim("$first_name $last_name"),
    ]);

    $user = new WP_User($user_id);
    $user->set_role('customer');

    update_user_meta($user_id, 'billing_first_name', $first_name);
    update_user_meta($user_id, 'billing_last_name', $last_name);
    update_user_meta($user_id, 'billing_email', $email);

    // clover_log('Telefono del usuario. '.$first_name.' '.$phone' '.$user_id);

    if ($primaryPhone) {
        update_user_meta($user_id, 'billing_phone', $phone);
        update_user_meta($user_id, 'clover_phone_id', $phoneId);
    }

    if ($address) {
        update_user_meta($user_id, 'billing_address_1', $address[0]->getAddress1());
        // update_user_meta($user_id, 'billing_address_2', $address->getAddress2());
        update_user_meta($user_id, 'billing_postcode', $address[0]->getZip());
        update_user_meta($user_id, 'billing_state', $address[0]->getState());
        update_user_meta($user_id, 'billing_city', $address[0]->getCity());
        update_user_meta($user_id, 'billing_country', $address[0]->getCountry());
        update_user_meta($user_id, 'clover_address_id', $address[0]->getId());
    }
    update_user_meta($user_id, 'clover_customer_id', $clover_id);

    return $user_id;
}

// Agregar las nuevas acciones AJAX
add_action('wp_ajax_clover_import_all_customers', 'clover_import_all_customers');
// add_action('wp_ajax_clover_import_single_customer', 'clover_import_single_customer');

// AJAX handler para sincronizar usuario individual a Clover
function clover_sync_single_user_to_clover()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(['error' => 'Security check failed']);
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['error' => 'Insufficient permissions']);
    }

    $user_id = intval($_POST['user_id']);
    if (!$user_id) {
        wp_send_json_error(['error' => 'User ID required']);
    }

    try {
        // Usar el servicio existente
        \Src\Customers\Services\CustomerSyncService::handleUpdate($user_id);

        wp_send_json_success([
            'message' => 'Usuario sincronizado con Clover correctamente'
        ]);
    } catch (Exception $e) {
        wp_send_json_error([
            'error' => 'Error al sincronizar: ' . $e->getMessage()
        ]);
    }
}

add_action('wp_ajax_clover_sync_single_user_to_clover', 'clover_sync_single_user_to_clover');

/**
 * AJAX handler to cancel an import process
 */
function clover_cancel_import()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('error' => 'Insufficient permissions'));
    }

    $process_id = sanitize_text_field($_POST['process_id']);

    if (empty($process_id)) {
        wp_send_json_error(array('error' => 'Process ID is required'));
    }

    // Get the progress tracker
    $tracker = new Clover_Progress_Tracker($process_id);
    $progress_data = $tracker->get_progress();

    if (!$progress_data) {
        wp_send_json_error(array('error' => 'Invalid process ID'));
    }

    // Mark the import as cancelled
    $tracker->cancel('Import cancelled by user');

    // Clean up transients
    $items_transient_key = 'clover_import_items_' . $process_id;
    delete_transient($items_transient_key);

    $expanded_data_transient_key = 'clover_import_expanded_data_' . $process_id;
    delete_transient($expanded_data_transient_key);

    wp_send_json_success(array(
        'message' => 'Import cancelled successfully',
        'progress' => $progress_data
    ));
}

/**
 * AJAX handler for quick view product
 */
function clover_quick_view_product_handler()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    $product_id = intval($_POST['product_id']);
    $product = wc_get_product($product_id);

    if (!$product) {
        wp_send_json_error(array('error' => 'Product not found'));
    }

    // Get product image
    $image_id = get_post_thumbnail_id($product_id);
    $image_url = $image_id ? wp_get_attachment_url($image_id) : wc_placeholder_img_src();

    // Get product price
    $price = $product->get_price();
    $price_html = $product->get_price_html();

    // Start output buffering
    ob_start();
    ?>
    <div class="clover-quick-view-content" data-product-id="<?php echo esc_attr($product_id); ?>">
        <span class="clover-quick-view-close">&times;</span>

        <div class="clover-quick-view-body">
            <div class="clover-quick-view-product">
                <!-- Left Column: Image + Description -->
                <div class="clover-quick-view-left-column">
                    <div class="clover-quick-view-image">
                        <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($product->get_name()); ?>">
                    </div>
                    
                    <!-- Product Description -->
                    <div class="clover-quick-view-description">
                        <?php
                        $description = $product->get_description();
                        if (!empty($description)) {
                            echo wp_kses_post($description);
                        }
                        ?>
                    </div>
                </div>

                <!-- Right Column: Title + Modifiers -->
                <div class="clover-quick-view-right-column">
                    <h2 class="clover-quick-view-title"><?php echo esc_html($product->get_name()); ?></h2>
                    <!-- Price removed from here - now shown in Add to Cart button -->

                    <!-- Load modifiers from existing system -->
                    <div class="clover-quick-view-modifiers-wrapper" id="quick-view-modifiers-<?php echo esc_attr($product_id); ?>">
                        <?php
                        // Check availability before modifiers so the inline script can read it
                        $qv_avail = clover_get_product_availability($product_id);
                        if (!$qv_avail['available']) {
                            // Hidden inputs read by updateAddToCartButton() JS
                            echo '<input type="hidden" id="category-hours-closed" value="1">';
                            echo '<input type="hidden" id="category-hours-message" value="' . esc_attr($qv_avail['message']) . '">';
                        }

                        // Temporarily override the display to capture modifiers
                        global $product;
                        $original_product = $product;
                        $product = wc_get_product($product_id);

                        // Call the existing modifier display function
                        if (class_exists('Custom_Modifier_System')) {
                            $modifier_system = Custom_Modifier_System::get_instance();
                            // Capture the modifiers HTML
                            ob_start();
                            $modifier_system->display_modifiers();
                            $modifiers_html = ob_get_clean();
                            echo $modifiers_html;
                        }

                        $product = $original_product;
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="clover-quick-view-footer">
            <div class="clover-quick-view-quantity">
                <label>Quantity:</label>
                <div class="clover-quick-view-qty-wrapper">
                    <button type="button" class="quick-view-qty-btn" data-action="minus">-</button>
                    <input type="number" class="quick-view-qty-input" value="1" min="1">
                    <button type="button" class="quick-view-qty-btn" data-action="plus">+</button>
                </div>
            </div>

            <?php // $qv_avail already computed above in modifiers wrapper ?>
            <button type="button"
                class="clover-quick-view-add-to-cart<?php echo !$qv_avail['available'] ? ' disabled' : ''; ?>"
                data-product-id="<?php echo esc_attr($product_id); ?>"
                <?php echo !$qv_avail['available'] ? 'disabled' : ''; ?>>
                Add to Cart - <?php echo $price_html; ?>
            </button>
            <?php if (!$qv_avail['available']): ?>
            <p class="category-hours-notice" style="color:#dc3545;font-size:0.85em;font-weight:500;margin:6px 0 0;text-align:center;">
                <?php echo esc_html($qv_avail['message']); ?>
            </p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    $html = ob_get_clean();

    wp_send_json_success(array('html' => $html));
}

/**
 * AJAX handler for adding product to cart from quick view
 */
function clover_quick_view_add_to_cart_handler()
{
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clover_make_request')) {
        wp_send_json_error(array('error' => 'Security check failed'));
    }

    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']);

    // Get modifiers from POST
    $modifiers = isset($_POST['modifiers']) ? $_POST['modifiers'] : array();

    clover_log('Quick View Add to Cart - Product ID: ' . $product_id);
    clover_log('Quick View Add to Cart - Quantity: ' . $quantity);
    clover_log('Quick View Add to Cart - Modifiers: ' . print_r($modifiers, true));

    // Prepare custom cart item data
    $cart_item_data = array();

    if (!empty($modifiers)) {
        // Store modifiers in cart item data
        $cart_item_data['custom_modifiers'] = $modifiers;

        // Calculate modifiers total price
        $modifiers_json = get_post_meta($product_id, '_clover_modifiers', true);
        clover_log('Quick View - Modifiers JSON from meta: ' . $modifiers_json);

        $all_modifiers = !empty($modifiers_json) ? json_decode($modifiers_json, true) : array();
        clover_log('Quick View - All modifiers decoded: ' . print_r($all_modifiers, true));

        $modifiers_total = 0;
        foreach ($modifiers as $serving => $serving_mods) {
            clover_log('Quick View - Processing serving ' . $serving . ': ' . print_r($serving_mods, true));
            if (is_array($serving_mods)) {
                foreach ($serving_mods as $mod_id) {
                    foreach ($all_modifiers as $modifier) {
                        if ($modifier['id'] == $mod_id && isset($modifier['price'])) {
                            $modifiers_total += floatval($modifier['price']);
                            clover_log('Quick View - Added modifier ' . $mod_id . ' price: ' . $modifier['price']);
                            break;
                        }
                    }
                }
            }
        }

        clover_log('Quick View - Total modifiers price: ' . $modifiers_total);

        // Store modifier price for later use
        $cart_item_data['modifiers_price'] = $modifiers_total;

        // IMPORTANT: Remove $_POST['custom_modifiers'] to prevent Custom_Modifier_System from processing again
        unset($_POST['custom_modifiers']);
        clover_log('Quick View - Removed $_POST[custom_modifiers] to prevent duplicate processing');
    } else {
        clover_log('Quick View - No modifiers selected');
    }

    // Add to cart with custom data
    $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, 0, array(), $cart_item_data);

    clover_log('Quick View - Cart item key: ' . ($cart_item_key ? $cart_item_key : 'FAILED'));

    if ($cart_item_key) {
        wp_send_json_success(array('message' => 'Product added to cart'));
    } else {
        wp_send_json_error(array('error' => 'Failed to add product to cart'));
    }
}

// Add modifier price to cart item price - handled by Custom_Modifier_System::update_cart_item_price()
// No need to add duplicate price calculation

// Restore cart item data from session
add_filter('woocommerce_get_cart_item_from_session', 'clover_quick_view_restore_cart_item_data', 10, 2);

function clover_quick_view_restore_cart_item_data($cart_item, $values)
{
    if (isset($values['custom_modifiers'])) {
        $cart_item['custom_modifiers'] = $values['custom_modifiers'];
    }
    return $cart_item;
}

// Display modifiers in cart - handled by Custom_Modifier_System::display_modifiers_in_cart()
// No need to add duplicate display

// Add quick view button to product loops - position based on settings
add_action('woocommerce_before_shop_loop_item', 'clover_add_quick_view_button_at_position', 9);
add_action('woocommerce_before_shop_loop_item_title', 'clover_add_quick_view_button_at_position', 8);
add_action('woocommerce_shop_loop_item_title', 'clover_add_quick_view_button_at_position', 9);
add_action('woocommerce_after_shop_loop_item_title', 'clover_add_quick_view_button_at_position', 11);
add_action('woocommerce_after_shop_loop_item', 'clover_add_quick_view_button_at_position', 8);

function clover_add_quick_view_button_at_position()
{
    $current_position = get_option('clover_quick_view_button_position', 'after_shop_loop_item');
    $current_hook = current_filter();
    
    // Map hooks to position names
    $hook_to_position = array(
        'woocommerce_before_shop_loop_item' => 'before_shop_loop_item',
        'woocommerce_before_shop_loop_item_title' => 'before_shop_loop_item_title',
        'woocommerce_shop_loop_item_title' => 'shop_loop_item_title',
        'woocommerce_after_shop_loop_item_title' => 'after_shop_loop_item_title',
        'woocommerce_after_shop_loop_item' => 'after_shop_loop_item',
    );
    
    // Only show button at the selected position
    if (!isset($hook_to_position[$current_hook]) || $hook_to_position[$current_hook] !== $current_position) {
        return;
    }
    
    clover_add_quick_view_button();
}

function clover_add_quick_view_button()
{
    // Check if quick view is enabled
    $show_button = get_option('clover_quick_view_show_button', '1');
    if ($show_button !== '1') {
        return;
    }
    
    // Check page-specific settings
    $show_on_shop = get_option('clover_quick_view_show_on_shop', '1');
    $show_on_category = get_option('clover_quick_view_show_on_category', '1');
    $show_on_tag = get_option('clover_quick_view_show_on_tag', '0');
    $show_on_pages = get_option('clover_quick_view_show_on_pages', '1');
    $show_on_posts = get_option('clover_quick_view_show_on_posts', '0');
    
    $should_show = false;
    
    // Check current page type
    if (is_shop() && $show_on_shop === '1') {
        $should_show = true;
    } elseif (is_product_category() && $show_on_category === '1') {
        $should_show = true;
    } elseif (is_product_tag() && $show_on_tag === '1') {
        $should_show = true;
    } elseif (is_page() && $show_on_pages === '1') {
        $should_show = true;
    } elseif (is_single() && get_post_type() === 'post' && $show_on_posts === '1') {
        $should_show = true;
    }
    
    if (!$should_show) {
        return;
    }
    
    global $product;
    $button_text = get_option('clover_quick_view_button_text', 'Buy now');
    echo '<button class="clover-quick-view-btn" data-product-id="' . esc_attr($product->get_id()) . '">' . esc_html($button_text) . '</button>';
}

// Add modal container to footer
add_action('wp_footer', 'clover_add_quick_view_modal_container');

function clover_add_quick_view_modal_container()
{
    ?>
    <div id="clover-quick-view-modal"></div>
    <?php
}
