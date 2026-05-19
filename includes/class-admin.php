<?php

/**
 * Clover Admin Class
 * Handles the admin interface for the plugin
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Assign a product to the Uncategorized category
 *
 * @param int $product_id The product ID to assign
 * @return void
 */
function assign_product_to_uncategorized($product_id)
{
    // Get the default WooCommerce category (Uncategorized)
    $uncategorized = get_term_by('slug', 'uncategorized', 'product_cat');

    // If not found by slug, try to get from WooCommerce settings
    if (!$uncategorized) {
        $default_cat_id = get_option('default_product_cat');
        if ($default_cat_id) {
            $uncategorized = get_term_by('id', $default_cat_id, 'product_cat');
        }
    }

    // If still not found, create it
    if (!$uncategorized) {
        $result = wp_insert_term('Uncategorized', 'product_cat', array(
            'slug' => 'uncategorized'
        ));
        if (!is_wp_error($result)) {
            $uncategorized = get_term_by('id', $result['term_id'], 'product_cat');
        }
    }

    // Assign the category to the product
    if ($uncategorized) {
        wp_set_object_terms(
            $product_id,
            [(int) $uncategorized->term_id],
            'product_cat',
            false
        );
        clover_log('Assigned product ' . $product_id . ' to Uncategorized category (ID: ' . $uncategorized->term_id . ')');
    } else {
        clover_log('Could not find or create Uncategorized category for product ' . $product_id);
    }
}

class Clover_Admin
{
    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_settings'));
        add_action('admin_init', array($this, 'handle_refresh_hours'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('edit_user_profile', array($this, 'add_clover_sync_button'));
        add_action('show_user_profile', array($this, 'add_clover_sync_button'));
    }

    /**
     * Handle manual refresh of business hours
     */
    public function handle_refresh_hours()
    {
        if (isset($_GET['refresh_clover_hours']) && current_user_can('manage_options')) {
            delete_transient('clover_business_hours_data');
            wp_safe_redirect(remove_query_arg('refresh_clover_hours'));
            exit;
        }
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts($hook)
    {
        // Only load on the Clover API settings page
        if ($hook !== 'toplevel_page_clover-api-config') {
            return;
        }

        // jQuery is loaded by WordPress, but we need to make sure it's available
        wp_enqueue_script('jquery');
    }

    /**
     * Add admin menu page
     */
    public function add_admin_menu()
    {
        add_menu_page(
            'EMP Clover Integration',  // Titulo de la pagina
            'EMP Clover Integration',  // Titulo del Menu
            'manage_options',  // Permiso
            'clover-api-config',  // slog
            array($this, 'options_page'),
            'dashicons-products',  // Using a products icon
            30  // Position in the menu
        );

        /* add_submenu_page(
             'clover-api-config', // Slug del menú padre
              'Importar Items', // Título de la página
              'Importar Items', // Texto del submenú
              'manage_options', // Permiso
              'clover-import-items', // Slug del submenú
              array($this, 'import_page') // Callback
         );*/

        add_submenu_page(
            'clover-api-config',  // Slug del menú padre
            'Import data',  // Título de la página
            'Import data',  // Texto del submenú
            'manage_options',  // Permiso
            'clover-get-info',  // Slug del submenú
            array($this, 'get_info_page')  // Callback
        );

        add_submenu_page(
            'clover-api-config',  // Slug del menú padre
            'Import Customers',  // Título de la página
            'Customers',  // Texto del submenú
            'manage_options',  // Permiso'error' => $e->getMessage()));
            'clover-import-customers',  // Slug del submenú
            array($this, 'import_customers_page')  // Callback
        );
    }

    /**
     * Initialize settings
     */
    public function init_settings()
    {
        register_setting('clover_settings', 'clover_merchid', array($this, 'sanitize_merchid'));
        register_setting('clover_settings', 'clover_token', array($this, 'sanitize_token'));
        register_setting('clover_settings', 'clover_api_base_url', array($this, 'sanitize_api_base_url'));
        register_setting('clover_settings', 'clover_auto_print_orders', array($this, 'sanitize_auto_print'));
        register_setting('clover_settings', 'clover_auto_mark_as_paid', array($this, 'sanitize_auto_print'));
        register_setting('clover_settings', 'clover_payment_tender_id', array($this, 'sanitize_text'));
        register_setting('clover_settings', 'clover_import_fee_enabled', array($this, 'sanitize_checkbox'));
        register_setting('clover_settings', 'clover_import_fee_percent', array($this, 'sanitize_text'));
        register_setting('clover_settings', 'clover_global_discount_enabled', array($this, 'sanitize_checkbox'));
        register_setting('clover_settings', 'clover_global_discount_percent', array($this, 'sanitize_text'));
        register_setting('clover_settings', 'clover_global_discount_apply_modifiers', array($this, 'sanitize_checkbox'));
        register_setting('clover_settings', 'clover_prevent_orders_when_closed', array($this, 'sanitize_checkbox'));
        register_setting('clover_settings', 'clover_employee_id', array($this, 'sanitize_text'));

        register_setting('clover_settings', 'clover_default_order_type_id', array($this, 'sanitize_text'));
        register_setting('clover_settings', 'clover_order_type_map', array($this, 'sanitize_order_type_map'));

        // Quick View settings
        register_setting('clover_settings', 'clover_quick_view_show_button', array($this, 'sanitize_checkbox'));
        register_setting('clover_settings', 'clover_quick_view_show_on_shop', array($this, 'sanitize_checkbox'));
        register_setting('clover_settings', 'clover_quick_view_show_on_category', array($this, 'sanitize_checkbox'));
        register_setting('clover_settings', 'clover_quick_view_show_on_tag', array($this, 'sanitize_checkbox'));
        register_setting('clover_settings', 'clover_quick_view_show_on_pages', array($this, 'sanitize_checkbox'));
        register_setting('clover_settings', 'clover_quick_view_show_on_posts', array($this, 'sanitize_checkbox'));
        register_setting('clover_settings', 'clover_quick_view_button_text', array($this, 'sanitize_text'));
        register_setting('clover_settings', 'clover_quick_view_button_position', array($this, 'sanitize_text'));

        // Business Hours Banner settings (from Business Hours module)
        register_setting('clover_settings', 'clover_bh_show_banner', array($this, 'sanitize_checkbox'));
        register_setting('clover_settings', 'clover_bh_banner_position', array($this, 'sanitize_text'));
        register_setting('clover_settings', 'clover_bh_show_countdown', array($this, 'sanitize_checkbox'));

        // Logs settings
        register_setting('clover_settings', 'clover_enable_logs', array($this, 'sanitize_checkbox'));
        register_setting('clover_settings', 'clover_log_to_wp_debug', array($this, 'sanitize_checkbox'));

        // Discounts settings
        register_setting('clover_settings', 'clover_discount_apply_to_orders', array($this, 'sanitize_checkbox'));
        register_setting('clover_settings', 'clover_discount_id', array($this, 'sanitize_text'));
        register_setting('clover_settings', 'clover_discount_cached_percent', array($this, 'sanitize_text'));

        // Taxes and Fees settings
        register_setting('clover_settings', 'clover_enabled_tax_rates', array($this, 'sanitize_tax_rates'));
        register_setting('clover_settings', 'clover_tax_rates_cache', array($this, 'sanitize_text'));

        // ========== TAB 1: API Configuration ==========
        add_settings_section(
            'clover_main_section',
            'API Configuration',
            array($this, 'section_callback'),
            'clover-settings'
        );

        add_settings_field(
            'clover_merchid',
            'Merchant ID',
            array($this, 'merchid_callback'),
            'clover-settings',
            'clover_main_section'
        );

        add_settings_field(
            'clover_token',
            'API Token',
            array($this, 'token_callback'),
            'clover-settings',
            'clover_main_section'
        );

        add_settings_field(
            'clover_api_base_url',
            'API Base URL',
            array($this, 'api_base_url_callback'),
            'clover-settings',
            'clover_main_section'
        );

        // ========== TAB 2: Orders & Payments ==========
        add_settings_section(
            'clover_orders_section',
            'Orders & Payments',
            array($this, 'orders_section_callback'),
            'clover-settings-orders'
        );

        add_settings_field(
            'clover_auto_print_orders',
            'Auto-Print Orders',
            array($this, 'auto_print_callback'),
            'clover-settings-orders',
            'clover_orders_section'
        );

        add_settings_field(
            'clover_auto_mark_as_paid',
            'Auto-Mark Orders as Paid',
            array($this, 'auto_mark_as_paid_callback'),
            'clover-settings-orders',
            'clover_orders_section'
        );

        add_settings_field(
            'clover_payment_tender_id',
            'Payment Tender ID',
            array($this, 'payment_tender_id_callback'),
            'clover-settings-orders',
            'clover_orders_section'
        );

        add_settings_field(
            'clover_employee_id',
            'Default Employee ID',
            array($this, 'employee_id_callback'),
            'clover-settings-orders',
            'clover_orders_section'
        );

        // ========== Order Type Mapping ==========
        add_settings_section(
            'clover_order_types_section',
            'Order Type Mapping',
            array($this, 'order_types_section_callback'),
            'clover-settings-orders'
        );

        add_settings_field(
            'clover_default_order_type_id',
            'Default Order Type',
            array($this, 'default_order_type_callback'),
            'clover-settings-orders',
            'clover_order_types_section'
        );

        add_settings_field(
            'clover_order_type_map',
            'Shipping Method Mapping',
            array($this, 'order_type_map_callback'),
            'clover-settings-orders',
            'clover_order_types_section'
        );

        // ========== TAB 3: Pricing & Discounts ==========
        add_settings_section(
            'clover_pricing_section',
            'Pricing & Discounts',
            array($this, 'pricing_section_callback'),
            'clover-settings-pricing'
        );

        add_settings_field(
            'clover_import_fee_enabled',
            'Import Price Fee',
            array($this, 'import_fee_enabled_callback'),
            'clover-settings-pricing',
            'clover_pricing_section'
        );

        add_settings_field(
            'clover_import_fee_percent',
            'Fee Percentage',
            array($this, 'import_fee_percent_callback'),
            'clover-settings-pricing',
            'clover_pricing_section'
        );

        add_settings_field(
            'clover_global_discount_enabled',
            'Global Discount',
            array($this, 'global_discount_enabled_callback'),
            'clover-settings-pricing',
            'clover_pricing_section'
        );

        add_settings_field(
            'clover_global_discount_percent',
            'Discount Percentage',
            array($this, 'global_discount_percent_callback'),
            'clover-settings-pricing',
            'clover_pricing_section'
        );

        add_settings_field(
            'clover_global_discount_apply_modifiers',
            'Apply to Modifiers',
            array($this, 'global_discount_apply_modifiers_callback'),
            'clover-settings-pricing',
            'clover_pricing_section'
        );

        add_settings_field(
            'clover_prevent_orders_when_closed',
            'Prevent Orders When Closed',
            array($this, 'prevent_orders_when_closed_callback'),
            'clover-settings-hours',
            'clover_hours_section'
        );

        // ========== TAB 4: Store Hours ==========
        add_settings_section(
            'clover_hours_section',
            'Store Opening Hours',
            array($this, 'hours_section_callback'),
            'clover-settings-hours'
        );

        add_settings_field(
            'clover_store_hours_display',
            'Current Hours',
            array($this, 'store_hours_display_callback'),
            'clover-settings-hours',
            'clover_hours_section'
        );

        // ========== TAB 5: Quick View ==========
        add_settings_section(
            'clover_quick_view_section',
            'Quick View Settings',
            array($this, 'quick_view_section_callback'),
            'clover-settings-quickview'
        );

        add_settings_field(
            'clover_quick_view_show_button',
            'Show Quick View Button',
            array($this, 'quick_view_show_button_callback'),
            'clover-settings-quickview',
            'clover_quick_view_section'
        );

        add_settings_field(
            'clover_quick_view_show_on_shop',
            'Show on Shop Page',
            array($this, 'quick_view_show_on_shop_callback'),
            'clover-settings-quickview',
            'clover_quick_view_section'
        );

        add_settings_field(
            'clover_quick_view_show_on_category',
            'Show on Category Pages',
            array($this, 'quick_view_show_on_category_callback'),
            'clover-settings-quickview',
            'clover_quick_view_section'
        );

        add_settings_field(
            'clover_quick_view_show_on_tag',
            'Show on Tag Pages',
            array($this, 'quick_view_show_on_tag_callback'),
            'clover-settings-quickview',
            'clover_quick_view_section'
        );

        add_settings_field(
            'clover_quick_view_show_on_pages',
            'Show on Pages',
            array($this, 'quick_view_show_on_pages_callback'),
            'clover-settings-quickview',
            'clover_quick_view_section'
        );

        add_settings_field(
            'clover_quick_view_show_on_posts',
            'Show on Posts',
            array($this, 'quick_view_show_on_posts_callback'),
            'clover-settings-quickview',
            'clover_quick_view_section'
        );

        add_settings_field(
            'clover_quick_view_button_text',
            'Button Text',
            array($this, 'quick_view_button_text_callback'),
            'clover-settings-quickview',
            'clover_quick_view_section'
        );

        add_settings_field(
            'clover_quick_view_button_position',
            'Button Position',
            array($this, 'quick_view_button_position_callback'),
            'clover-settings-quickview',
            'clover_quick_view_section'
        );

        // ========== TAB 6: Business Hours Banner ==========
        add_settings_section(
            'clover_bh_section',
            'Business Hours Banner',
            array($this, 'business_hours_banner_section_callback'),
            'clover-settings-banner'
        );

        add_settings_field(
            'clover_bh_show_banner',
            'Show Status Banner',
            array($this, 'bh_show_banner_callback'),
            'clover-settings-hours',
            'clover_hours_section'
        );

        add_settings_field(
            'clover_bh_banner_position',
            'Banner Position',
            array($this, 'bh_banner_position_callback'),
            'clover-settings-hours',
            'clover_hours_section'
        );

        add_settings_field(
            'clover_bh_show_countdown',
            'Show Countdown',
            array($this, 'bh_show_countdown_callback'),
            'clover-settings-hours',
            'clover_hours_section'
        );

        add_settings_field(
            'clover_bh_test_connection',
            'Test Business Hours',
            array($this, 'bh_test_connection_callback'),
            'clover-settings-hours',
            'clover_hours_section'
        );

        // ========== TAB 7: Logs ==========
        add_settings_section(
            'clover_logs_section',
            'Plugin Logs',
            array($this, 'logs_section_callback'),
            'clover-settings-logs'
        );

        add_settings_field(
            'clover_enable_logs',
            'Enable Logging',
            array($this, 'enable_logs_callback'),
            'clover-settings-logs',
            'clover_logs_section'
        );

        // ========== TAB 8: Taxes and Fees ==========
        add_settings_section(
            'clover_taxes_section',
            'Tax Rates',
            array($this, 'taxes_section_callback'),
            'clover-settings-taxes'
        );

        add_settings_field(
            'clover_enabled_tax_rates',
            'Clover Tax Rates',
            array($this, 'tax_rates_callback'),
            'clover-settings-taxes',
            'clover_taxes_section'
        );

        // ========== TAB 9: Discounts ==========
        add_settings_section(
            'clover_discounts_section',
            'Clover Discounts',
            array($this, 'discounts_section_callback'),
            'clover-settings-discounts'
        );

        add_settings_field(
            'clover_discount_apply_to_orders',
            'Apply Discount to Orders',
            array($this, 'discount_apply_callback'),
            'clover-settings-discounts',
            'clover_discounts_section'
        );

        add_settings_field(
            'clover_discount_id',
            'Clover Discount',
            array($this, 'discount_id_callback'),
            'clover-settings-discounts',
            'clover_discounts_section'
        );
    }

    /**
     * Sanitize merchant ID
     */
    public function sanitize_merchid($input)
    {
        if (empty($input)) {
            add_settings_error('clover_merchid', 'clover_merchid_empty', 'Merchant ID is required.');
            return get_option('clover_merchid');  // Return previous value
        }

        return sanitize_text_field(trim($input));
    }

    /**
     * Sanitize API token
     */
    public function sanitize_token($input)
    {
        if (empty($input)) {
            add_settings_error('clover_token', 'clover_token_empty', 'API Token is required.');
            return get_option('clover_token');  // Return previous value
        }

        return sanitize_text_field(trim($input));
    }

    /**
     * Sanitize API base URL
     */
    public function sanitize_api_base_url($input)
    {
        if (empty($input)) {
            return 'https://api.clover.com/v3/merchants/';  // Default value
        }

        $sanitized = esc_url_raw(trim($input));

        // Validate URL format
        if (!filter_var($sanitized, FILTER_VALIDATE_URL)) {
            add_settings_error('clover_api_base_url', 'clover_api_base_url_invalid', 'Invalid API Base URL format.');
            return get_option('clover_api_base_url', 'https://api.clover.com/v3/merchants/');  // Return previous/default value
        }

        return $sanitized;
    }

    /**
     * Sanitize auto-print setting
     */
    public function sanitize_auto_print($input)
    {
        return !empty($input) ? '1' : '0';
    }

    /**
     * Section callback
     */
    public function section_callback()
    {
        echo '<p>Enter your Clover API credentials below. These are required for all plugin functionality.</p>';
    }

    /**
     * Orders section callback
     */
    public function orders_section_callback()
    {
        echo '<p>Configure how orders are handled when created from WooCommerce.</p>';
    }

    /**
     * Pricing section callback
     */
    public function pricing_section_callback()
    {
        echo '<p>Configure pricing modifiers, import fees, and global discount settings.</p>';
    }

    /**
     * Store hours section callback
     */
    public function store_hours_section_callback()
    {
        echo '<p>View and manage your store business hours synced from Clover.</p>';
    }

    /**
     * Quick View section callback
     */
    public function quick_view_section_callback()
    {
        echo '<p>Configure where the Quick View "Buy Now" button appears on your site.</p>';
    }

    /**
     * Business Hours Banner section callback
     */
    public function business_hours_banner_section_callback()
    {
        echo '<p>Configure the open/closed status banner display on your website.</p>';
    }

    /**
     * Merchant ID field callback
     */
    public function merchid_callback()
    {
        $value = get_option('clover_merchid');
        echo '<input type="text" id="clover_merchid" name="clover_merchid" value="' . esc_attr($value) . '" size="50" />';
        echo '<p class="description">Enter your Clover merchant ID.</p>';
    }

    /**
     * Token field callback
     */
    public function token_callback()
    {
        $value = get_option('clover_token');
        echo '<input type="password" id="clover_token" name="clover_token" value="' . esc_attr($value) . '" size="50" />';
        echo '<p class="description">Enter your Clover API token.</p>';
    }

    /**
     * API Base URL field callback
     */
    public function api_base_url_callback()
    {
        $value = get_option('clover_api_base_url');
        if (empty($value)) {
            $value = 'https://api.clover.com/v3/merchants/';
        }
        echo '<input type="text" id="clover_api_base_url" name="clover_api_base_url" value="' . esc_attr($value) . '" size="50" />';
        echo '<p class="description">Enter the base URL for the Clover API. Default: https://api.clover.com/v3/merchants/</p>';
    }

    /**
     * Auto-print orders field callback
     */
    public function auto_print_callback()
    {
        $value = get_option('clover_auto_print_orders', '1');
        echo '<label><input type="checkbox" id="clover_auto_print_orders" name="clover_auto_print_orders" value="1" ' . checked($value, '1', false) . ' /> ';
        echo 'Automatically print orders to Clover printer</label>';
        echo '<p class="description">When enabled, orders will be automatically sent to the Clover printer after being created.</p>';
    }

    /**
     * Auto-mark orders as paid field callback
     */
    public function auto_mark_as_paid_callback()
    {
        $value = get_option('clover_auto_mark_as_paid', '1');
        echo '<label><input type="checkbox" id="clover_auto_mark_as_paid" name="clover_auto_mark_as_paid" value="1" ' . checked($value, '1', false) . ' /> ';
        echo 'Automatically mark orders as paid via API</label>';
        echo '<p class="description">When enabled, orders will be automatically marked as paid using the selected tender. If disabled, orders will arrive in Clover as unpaid.</p>';
    }

    /**
     * Payment tender ID field callback
     */
    public function payment_tender_id_callback()
    {
        $value = get_option('clover_payment_tender_id', '');

        // Try to fetch tenders from Clover API
        $tenders = array();
        $config = array(
            'base_url' => get_option('clover_api_base_url', 'https://api.clover.com/v3/merchants/'),
            'merchID' => get_option('clover_merchid'),
            'tokenBearer' => get_option('clover_token')
        );

        if (!empty($config['merchID']) && !empty($config['tokenBearer'])) {
            try {
                $orderService = new \Src\Services\OrderService($config);
                $tendersResponse = $orderService->getTenders();

                if (isset($tendersResponse['data']['elements']) && is_array($tendersResponse['data']['elements'])) {
                    $tenders = $tendersResponse['data']['elements'];
                }
            } catch (Exception $e) {
                clover_log('Error fetching tenders: ' . $e->getMessage());
            }
        }

        echo '<div style="display: flex; align-items: center; gap: 10px;">';
        echo '<select id="clover_payment_tender_id" name="clover_payment_tender_id" style="min-width: 300px;">';
        echo '<option value="">-- Select Tender --</option>';

        foreach ($tenders as $tender) {
            $selected = selected($value, $tender['id'], false);
            $label = isset($tender['label']) ? $tender['label'] : (isset($tender['name']) ? $tender['name'] : $tender['id']);
            echo '<option value="' . esc_attr($tender['id']) . '"' . $selected . '>' . esc_html($label) . '</option>';
        }

        echo '</select>';

        // Add reload button
        echo '<button type="button" id="reload-tenders-btn" class="button" style="margin: 0;" onclick="reloadTenders()">';
        echo '<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Reload Tenders';
        echo '</button>';
        echo '</div>';

        echo '<p class="description">Select the tender to use for marking orders as paid (e.g., Check, Cash, Credit Card). This will be used to create payment records via API.</p>';

        if (empty($tenders)) {
            echo '<p class="description" style="color: #dc3232;">Note: Could not fetch tenders. Please verify API credentials. You can manually enter a tender ID if known.</p>';
            echo '<input type="text" id="clover_payment_tender_id_manual" value="' . esc_attr($value) . '" class="regular-text" placeholder="Enter tender ID manually (e.g., AAAABBBBCCCCDDD)" style="margin-top: 10px;" />';
            echo '<p class="description">If you know your tender ID, enter it above and it will be saved.</p>';
        }

        // Add JavaScript for reload functionality
        ?>
        <script type="text/javascript">
        function reloadTenders() {
            var btn = document.getElementById('reload-tenders-btn');
            var select = document.getElementById('clover_payment_tender_id');
            var manualInput = document.getElementById('clover_payment_tender_id_manual');
            var manualContainer = manualInput ? manualInput.parentElement : null;

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner is-active" style="float:none; display:inline-block; margin:0 5px 0 0;"></span> Loading...';

            var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
            var nonce = '<?php echo wp_create_nonce('clover_make_request'); ?>';

            jQuery.post(ajaxUrl, {
                action: 'clover_reload_tenders',
                nonce: nonce
            }, function(response) {
                if (response.success && response.data.tenders && response.data.tenders.length > 0) {
                    // Clear existing options except first
                    select.innerHTML = '<option value="">-- Select Tender --</option>';

                    // Add new options
                    response.data.tenders.forEach(function(tender) {
                        var option = document.createElement('option');
                        option.value = tender.id;
                        option.textContent = tender.label || tender.name || tender.id;
                        option.selected = (tender.id === '<?php echo esc_js($value); ?>');
                        select.appendChild(option);
                    });

                    // Hide manual input and error message if visible
                    if (manualInput) {
                        manualInput.style.display = 'none';
                    }
                    if (manualContainer && manualContainer.previousElementSibling) {
                        manualContainer.previousElementSibling.style.display = 'none';
                    }

                    btn.innerHTML = '<span class="dashicons dashicons-yes" style="margin-top: 3px;"></span> Reloaded!';
                    setTimeout(function() {
                        btn.disabled = false;
                        btn.innerHTML = '<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Reload Tenders';
                    }, 2000);
                } else {
                    btn.innerHTML = '<span class="dashicons dashicons-no" style="margin-top: 3px;"></span> Failed';
                    setTimeout(function() {
                        btn.disabled = false;
                        btn.innerHTML = '<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Reload Tenders';
                    }, 2000);
                    alert('Error: ' + (response.data.error || 'Failed to load tenders'));
                }
            }).fail(function() {
                btn.innerHTML = '<span class="dashicons dashicons-no" style="margin-top: 3px;"></span> Failed';
                setTimeout(function() {
                    btn.disabled = false;
                    btn.innerHTML = '<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Reload Tenders';
                }, 2000);
                alert('Error: Failed to connect to server');
            });
        }
        </script>
        <?php
    }

    /**
     * Import fee enabled callback
     */
    public function import_fee_enabled_callback()
    {
        $value = get_option('clover_import_fee_enabled', '0');
        echo '<label><input type="checkbox" id="clover_import_fee_enabled" name="clover_import_fee_enabled" value="1" ' . checked($value, '1', false) . ' /> ';
        echo 'Enable price markup during import</label>';
        echo '<p class="description">When enabled, a percentage fee will be added to product and modifier prices during import from Clover.</p>';
    }

    /**
     * Import fee percentage callback
     */
    public function import_fee_percent_callback()
    {
        $value = get_option('clover_import_fee_percent');
        if ($value === false || $value === '') {
            $value = '20';
        }
        echo '<input type="number" id="clover_import_fee_percent" name="clover_import_fee_percent" value="' . esc_attr($value) . '" class="small-text" min="0" max="100" step="0.1" /> %';
        echo '<p class="description">Enter the percentage to add to all prices during import (e.g., 20 for 20%). Default: 20%.</p>';
    }

    /**
     * Global discount enabled callback
     */
    public function global_discount_enabled_callback()
    {
        $value = get_option('clover_global_discount_enabled', '0');
        echo '<label><input type="checkbox" id="clover_global_discount_enabled" name="clover_global_discount_enabled" value="1" ' . checked($value, '1', false) . ' /> ';
        echo 'Enable Global Discount</label>';
        echo '<p class="description">When enabled, a percentage discount will be applied to all product prices. Shows original price crossed out.</p>';
    }

    /**
     * Global discount percentage callback
     */
    public function global_discount_percent_callback()
    {
        $value = get_option('clover_global_discount_percent');
        if ($value === false || $value === '') {
            $value = '10';
        }
        echo '<input type="number" id="clover_global_discount_percent" name="clover_global_discount_percent" value="' . esc_attr($value) . '" class="small-text" min="0" max="100" step="0.1" /> %';
        echo '<p class="description">Enter the discount percentage (e.g., 25 for 25% off). Default: 10%.</p>';
    }

    /**
     * Global discount apply to modifiers callback
     */
    public function global_discount_apply_modifiers_callback()
    {
        $value = get_option('clover_global_discount_apply_modifiers', '0');
        echo '<label><input type="checkbox" id="clover_global_discount_apply_modifiers" name="clover_global_discount_apply_modifiers" value="1" ' . checked($value, '1', false) . ' /> ';
        echo 'Apply discount to modifiers</label>';
        echo '<p class="description">When enabled, the discount will also apply to modifier prices.</p>';
    }

    /**
     * Prevent orders when closed callback
     */
    public function prevent_orders_when_closed_callback()
    {
        $value = get_option('clover_prevent_orders_when_closed', '0');
        echo '<label><input type="checkbox" id="clover_prevent_orders_when_closed" name="clover_prevent_orders_when_closed" value="1" ' . checked($value, '1', false) . ' /> ';
        echo 'Prevent orders when store is closed</label>';
        echo '<p class="description">When enabled, customers cannot add items to cart or checkout when the store is closed.</p>';
    }

    /**
     * Default Employee selector callback — fetches employees from Clover API
     */
    public function employee_id_callback()
    {
        $value = get_option('clover_employee_id', '');

        // Fetch employees from Clover API
        $employees = array();
        $config = array(
            'base_url'    => get_option('clover_api_base_url', 'https://api.clover.com/v3/merchants/'),
            'merchID'     => get_option('clover_merchid'),
            'tokenBearer' => get_option('clover_token'),
        );

        if (!empty($config['merchID']) && !empty($config['tokenBearer'])) {
            try {
                $orderService      = new \Src\Services\OrderService($config);
                $employeesResponse = $orderService->getEmployees();

                if (isset($employeesResponse['data']['elements']) && is_array($employeesResponse['data']['elements'])) {
                    $employees = $employeesResponse['data']['elements'];
                }
            } catch (Exception $e) {
                clover_log('Error fetching employees: ' . $e->getMessage());
            }
        }

        echo '<div style="display: flex; align-items: center; gap: 10px;">';
        echo '<select id="clover_employee_id" name="clover_employee_id" style="min-width: 300px;">';
        echo '<option value="">-- Select Employee --</option>';

        foreach ($employees as $employee) {
            $selected  = selected($value, $employee['id'], false);
            $full_name = trim(
                ($employee['name'] ?? '') !== ''
                    ? $employee['name']
                    : (($employee['firstName'] ?? '') . ' ' . ($employee['lastName'] ?? ''))
            );
            if ($full_name === '') $full_name = $employee['id'];
            echo '<option value="' . esc_attr($employee['id']) . '"' . $selected . '>' . esc_html($full_name) . '</option>';
        }

        echo '</select>';

        echo '<button type="button" id="reload-employees-btn" class="button" style="margin: 0;" onclick="reloadEmployees()">';
        echo '<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Reload Employees';
        echo '</button>';
        echo '</div>';

        echo '<p class="description">Select the default Clover employee to associate with orders created via the API.</p>';

        if (empty($employees)) {
            echo '<p class="description" style="color: #dc3232;">Note: Could not fetch employees. Please verify API credentials.</p>';
            echo '<input type="text" id="clover_employee_id_manual" value="' . esc_attr($value) . '" class="regular-text" placeholder="Enter employee ID manually" style="margin-top: 10px;" />';
            echo '<p class="description">If you know the employee ID, enter it above and it will be saved.</p>';
        }

        ?>
        <script type="text/javascript">
        function reloadEmployees() {
            var btn       = document.getElementById('reload-employees-btn');
            var select    = document.getElementById('clover_employee_id');
            var manualInput = document.getElementById('clover_employee_id_manual');

            btn.disabled  = true;
            btn.innerHTML = '<span class="spinner is-active" style="float:none; display:inline-block; margin:0 5px 0 0;"></span> Loading...';

            var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
            var nonce   = '<?php echo wp_create_nonce('clover_make_request'); ?>';

            jQuery.post(ajaxUrl, {
                action: 'clover_reload_employees',
                nonce:  nonce
            }, function(response) {
                if (response.success && response.data.employees && response.data.employees.length > 0) {
                    var currentVal = select.value;
                    select.innerHTML = '<option value="">-- Select Employee --</option>';

                    response.data.employees.forEach(function(emp) {
                        var option     = document.createElement('option');
                        option.value   = emp.id;
                        option.textContent = emp.name || (emp.firstName + ' ' + emp.lastName).trim() || emp.id;
                        option.selected = (emp.id === currentVal);
                        select.appendChild(option);
                    });

                    if (manualInput) {
                        manualInput.parentElement && (manualInput.parentElement.style.display = 'none');
                    }

                    btn.innerHTML = '<span class="dashicons dashicons-yes" style="margin-top: 3px;"></span> Reloaded!';
                    setTimeout(function() {
                        btn.disabled  = false;
                        btn.innerHTML = '<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Reload Employees';
                    }, 2000);
                } else {
                    btn.innerHTML = '<span class="dashicons dashicons-no" style="margin-top: 3px;"></span> Failed';
                    setTimeout(function() {
                        btn.disabled  = false;
                        btn.innerHTML = '<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Reload Employees';
                    }, 2000);
                    alert('Error: ' + (response.data && response.data.error ? response.data.error : 'Failed to load employees'));
                }
            }).fail(function() {
                btn.innerHTML = '<span class="dashicons dashicons-no" style="margin-top: 3px;"></span> Failed';
                setTimeout(function() {
                    btn.disabled  = false;
                    btn.innerHTML = '<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Reload Employees';
                }, 2000);
                alert('Error: Failed to connect to server');
            });
        }

        // Sync manual input value into the hidden select so it gets saved
        (function() {
            var manualInput = document.getElementById('clover_employee_id_manual');
            var select      = document.getElementById('clover_employee_id');
            if (manualInput && select) {
                manualInput.addEventListener('input', function() {
                    var existing = select.querySelector('option[data-manual]');
                    if (!existing) {
                        existing = document.createElement('option');
                        existing.setAttribute('data-manual', '1');
                        select.appendChild(existing);
                    }
                    existing.value       = manualInput.value;
                    existing.textContent = manualInput.value;
                    existing.selected    = true;
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * Sanitize order type map (array of shipping_method_key => clover_order_type_id)
     */
    public function sanitize_order_type_map($value)
    {
        if (!is_array($value)) {
            return array();
        }
        $clean = array();
        foreach ($value as $k => $v) {
            $clean[sanitize_text_field($k)] = sanitize_text_field($v);
        }
        return $clean;
    }

    /**
     * Helper: fetch Clover order types. Returns array of ['id' => '', 'label' => ''].
     */
    private function get_clover_order_types()
    {
        $order_types = array();
        $config = array(
            'base_url' => get_option('clover_api_base_url', 'https://api.clover.com/v3/merchants/'),
            'merchID' => get_option('clover_merchid'),
            'tokenBearer' => get_option('clover_token'),
        );
        if (!empty($config['merchID']) && !empty($config['tokenBearer'])) {
            try {
                $service = new \Src\Services\OrderService($config);
                $response = $service->getOrderTypes();
                if (isset($response['data']['elements']) && is_array($response['data']['elements'])) {
                    foreach ($response['data']['elements'] as $ot) {
                        $order_types[] = array(
                            'id' => $ot['id'] ?? '',
                            'label' => $ot['label'] ?? ($ot['name'] ?? $ot['id']),
                        );
                    }
                }
            } catch (Exception $e) {
                clover_log('Error fetching order types: ' . $e->getMessage());
            }
        }
        return $order_types;
    }

    /**
     * Helper: fetch all enabled WooCommerce shipping methods across all zones.
     * Returns array of ['key' => 'method_id:instance_id', 'title' => '', 'zone' => '']
     *
     * Instantiates WC_Shipping_Zone directly per zone instead of relying on the
     * data array returned by WC_Shipping_Zones::get_zones(), whose structure and
     * object initialisation state varies between WooCommerce versions.
     */
    private function get_wc_shipping_methods()
    {
        if (!class_exists('WC_Shipping_Zone') || !class_exists('WC_Shipping_Zones')) {
            return array();
        }

        $methods = array();

        // Collect all zone IDs plus 0 (Rest of the World)
        $raw_zones = WC_Shipping_Zones::get_zones();
        $zone_ids = array_keys($raw_zones);
        $zone_ids[] = 0;

        foreach ($zone_ids as $zone_id) {
            $zone = new WC_Shipping_Zone($zone_id);
            $zone_name = $zone->get_zone_name() ?: 'Rest of the World';

            // get_shipping_methods(true) returns only enabled methods as fully
            // initialised WC_Shipping_Method objects
            foreach ($zone->get_shipping_methods(true) as $method) {
                $key = $method->id . ':' . $method->instance_id;
                $methods[] = array(
                    'key' => $key,
                    'title' => $method->get_title(),
                    'zone' => $zone_name,
                );
            }
        }

        return $methods;
    }

    /**
     * Order Type Mapping section callback
     */
    public function order_types_section_callback()
    {
        echo '<p>Map each WooCommerce shipping method to a Clover Order Type. When no mapping matches, the Default Order Type is used.</p>';
    }

    /**
     * Default Order Type callback
     */
    public function default_order_type_callback()
    {
        $value = get_option('clover_default_order_type_id', '');
        $order_types = $this->get_clover_order_types();

        echo '<div style="display:flex; align-items:center; gap:10px;">';
        echo '<select id="clover_default_order_type_id" name="clover_default_order_type_id" style="min-width:300px;">';
        echo '<option value="">-- No default --</option>';
        foreach ($order_types as $ot) {
            $selected = selected($value, $ot['id'], false);
            echo '<option value="' . esc_attr($ot['id']) . '"' . $selected . '>' . esc_html($ot['label']) . ' (' . esc_html($ot['id']) . ')</option>';
        }
        echo '</select>';
        echo '<button type="button" id="reload-order-types-btn" class="button" style="margin:0;" onclick="reloadOrderTypes()">';
        echo '<span class="dashicons dashicons-update" style="margin-top:3px;"></span> Reload Order Types';
        echo '</button>';
        echo '</div>';
        echo '<p class="description">Used when the order has no shipping method or no mapping is configured for it.</p>';

        if (empty($order_types)) {
            echo '<p class="description" style="color:#dc3232;">Could not fetch order types. Verify API credentials.</p>';
        }
        ?>
        <script type="text/javascript">
        var cloverOrderTypesCache = <?php echo json_encode($order_types); ?>;
        var cloverDefaultOrderTypeId = '<?php echo esc_js($value); ?>';

        function buildOrderTypeOptions(types, selectedId) {
            var html = '<option value="">-- None --</option>';
            types.forEach(function(ot) {
                var sel = (ot.id === selectedId) ? ' selected' : '';
                html += '<option value="' + ot.id + '"' + sel + '>' + ot.label + ' (' + ot.id + ')</option>';
            });
            return html;
        }

        function reloadOrderTypes() {
            var btn = document.getElementById('reload-order-types-btn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner is-active" style="float:none;display:inline-block;margin:0 5px 0 0;"></span> Loading...';

            jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'clover_reload_order_types',
                nonce:  '<?php echo wp_create_nonce('clover_make_request'); ?>'
            }, function(response) {
                if (response.success && response.data.order_types && response.data.order_types.length > 0) {
                    cloverOrderTypesCache = response.data.order_types;

                    // Repopulate default select
                    var defSelect = document.getElementById('clover_default_order_type_id');
                    var defVal    = defSelect ? defSelect.value : '';
                    if (defSelect) {
                        defSelect.innerHTML = '<option value="">-- No default --</option>';
                        response.data.order_types.forEach(function(ot) {
                            var opt = document.createElement('option');
                            opt.value       = ot.id;
                            opt.textContent = ot.label + ' (' + ot.id + ')';
                            opt.selected    = (ot.id === defVal);
                            defSelect.appendChild(opt);
                        });
                    }

                    // Repopulate all mapping selects
                    document.querySelectorAll('.clover-order-type-map-select').forEach(function(sel) {
                        var curVal = sel.value;
                        sel.innerHTML = buildOrderTypeOptions(response.data.order_types, curVal);
                    });

                    btn.innerHTML = '<span class="dashicons dashicons-yes" style="margin-top:3px;"></span> Reloaded!';
                    setTimeout(function() {
                        btn.disabled  = false;
                        btn.innerHTML = '<span class="dashicons dashicons-update" style="margin-top:3px;"></span> Reload Order Types';
                    }, 2000);
                } else {
                    btn.innerHTML = '<span class="dashicons dashicons-no" style="margin-top:3px;"></span> Failed';
                    setTimeout(function() {
                        btn.disabled  = false;
                        btn.innerHTML = '<span class="dashicons dashicons-update" style="margin-top:3px;"></span> Reload Order Types';
                    }, 2000);
                    alert('Error: ' + (response.data && response.data.error ? response.data.error : 'Failed to load order types'));
                }
            }).fail(function() {
                btn.disabled  = false;
                btn.innerHTML = '<span class="dashicons dashicons-update" style="margin-top:3px;"></span> Reload Order Types';
                alert('Error: Failed to connect to server');
            });
        }
        </script>
        <?php
    }

    /**
     * Shipping method -> Order Type mapping table callback
     */
    public function order_type_map_callback()
    {
        $map = get_option('clover_order_type_map', array());
        if (!is_array($map)) {
            $map = array();
        }
        $order_types = $this->get_clover_order_types();
        $shipping_methods = $this->get_wc_shipping_methods();

        if (empty($shipping_methods)) {
            echo '<p class="description" style="color:#dc3232;">No active shipping methods found. Add shipping methods in WooCommerce &gt; Settings &gt; Shipping.</p>';
            return;
        }

        echo '<table class="widefat" style="max-width:700px;">';
        echo '<thead><tr>';
        echo '<th style="width:35%">Shipping Method</th>';
        echo '<th style="width:25%">Zone</th>';
        echo '<th>Clover Order Type</th>';
        echo '</tr></thead><tbody>';

        foreach ($shipping_methods as $method) {
            $key = $method['key'];
            $selected_ot = $map[$key] ?? '';
            echo '<tr>';
            echo '<td><strong>' . esc_html($method['title']) . '</strong><br><code style="font-size:11px;">' . esc_html($key) . '</code></td>';
            echo '<td>' . esc_html($method['zone']) . '</td>';
            echo '<td>';
            echo '<select name="clover_order_type_map[' . esc_attr($key) . ']" class="clover-order-type-map-select" style="min-width:220px;">';
            echo '<option value="">-- Use default --</option>';
            foreach ($order_types as $ot) {
                $sel = selected($selected_ot, $ot['id'], false);
                echo '<option value="' . esc_attr($ot['id']) . '"' . $sel . '>' . esc_html($ot['label']) . ' (' . esc_html($ot['id']) . ')</option>';
            }
            echo '</select>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p class="description" style="margin-top:8px;">Select "Use default" to fall back to the Default Order Type configured above.</p>';

        if (empty($order_types)) {
            echo '<p class="description" style="color:#dc3232;">Could not fetch Clover order types. Verify API credentials and use the Reload button above.</p>';
        }
    }

    /**
     * Hours section callback
     */
    public function hours_section_callback()
    {
        echo '<p>Opening hours retrieved from Clover API.</p>';
        echo '<p><a href="' . esc_url(add_query_arg('refresh_clover_hours', '1')) . '" class="button button-small">Refresh Hours</a></p>';
    }

    /**
     * Store hours display callback
     */
    public function store_hours_display_callback()
    {
        $config = array(
            'base_url' => get_option('clover_api_base_url', 'https://api.clover.com/v3/merchants/'),
            'merchID' => get_option('clover_merchid'),
            'tokenBearer' => get_option('clover_token')
        );

        if (empty($config['merchID']) || empty($config['tokenBearer'])) {
            echo '<p style="color: #dc3232;">Please configure your API credentials first.</p>';
            return;
        }

        // Get data (uses transient)
        $business_hours = new \Src\BusinessHours\Business_Hours();
        $data = $business_hours->fetch_business_hours();

        if (is_wp_error($data) || isset($data['error'])) {
            echo '<p style="color: #dc3232;">Error fetching hours: ' . (is_wp_error($data) ? $data->get_error_message() : ($data['error'] ?? 'Unknown error')) . '</p>';
            return;
        }

        $this->render_hours_table($data);
    }

    /**
     * Render the hours table
     */
    private function render_hours_table($data)
    {
        if (!isset($data['elements'][0])) {
            echo '<p>No hours data found.</p>';
            return;
        }

        $config = $data['elements'][0];
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        echo '<table class="widefat fixed striped" style="width: 300px; margin-top: 10px;">';
        echo '<thead><tr><td><b>Day</b></td><td><b>Hours</b></td></tr></thead>';
        echo '<tbody>';

        foreach ($days as $day) {
            $day_name = ucfirst($day);
            $hours_str = 'Closed';

            if (isset($config[$day]['elements']) && is_array($config[$day]['elements'])) {
                $intervals = [];
                foreach ($config[$day]['elements'] as $interval) {
                    if (isset($interval['start']) && isset($interval['end'])) {
                        $start = $this->format_clover_time($interval['start']);
                        $end = $this->format_clover_time($interval['end']);
                        $intervals[] = $start . ' - ' . $end;
                    }
                }
                if (!empty($intervals)) {
                    $hours_str = implode(', ', $intervals);
                }
            }

            echo '<tr><td><strong>' . $day_name . '</strong></td><td>' . $hours_str . '</td></tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Format Clover time integer to human readable string
     */
    private function format_clover_time($val)
    {
        $val = (int) $val;
        $h = floor($val / 100);
        $m = $val % 100;
        $ampm = $h >= 12 ? 'PM' : 'AM';
        $h = $h % 12;
        if ($h == 0)
            $h = 12;
        return sprintf('%d:%02d %s', $h, $m, $ampm);
    }

    /**
     * Quick View show button callback
     */
    public function quick_view_show_button_callback()
    {
        $value = get_option('clover_quick_view_show_button', '1');
        echo '<label><input type="checkbox" id="clover_quick_view_show_button" name="clover_quick_view_show_button" value="1" ' . checked($value, '1', false) . ' /> ';
        echo 'Enable Quick View button</label>';
        echo '<p class="description">Show "Buy Now" button on product listings.</p>';
    }

    /**
     * Quick View show on shop callback
     */
    public function quick_view_show_on_shop_callback()
    {
        $value = get_option('clover_quick_view_show_on_shop', '1');
        echo '<label><input type="checkbox" id="clover_quick_view_show_on_shop" name="clover_quick_view_show_on_shop" value="1" ' . checked($value, '1', false) . ' /> ';
        echo 'Show on main Shop page</label>';
    }

    /**
     * Quick View show on category callback
     */
    public function quick_view_show_on_category_callback()
    {
        $value = get_option('clover_quick_view_show_on_category', '1');
        echo '<label><input type="checkbox" id="clover_quick_view_show_on_category" name="clover_quick_view_show_on_category" value="1" ' . checked($value, '1', false) . ' /> ';
        echo 'Show on Category archive pages</label>';
    }

    /**
     * Quick View show on tag callback
     */
    public function quick_view_show_on_tag_callback()
    {
        $value = get_option('clover_quick_view_show_on_tag', '0');
        echo '<label><input type="checkbox" id="clover_quick_view_show_on_tag" name="clover_quick_view_show_on_tag" value="1" ' . checked($value, '1', false) . ' /> ';
        echo 'Show on Tag archive pages</label>';
    }

    /**
     * Quick View show on pages callback
     */
    public function quick_view_show_on_pages_callback()
    {
        $value = get_option('clover_quick_view_show_on_pages', '1');
        echo '<label><input type="checkbox" id="clover_quick_view_show_on_pages" name="clover_quick_view_show_on_pages" value="1" ' . checked($value, '1', false) . ' /> ';
        echo 'Show on regular Pages</label>';
        echo '<p class="description">Enable for pages that display products using shortcodes or page builders.</p>';
    }

    /**
     * Quick View show on posts callback
     */
    public function quick_view_show_on_posts_callback()
    {
        $value = get_option('clover_quick_view_show_on_posts', '0');
        echo '<label><input type="checkbox" id="clover_quick_view_show_on_posts" name="clover_quick_view_show_on_posts" value="1" ' . checked($value, '1', false) . ' /> ';
        echo 'Show on Posts</label>';
        echo '<p class="description">Enable for blog posts that display products.</p>';
    }

    /**
     * Quick View button text callback
     */
    public function quick_view_button_text_callback()
    {
        $value = get_option('clover_quick_view_button_text');
        if ($value === false || $value === '') {
            $value = 'Buy now';
        }
        echo '<input type="text" id="clover_quick_view_button_text" name="clover_quick_view_button_text" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Default: "Buy now". Change the text shown on the Quick View button.</p>';
    }

    /**
     * Quick View button position callback
     */
    public function quick_view_button_position_callback()
    {
        $value = get_option('clover_quick_view_button_position');
        if ($value === false || $value === '') {
            $value = 'after_shop_loop_item';
        }

        $positions = array(
            'before_shop_loop_item' => 'Before product (top of card)',
            'before_shop_loop_item_title' => 'Before product image/title',
            'shop_loop_item_title' => 'At product title position',
            'after_shop_loop_item_title' => 'After product image/title',
            'before_add_to_cart_button' => 'Before "Add to Cart" button',
            'after_add_to_cart_button' => 'After "Add to Cart" button',
            'after_shop_loop_item' => 'After product (bottom of card) - Default',
        );

        echo '<select id="clover_quick_view_button_position" name="clover_quick_view_button_position" style="min-width: 300px;">';
        foreach ($positions as $key => $label) {
            $selected = selected($value, $key, false);
            echo '<option value="' . esc_attr($key) . '"' . $selected . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Choose where to display the Quick View button in the product card.</p>';
    }

    /**
     * Business Hours Banner - Show banner callback
     */
    public function bh_show_banner_callback()
    {
        $value = get_option('clover_bh_show_banner', '1');
        echo '<label><input type="checkbox" name="clover_bh_show_banner" value="1" ' . checked($value, '1', false) . ' /> ';
        echo 'Show open/closed banner on website</label>';
        echo '<p class="description">Displays a fixed banner at top or bottom of page showing if business is open or closed.</p>';
    }

    /**
     * Business Hours Banner - Banner position callback
     */
    public function bh_banner_position_callback()
    {
        $value = get_option('clover_bh_banner_position');
        if ($value === false || $value === '') {
            $value = 'bottom';
        }
        echo '<select name="clover_bh_banner_position">';
        echo '<option value="top" ' . selected($value, 'top', false) . '>Top of page</option>';
        echo '<option value="bottom" ' . selected($value, 'bottom', false) . '>Bottom of page</option>';
        echo '</select>';
        echo '<p class="description">Choose where the status banner appears.</p>';
    }

    /**
     * Business Hours Banner - Show countdown callback
     */
    public function bh_show_countdown_callback()
    {
        $value = get_option('clover_bh_show_countdown', '1');
        echo '<label><input type="checkbox" name="clover_bh_show_countdown" value="1" ' . checked($value, '1', false) . ' /> ';
        echo 'Show countdown timer when closed</label>';
        echo '<p class="description">Displays countdown until next opening time when business is closed.</p>';
    }

    /**
     * Logs section callback
     */
    public function logs_section_callback()
    {
        echo '<p>View and manage plugin logs. Logs are stored in the /logs folder.</p>';
    }

    /**
     * Enable logs callback
     */
    public function enable_logs_callback()
    {
        $value = get_option('clover_enable_logs', '0');
        echo '<label><input type="checkbox" name="clover_enable_logs" value="1" ' . checked($value, '1', false) . ' /> ';
        echo 'Enable plugin logging</label>';
        echo '<p class="description">When enabled, all clover_log() calls will be written to plugin log files.</p>';

        $value2 = get_option('clover_log_to_wp_debug', '0');
        echo '<br><label><input type="checkbox" name="clover_log_to_wp_debug" value="1" ' . checked($value2, '1', false) . ' /> ';
        echo 'Also log to WordPress debug.log</label>';
        echo '<p class="description">Also write logs to WordPress debug.log (requires WP_DEBUG enabled).</p>';
    }

    public function sanitize_tax_rates($input)
    {
        if (!is_array($input)) return array();
        return array_values(array_map('sanitize_text_field', $input));
    }

    public function taxes_section_callback()
    {
        echo '<p>Tax rates from your Clover merchant account. Check the ones you want applied to WooCommerce orders sent to Clover. The <strong>Default</strong> badge indicates which rate Clover applies automatically.</p>';
    }

    public function tax_rates_callback()
    {
        $enabled = get_option('clover_enabled_tax_rates', array());
        if (!is_array($enabled)) $enabled = array();

        $tax_rates = array();
        $config = array(
            'base_url'    => get_option('clover_api_base_url', 'https://api.clover.com/v3/merchants/'),
            'merchID'     => get_option('clover_merchid'),
            'tokenBearer' => get_option('clover_token'),
        );

        if (!empty($config['merchID']) && !empty($config['tokenBearer'])) {
            try {
                $orderService = new \Src\Services\OrderService($config);
                $response     = $orderService->getTaxRates();
                if (isset($response['data']['elements']) && is_array($response['data']['elements'])) {
                    $tax_rates = $response['data']['elements'];
                }
            } catch (Exception $e) {
                clover_log('Error fetching tax rates: ' . $e->getMessage());
            }
        }

        // Always emit a hidden field with all tax rate data so it's saved to cache on form submit
        $tax_rates_cache = array();
        foreach ($tax_rates as $t) {
            $tax_rates_cache[] = array(
                'id'        => $t['id'] ?? '',
                'name'      => $t['name'] ?? '',
                'rate'      => isset($t['rate']) ? intval($t['rate']) : 0,
                'isDefault' => !empty($t['isDefault']),
            );
        }
        echo '<input type="hidden" id="clover_tax_rates_cache" name="clover_tax_rates_cache" value="' . esc_attr(json_encode($tax_rates_cache)) . '" />';

        if (empty($tax_rates)) {
            echo '<p style="color:#dc3232;">Could not fetch tax rates. Verify API credentials.</p>';
            echo '<button type="button" class="button" id="reload-tax-rates-btn"><span class="dashicons dashicons-update" style="margin-top:3px;"></span> Reload</button>';
            echo '<div id="tax-rates-grid"></div>';
        } else {
            echo '<button type="button" class="button" id="reload-tax-rates-btn" style="margin-bottom:12px;"><span class="dashicons dashicons-update" style="margin-top:3px;"></span> Reload Tax Rates</button>';
            echo '<div id="tax-rates-grid">';
            echo '<table class="wp-list-table widefat fixed striped" style="max-width:700px;">';
            echo '<thead><tr>';
            echo '<th style="width:40px;">Apply</th>';
            echo '<th>Name</th>';
            echo '<th style="width:100px;">Rate</th>';
            echo '<th style="width:120px;">Status</th>';
            echo '</tr></thead><tbody>';

            foreach ($tax_rates as $tax) {
                $id         = $tax['id'] ?? '';
                $name       = $tax['name'] ?? 'Unnamed';
                $rate_raw   = isset($tax['rate']) ? intval($tax['rate']) : 0;
                $percent    = number_format($rate_raw / 100000, 4) + 0; // remove trailing zeros
                $is_default = !empty($tax['isDefault']);
                $checked    = in_array($id, $enabled) ? 'checked' : '';

                $default_badge = $is_default
                    ? '<span style="display:inline-block;background:#0073aa;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;">Default</span>'
                    : '';

                echo '<tr>';
                echo '<td><input type="checkbox" name="clover_enabled_tax_rates[]" value="' . esc_attr($id) . '" ' . $checked . ' /></td>';
                echo '<td>' . esc_html($name) . '</td>';
                echo '<td>' . esc_html($percent) . '%</td>';
                echo '<td>' . $default_badge . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</div>';
        }

        ?>
        <script>
        (function($){
            $('#reload-tax-rates-btn').on('click', function(){
                var btn = $(this);
                btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;display:inline-block;margin:0 5px 0 0;"></span> Loading...');
                $.post(ajaxurl, {
                    action: 'clover_reload_tax_rates',
                    nonce:  '<?php echo wp_create_nonce('clover_reload_tax_rates'); ?>'
                }, function(res){
                    if (res.success && res.data.tax_rates) {
                        var enabled = <?php echo json_encode($enabled); ?>;
                        var rows = '';
                        $.each(res.data.tax_rates, function(i, t){
                            var checked  = enabled.indexOf(t.id) !== -1 ? 'checked' : '';
                            var badge    = t.is_default ? '<span style="display:inline-block;background:#0073aa;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;">Default</span>' : '';
                            rows += '<tr>';
                            rows += '<td><input type="checkbox" name="clover_enabled_tax_rates[]" value="' + t.id + '" ' + checked + ' /></td>';
                            rows += '<td>' + t.name + '</td>';
                            rows += '<td>' + t.percent + '%</td>';
                            rows += '<td>' + badge + '</td>';
                            rows += '</tr>';
                        });
                        $('#tax-rates-grid table tbody').html(rows);
                        btn.html('<span class="dashicons dashicons-yes" style="margin-top:3px;"></span> Reloaded!');
                    } else {
                        btn.html('<span class="dashicons dashicons-no" style="margin-top:3px;"></span> Failed');
                        alert('Error: ' + (res.data ? res.data.error : 'Unknown error'));
                    }
                    setTimeout(function(){ btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top:3px;"></span> Reload Tax Rates'); }, 2000);
                }).fail(function(){
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top:3px;"></span> Reload Tax Rates');
                    alert('Request failed');
                });
            });
        })(jQuery);
        </script>
        <?php
    }


    public function discounts_section_callback()
    {
        echo '<p>Select a discount from your Clover catalog to automatically apply to all orders sent from WooCommerce.</p>';
    }

    public function discount_apply_callback()
    {
        $value = get_option('clover_discount_apply_to_orders', '0');
        echo '<label><input type="checkbox" name="clover_discount_apply_to_orders" value="1" ' . checked($value, '1', false) . ' /> ';
        echo 'Apply Clover discount to all WooCommerce orders</label>';
        echo '<p class="description">When enabled, the selected discount will be included in every order sent to Clover.</p>';
    }

    public function discount_id_callback()
    {
        $saved_id = get_option('clover_discount_id', '');
        $discounts = array();

        $config = array(
            'base_url'    => get_option('clover_api_base_url', 'https://api.clover.com/v3/merchants/'),
            'merchID'     => get_option('clover_merchid'),
            'tokenBearer' => get_option('clover_token'),
        );

        if (!empty($config['merchID']) && !empty($config['tokenBearer'])) {
            try {
                $orderService    = new \Src\Services\OrderService($config);
                $discountResponse = $orderService->getDiscounts();
                if (isset($discountResponse['data']['elements']) && is_array($discountResponse['data']['elements'])) {
                    $discounts = $discountResponse['data']['elements'];
                }
            } catch (Exception $e) {
                clover_log('Error fetching discounts: ' . $e->getMessage());
            }
        }

        // Auto-populate cached_percent from the loaded discounts list so it's
        // always in sync even if the user never re-selected after saving.
        $cached_percent = get_option('clover_discount_cached_percent', '');
        if (!empty($saved_id) && !empty($discounts)) {
            foreach ($discounts as $d) {
                if (($d['id'] ?? '') === $saved_id) {
                    $cached_percent = isset($d['percentage']) ? intval($d['percentage']) : $cached_percent;
                    break;
                }
            }
        }

        echo '<div style="display:flex;align-items:center;gap:10px;">';
        echo '<input type="hidden" id="clover_discount_cached_percent" name="clover_discount_cached_percent" value="' . esc_attr($cached_percent) . '" />';
        echo '<select id="clover_discount_id" name="clover_discount_id" style="min-width:300px;">';
        echo '<option value="" data-percent="" data-amount="">-- Select Discount --</option>';
        foreach ($discounts as $discount) {
            $name   = $discount['name'] ?? $discount['id'];
            $pct    = isset($discount['percentage']) ? intval($discount['percentage']) : 0;
            $amt    = isset($discount['amount'])     ? intval($discount['amount'])     : 0;
            $suffix = '';
            if ($pct > 0)      $suffix = ' (' . $pct . '%)';
            elseif ($amt > 0)  $suffix = ' ($' . number_format($amt / 100, 2) . ')';
            $label    = esc_html($name . $suffix);
            $selected = selected($saved_id, $discount['id'], false);
            echo '<option value="' . esc_attr($discount['id']) . '" data-percent="' . esc_attr($pct) . '" data-amount="' . esc_attr($amt) . '"' . $selected . '>' . $label . '</option>';
        }
        echo '</select>';

        echo '<button type="button" class="button" id="reload-discounts-btn">';
        echo '<span class="dashicons dashicons-update" style="margin-top:3px;"></span> Reload</button>';
        echo '</div>';

        if (empty($discounts)) {
            echo '<p class="description" style="color:#dc3232;">Could not fetch discounts. Verify API credentials.</p>';
        }

        ?>
        <script>
        (function($){
            // Sync cached percent when selection changes
            $('#clover_discount_id').on('change', function(){
                var opt     = $(this).find(':selected');
                var percent = opt.data('percent') || '';
                $('#clover_discount_cached_percent').val(percent);
            });

            $('#reload-discounts-btn').on('click', function(){
                var btn = $(this);
                btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;display:inline-block;margin:0 5px 0 0;"></span> Loading...');
                $.post(ajaxurl, {
                    action: 'clover_reload_discounts',
                    nonce:  '<?php echo wp_create_nonce('clover_reload_discounts'); ?>'
                }, function(res){
                    if (res.success && res.data.discounts && res.data.discounts.length > 0) {
                        var select  = $('#clover_discount_id');
                        var current = '<?php echo esc_js($saved_id); ?>';
                        select.html('<option value="">-- Select Discount --</option>');
                        $.each(res.data.discounts, function(i, d){
                            var suffix = '';
                        if (d.percentage) suffix = ' (' + d.percentage + '%)';
                        else if (d.amount)  suffix = ' ($' + (d.amount / 100).toFixed(2) + ')';
                        var opt = $('<option>').val(d.id).text((d.name || d.id) + suffix)
                            .attr('data-percent', d.percentage || '')
                            .attr('data-amount', d.amount || '');
                            if (d.id === current) opt.prop('selected', true);
                            select.append(opt);
                        });
                        btn.html('<span class="dashicons dashicons-yes" style="margin-top:3px;"></span> Reloaded!');
                    } else {
                        btn.html('<span class="dashicons dashicons-no" style="margin-top:3px;"></span> Failed');
                        alert('Error: ' + (res.data ? res.data.error : 'No discounts found'));
                    }
                    setTimeout(function(){ btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top:3px;"></span> Reload'); }, 2000);
                }).fail(function(){
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="margin-top:3px;"></span> Reload');
                    alert('Request failed');
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Business Hours Banner - Test connection callback
     */
    public function bh_test_connection_callback()
    {
        wp_nonce_field('clover_bh_test_nonce', 'clover_bh_test_nonce');
        ?>
        <button type="button" class="button button-secondary" id="clover-bh-test-btn">Test Connection</button>
        <div id="clover-bh-test-result" style="margin-top:10px;padding:10px;display:none;"></div>
        <button type="button" class="button button-secondary" id="clover-bh-debug-btn" style="margin-left:10px;">Debug Data</button>
        <div id="clover-bh-debug-result" style="margin-top:10px;padding:10px;display:none;white-space:pre-wrap;background:#f5f5f5;border:1px solid #ddd;"></div>

        <script>
        jQuery(document).ready(function($){
            $('#clover-bh-test-btn').click(function(){
                var btn = $(this), result = $('#clover-bh-test-result');
                btn.text('Testing...').prop('disabled', true);
                result.hide();

                $.post(ajaxurl, {
                    action: 'clover_bh_test_connection',
                    nonce: '<?php echo wp_create_nonce('clover_bh_test_nonce'); ?>'
                }, function(response) {
                    btn.text('Test Connection').prop('disabled', false);
                    result.show().html(response.data.message);
                    result.css('background', response.success ? '#d4edda' : '#f8d7da')
                          .css('color', response.success ? '#155724' : '#721c24');
                });
            });

            $('#clover-bh-debug-btn').click(function(){
                var btn = $(this), result = $('#clover-bh-debug-result');
                btn.text('Loading...').prop('disabled', true);
                result.hide();

                $.post(ajaxurl, {
                    action: 'clover_bh_debug_data',
                    nonce: '<?php echo wp_create_nonce('clover_bh_debug_nonce'); ?>'
                }, function(response) {
                    btn.text('Debug Data').prop('disabled', false);
                    result.show().text(response.data.raw);
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Sanitize checkbox input
     */
    public function sanitize_checkbox($input)
    {
        return !empty($input) ? '1' : '0';
    }

    /**
     * Sanitize text input
     */
    public function sanitize_text($input)
    {
        return sanitize_text_field(trim($input));
    }

    /**
     * Options page callback
     */
    public function options_page()
    {
        // Get current active tab from URL or default to 'api'
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'api';
        ?>
        <div class="wrap">
            <h1>Clover API Configuration</h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=clover-api-config&tab=api" class="nav-tab <?php echo $active_tab === 'api' ? 'nav-tab-active' : ''; ?>">API Configuration</a>
                <a href="?page=clover-api-config&tab=orders" class="nav-tab <?php echo $active_tab === 'orders' ? 'nav-tab-active' : ''; ?>">Orders & Payments</a>
                <a href="?page=clover-api-config&tab=pricing" class="nav-tab <?php echo $active_tab === 'pricing' ? 'nav-tab-active' : ''; ?>">Pricing</a>
                <a href="?page=clover-api-config&tab=discounts" class="nav-tab <?php echo $active_tab === 'discounts' ? 'nav-tab-active' : ''; ?>">Discounts</a>
                <a href="?page=clover-api-config&tab=taxes" class="nav-tab <?php echo $active_tab === 'taxes' ? 'nav-tab-active' : ''; ?>">Taxes and Fees</a>
                <a href="?page=clover-api-config&tab=hours" class="nav-tab <?php echo $active_tab === 'hours' ? 'nav-tab-active' : ''; ?>">Store Hours</a>
                <a href="?page=clover-api-config&tab=quickview" class="nav-tab <?php echo $active_tab === 'quickview' ? 'nav-tab-active' : ''; ?>">Quick View</a>
                <a href="?page=clover-api-config&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">Logs</a>
            </h2>

            <form method="post" action="options.php">
                <?php
                settings_fields('clover_settings');

                // Hidden fields to preserve settings from other tabs when saving one tab
                $tab_options = array(
                    'api' => array('clover_merchid', 'clover_token', 'clover_api_base_url'),
                    'orders' => array('clover_auto_print_orders', 'clover_auto_mark_as_paid', 'clover_payment_tender_id', 'clover_employee_id', 'clover_default_order_type_id'),
                    'pricing' => array('clover_import_fee_enabled', 'clover_import_fee_percent', 'clover_global_discount_enabled', 'clover_global_discount_percent', 'clover_global_discount_apply_modifiers'),
                    'hours'   => array('clover_prevent_orders_when_closed', 'clover_bh_show_banner', 'clover_bh_banner_position', 'clover_bh_show_countdown'),
                    'quickview' => array('clover_quick_view_show_button', 'clover_quick_view_show_on_shop', 'clover_quick_view_show_on_category', 'clover_quick_view_show_on_tag', 'clover_quick_view_show_on_pages', 'clover_quick_view_show_on_posts', 'clover_quick_view_button_text', 'clover_quick_view_button_position'),
                    'logs'      => array('clover_enable_logs', 'clover_log_to_wp_debug'),
                    'discounts' => array('clover_discount_apply_to_orders', 'clover_discount_id', 'clover_discount_cached_percent'),
                    'taxes'     => array('clover_enabled_tax_rates', 'clover_tax_rates_cache'),
                );
                $current_tab_options = $tab_options[$active_tab] ?? array();
                $all_options = array_merge(...array_values($tab_options));
                foreach ($all_options as $opt) {
                    if (!in_array($opt, $current_tab_options)) {
                        echo '<input type="hidden" name="' . esc_attr($opt) . '" value="' . esc_attr(get_option($opt)) . '" />';
                    }
                }

                // clover_order_type_map is an array — cannot use a single hidden field.
                // Emit one hidden field per key so it is preserved when saving other tabs.
                if ($active_tab !== 'orders') {
                    $map = get_option('clover_order_type_map', array());
                    if (is_array($map)) {
                        foreach ($map as $k => $v) {
                            echo '<input type="hidden" name="clover_order_type_map[' . esc_attr($k) . ']" value="' . esc_attr($v) . '" />';
                        }
                    }
                }

                if ($active_tab !== 'taxes') {
                    $enabled_taxes = get_option('clover_enabled_tax_rates', array());
                    if (is_array($enabled_taxes)) {
                        foreach ($enabled_taxes as $tax_id) {
                            echo '<input type="hidden" name="clover_enabled_tax_rates[]" value="' . esc_attr($tax_id) . '" />';
                        }
                    }
                }

                // Display settings based on active tab
                if ($active_tab === 'api') {
                    do_settings_sections('clover-settings');
                } elseif ($active_tab === 'orders') {
                    do_settings_sections('clover-settings-orders');
                } elseif ($active_tab === 'pricing') {
                    do_settings_sections('clover-settings-pricing');
                } elseif ($active_tab === 'hours') {
                    do_settings_sections('clover-settings-hours');
                } elseif ($active_tab === 'quickview') {
                    do_settings_sections('clover-settings-quickview');
                } elseif ($active_tab === 'discounts') {
                    do_settings_sections('clover-settings-discounts');
                } elseif ($active_tab === 'taxes') {
                    do_settings_sections('clover-settings-taxes');
                } elseif ($active_tab === 'logs') {
                    do_settings_sections('clover-settings-logs');

                    // Log viewer
                    if (get_option('clover_enable_logs', '0') === '1') {
                        echo '<h2>Log Viewer</h2>';
                        echo '<div style="margin-bottom: 10px;">';
                        echo '<button type="button" class="button" id="clover-refresh-logs">Refresh Logs</button> ';
                        echo '<button type="button" class="button" id="clover-clear-current">Clear Current Log</button> ';
                        echo '<button type="button" class="button" id="clover-clear-logs">Clear Old Logs</button>';
                        echo '</div>';
                        echo '<div id="clover-log-viewer" style="background:#1e1e1e;color:#0f0;padding:10px;font-family:monospace;font-size:12px;max-height:400px;overflow:auto;white-space:pre-wrap;word-break:break-all;">';
                        echo 'Enable logging to see logs here...';
                        echo '</div>';
                        ?>
                        <script>
                        jQuery(document).ready(function($){
                            function loadLogs() {
                                $.post(ajaxurl, {
                                    action: 'clover_get_logs',
                                    nonce: '<?php echo wp_create_nonce('clover_logs_nonce'); ?>'
                                }, function(response) {
                                    if (response.success && response.data.logs) {
                                        $('#clover-log-viewer').text(response.data.logs);
                                    } else {
                                        $('#clover-log-viewer').text('No logs found');
                                    }
                                }).fail(function() {
                                    $('#clover-log-viewer').text('Error loading logs');
                                });
                            }

                            $('#clover-refresh-logs').click(function(){
                                loadLogs();
                            });

                            $('#clover-clear-current').click(function(){
                                if (confirm('Delete current log file?')) {
                                    $.post(ajaxurl, {
                                        action: 'clover_clear_logs',
                                        type: 'current',
                                        nonce: '<?php echo wp_create_nonce('clover_logs_nonce'); ?>'
                                    }, function(response) {
                                        alert(response.data.message || 'Done');
                                        loadLogs();
                                    });
                                }
                            });

                            $('#clover-clear-logs').click(function(){
                                if (confirm('Delete logs older than 30 days?')) {
                                    $.post(ajaxurl, {
                                        action: 'clover_clear_logs',
                                        type: 'old',
                                        nonce: '<?php echo wp_create_nonce('clover_logs_nonce'); ?>'
                                    }, function(response) {
                                        alert(response.data.message || 'Done');
                                        loadLogs();
                                    });
                                }
                            });

                            // Load on page load
                            loadLogs();
                        });
                        </script>
                        <?php
                    }
                }

                // Show Test API Connection only on API Configuration tab
                if ($active_tab === 'api'):
                    ?>
                    <h2>Test API Connection</h2>
                    <div id="clover-connection-test">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Test Credentials</th>
                                <td>
                                    <input type="button" id="test_connection" class="button-secondary" value="Test Connection" />
                                    <span id="connection-spinner" class="spinner" style="display: none;"></span>
                                </td>
                            </tr>
                        </table>
                        <div id="connection-result" style="margin-top: 10px; padding: 10px; display: none;"></div>
                    </div>
                <?php
                endif;

                submit_button();
                ?>
            </form>


            <!-- Test Request Section
            <h2>Test API Requests</h2>
            <div id="clover-test-section">
                <table class="form-table">
                    <tr>
                        <th scope="row">GET Request</th>
                        <td>
                            <input type="text" id="get_endpoint" name="get_endpoint" size="50" />
                            <input type="button" id="send_get_request" class="button-primary" value="Send GET Request" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">POST Request</th>
                        <td>
                            <input type="text" id="post_endpoint" name="post_endpoint" placeholder="orders, inventory/items, etc." size="50" />
                            <br><br>
                            <label for="post_body">JSON Body:</label><br>
                            <textarea id="post_body" name="post_body" rows="8" cols="70" placeholder='{"key": "value"}'></textarea>
                            <br><br>
                            <input type="button" id="send_post_request" class="button-primary" value="Send POST Request" />
                        </td>
                    </tr>
                </table>

                <div id="clover-response" style="margin-top: 20px;"></div>
            </div>-->

            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
                    var nonce = '<?php echo wp_create_nonce('clover_make_request'); ?>';

                $('#test_connection').click(function() {
                $(this).prop('disabled', true);
                $('#connection-spinner').show();
                $('#connection-result').hide();

                $.post(ajaxUrl, {
                    action: 'clover_test_connection',
                    nonce: nonce
                }, function(response) {
                    if (response.success) {
                        var successResult = '<div class="notice notice-success is-dismissible"><p>' +
                            response.data.message + ' (Status: ' + response.data.status_code + ')' +
                            '</p></div>';
                        $('#connection-result').html(successResult).show();
                    } else {
                        var errorResult = '<div class="notice notice-error is-dismissible"><p>' +
                            response.data.error + ' (Status: ' + response.data.status_code + ')' +
                            '</p></div>';
                        $('#connection-result').html(errorResult).show();
                    }
                }).fail(function() {
                    var errorResult = '<div class="notice notice-error is-dismissible"><p>Connection test failed. Please check your settings.</p></div>';
                    $('#connection-result').html(errorResult).show();
                }).always(function() {
                    $('#test_connection').prop('disabled', false);
                    $('#connection-spinner').hide();
                });
            });

                    $('#send_get_request').click(function() {
                        var endpoint = $('#get_endpoint').val().trim();

                        if (!endpoint) {
                            alert('Please enter an endpoint');
                            return;
                        }

                        $.post(ajaxUrl, {
                            action: 'clover_make_request',
                            method: 'GET',
                            endpoint: endpoint,
                            nonce: nonce
                        }, function(response) {
                            if (response.success) {
                                var result = '<div class="card" style="padding: 15px; margin-top: 15px; border: 1px solid #ccc; background-color: #f9f9f9;">';
                                result += '<h3>Response (Status: ' + response.data.status_code + ')</h3>';
                                result += '<pre style="white-space: pre-wrap; word-break: break-all;">' + JSON.stringify(JSON.parse(response.data.body), null, 2) + '</pre>';
                                result += '</div>';
                                $('#clover-response').html(result);
                            } else {
                                var errorResult = '<div class="notice notice-error"><p>Error: ' + response.data.error + '</p></div>';
                                $('#clover-response').html(errorResult);
                            }
                        }).fail(function() {
                            var errorResult = '<div class="notice notice-error"><p>Request failed. Please check your connection and settings.</p></div>';
                            $('#clover-response').html(errorResult);
                        });
                    });

                    $('#send_post_request').click(function() {
                        var endpoint = $('#post_endpoint').val().trim();
                        var body = $('#post_body').val().trim();

                        if (!endpoint) {
                            alert('Please enter an endpoint');
                            return;
                        }

                        if (!body) {
                            alert('Please enter a JSON body');
                            return;
                        }

                        // Validate JSON
                        try {
                            JSON.parse(body);
                        } catch(e) {
                            alert('Invalid JSON in request body');
                            return;
                        }

                        $.post(ajaxUrl, {
                            action: 'clover_make_request',
                            method: 'POST',
                            endpoint: endpoint,
                            body: body,
                            nonce: nonce
                        }, function(response) {
                            if (response.success) {
                                var result = '<div class="card" style="padding: 15px; margin-top: 15px; border: 1px solid #ccc; background-color: #f9f9f9;">';
                                result += '<h3>Response (Status: ' + response.data.status_code + ')</h3>';
                                result += '<pre style="white-space: pre-wrap; word-break: break-all;">' + JSON.stringify(JSON.parse(response.data.body), null, 2) + '</pre>';
                                result += '</div>';
                                $('#clover-response').html(result);
                            } else {
                                var errorResult = '<div class="notice notice-error"><p>Error: ' + response.data.error + '</p></div>';
                                $('#clover-response').html(errorResult);
                            }
                        }).fail(function() {
                            var errorResult = '<div class="notice notice-error"><p>Request failed. Please check your connection and settings.</p></div>';
                            $('#clover-response').html(errorResult);
                        });
                    });
                });
            </script>
        </div>
        <?php
    }

    public function import_page()
    {
        $categories = [
            ['id' => 'WES9EQ1NVW8CW', 'name' => 'Anytime Specials'],
            ['id' => '7GT7FY6452XRR', 'name' => 'Appetizers'],
            ['id' => 'E7HT7707ABZVJ', 'name' => 'Burgers'],
            ['id' => '3NFEY0R1QXC12', 'name' => 'Lunch Special'],
            ['id' => 'NQTVXRRGVXHF2', 'name' => 'Pasta'],
            ['id' => 'B35AFV0E88W60', 'name' => 'Pick uP Special'],
        ];
        ?>
            <div class="wrap">
                <h1>Importar productos desde API</h1>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="clover_categories">
                                Categorías a importar
                            </label>
                        </th>
                        <td>
                            <select
                                name="clover_categories[]"
                                id="clover_categories"
                                multiple
                                style="min-width:300px; height:160px;"
                            >
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo esc_attr($category['id']); ?>">
                                        <?php echo esc_html($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <p class="description">
                                Selecciona una o más categorías.
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="button" id="import_products_btn" class="button button-primary">
                        Importar productos
                    </button>
                    <span id="import-spinner" class="spinner" style="float:none; display:inline-block;"></span>
                </p>

                <div id="import-result" style="margin-top: 20px;"></div>
            </div>

            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
                    var nonce = '<?php echo wp_create_nonce('clover_import_products_nonce'); ?>';

                    $('#import_products_btn').click(function() {
                        var selectedCategories = $('#clover_categories').val();

                        if (!selectedCategories || selectedCategories.length === 0) {
                            alert('Por favor selecciona al menos una categoría.');
                            return;
                        }

                        // Disable button and show spinner
                        $(this).prop('disabled', true);
                        $('#import-spinner').addClass('is-active');

                        $.post(ajaxUrl, {
                            action: 'clover_import_items',
                            categories: selectedCategories,
                            nonce: nonce
                        }, function(response) {
                            if (response.success) {
                                var successResult = '<div class="notice notice-success is-dismissible"><p>' +
                                    response.data.message +
                                    '</p></div>';
                                $('#import-result').html(successResult);
                            } else {
                                var errorResult = '<div class="notice notice-error is-dismissible"><p>' +
                                    response.data.error +
                                    '</p></div>';
                                $('#import-result').html(errorResult);
                            }
                        }).fail(function() {
                            var errorResult = '<div class="notice notice-error is-dismissible"><p>Error en la conexión. Por favor inténtalo de nuevo.</p></div>';
                            $('#import-result').html(errorResult);
                        }).always(function() {
                            // Re-enable button and hide spinner
                            $('#import_products_btn').prop('disabled', false);
                            $('#import-spinner').removeClass('is-active');
                        });
                    });
                });
            </script>
    <?php
    }

    public function create_product_from_item($item, string $category_slug = null)
    {
        $name = $item['name'] ?? 'Producto sin nombre';
        $original_price = ($item['price'] ?? 0) / 100;  // Original price from Clover
        $sku = $item['id'] ?? '';
        $description = $item['description'] ?? '';

        // Apply import fee if enabled
        $fee_enabled = get_option('clover_import_fee_enabled', '0');
        $fee_percent = get_option('clover_import_fee_percent', '20');
        $price = $original_price;

        if ($fee_enabled === '1' && $fee_percent > 0) {
            $price = $original_price * (1 + (floatval($fee_percent) / 100));
        }

        // Check if product with this SKU already exists
        $existing_product_id = wc_get_product_id_by_sku($sku);

        if ($existing_product_id) {
            // Update existing product
            $product = wc_get_product($existing_product_id);
            $product->set_name($name);
            $product->set_regular_price($price);
            // Store original price for reference
            $product->update_meta_data('_clover_original_price', $original_price);
            // Update description if available from Clover
            if (!empty($description)) {
                $product->set_description($description);
            }
            clover_log("Updating existing product with SKU {$sku}, ID: {$existing_product_id}, Original: {$original_price}, With Fee: {$price}");
        } else {
            // Create new product
            $product = new WC_Product_Simple();
            $product->set_name($name);
            $product->set_regular_price($price);
            if (!empty($sku)) {
                $product->set_sku($sku);
            }
            // Store original price for reference
            $product->update_meta_data('_clover_original_price', $original_price);
            // Set description if available from Clover
            if (!empty($description)) {
                $product->set_description($description);
            }
        }

        $product->save();

        // Automatically import modifiers for this product after saving
        $product_id = $product->get_id();
        clover_log("Product saved with ID: {$product_id}, SKU: {$sku}");

        // Note: Modifiers are now handled in the main import process to avoid duplicate API calls
        // The modifier import will be done in the main process with pre-fetched data

        // Handle categories from the item data
        clover_log('Processing categories for item: ' . print_r($item, true));

        if (isset($item['categories']) && is_array($item['categories']) && isset($item['categories']['elements'])) {
            clover_log('Found categories in item data: ' . print_r($item['categories'], true));
            $category_ids = array();

            foreach ($item['categories']['elements'] as $category) {
                clover_log('Processing category: ' . print_r($category, true));
                if (isset($category['name'])) {
                    $category_name = $category['name'];
                    clover_log("Processing category name: {$category_name}");

                    $term = get_term_by('name', $category_name, 'product_cat');

                    if (!$term) {
                        // Create the category if it doesn't exist
                        clover_log("Category {$category_name} does not exist, creating...");
                        $new_term = wp_insert_term($category_name, 'product_cat');
                        if (!is_wp_error($new_term)) {
                            $term_id = $new_term['term_id'];
                            clover_log("Created category {$category_name} with ID: {$term_id}");
                        } else {
                            clover_log("Error creating category {$category_name}: " . $new_term->get_error_message());
                            continue;
                        }
                    } else {
                        $term_id = $term->term_id;
                        clover_log("Found existing category {$category_name} with ID: {$term_id}");
                    }

                    $category_ids[] = (int) $term_id;
                } else {
                    clover_log('Category element missing name: ' . print_r($category, true));
                }
            }

            // Assign all categories to the product
            if (!empty($category_ids)) {
                clover_log('Assigning categories ' . implode(',', $category_ids) . ' to product ID: ' . $product->get_id());
                wp_set_object_terms(
                    $product->get_id(),
                    $category_ids,
                    'product_cat',
                    false
                );
            } else {
                clover_log('No categories to assign to product ID: ' . $product->get_id());
            }
        } else {
            clover_log('No categories found in item data. Categories key exists: ' . (isset($item['categories']) ? 'YES' : 'NO'));
            // Fallback to the original category slug if categories weren't expanded
            if ($category_slug) {
                $term = get_term_by('slug', $category_slug, 'product_cat');
                if (!$term) {
                    $new_term = wp_insert_term($category_slug, 'product_cat');
                    $term_id = is_array($new_term) ? $new_term['term_id'] : $new_term;
                } else {
                    $term_id = $term->term_id;
                }

                wp_set_object_terms(
                    $product->get_id(),
                    [(int) $term_id],
                    'product_cat',
                    false
                );
            } else {
                // No categories from Clover - assign to Uncategorized (default WooCommerce category)
                clover_log('No categories from Clover and no category_slug, assigning to Uncategorized');
                if (function_exists('assign_product_to_uncategorized')) {
                    assign_product_to_uncategorized($product->get_id());
                } else {
                    clover_log('ERROR: assign_product_to_uncategorized function not found!');
                }
            }
        }

        // Note: Image import is now handled in phase 2 of the import process
        // to avoid blocking product creation with image downloads
    }

    /**
     * Import product images for a batch of items (Phase 2 of import)
     *
     * @param array $items Array of item data with id and name
     * @param Clover_Progress_Tracker $tracker Progress tracker instance
     * @param int $batch_start Index to start processing from
     * @return array Result with processed count and status
     */
    public function import_product_images_batch($items, $tracker, $batch_start = 0)
    {
        $processed_count = 0;
        $batch_size = 1;  // Process one image at a time for progress tracking

        for ($i = $batch_start; $i < count($items); $i++) {
            $item = $items[$i];
            $item_id = $item['id'];
            $item_name = $item['name'];

            try {
                // Update progress
                $tracker->update_detailed_progress(
                    $item_id,
                    $item_name,
                    'Importing Image',
                    'processing',
                    'Getting image...'
                );

                // Get product by SKU
                $product_id = wc_get_product_id_by_sku($item_id);
                if (!$product_id) {
                    $tracker->update_image_progress(
                        $item_id,
                        $item_name,
                        'Importing Image',
                        'skipped',
                        'Product not found'
                    );
                    continue;
                }

                $product = wc_get_product($product_id);
                if (!$product) {
                    $tracker->update_image_progress(
                        $item_id,
                        $item_name,
                        'Importing Image',
                        'skipped',
                        'Product not found'
                    );
                    continue;
                }

                // Import the image (replace if exists)
                $image_result = $this->import_product_image($product, $item_id);

                // Verify image was set - refresh product from database to get actual image ID
                $product->get_data_store()->read($product);
                $new_image_id = $product->get_image_id();

                if ($image_result && $new_image_id) {
                    $tracker->update_image_progress(
                        $item_id,
                        $item_name,
                        'Importing Image',
                        'success',
                        'Image imported successfully'
                    );
                } elseif ($image_result === '404') {
                    // Image not found on Clover (404)
                    $tracker->update_image_progress(
                        $item_id,
                        $item_name,
                        'Importing Image',
                        'skipped',
                        'Image not found'
                    );
                } else {
                    $tracker->update_image_progress(
                        $item_id,
                        $item_name,
                        'Importing Image',
                        'warning',
                        'Image import failed (ID: ' . ($image_result ?: 'null') . ')'
                    );
                }

                $processed_count++;

                // Return after processing batch size to allow progress updates
                if ($processed_count >= $batch_size) {
                    return array(
                        'status' => 'processing',
                        'processed_count' => $processed_count,
                        'total_processed' => $i + 1,
                        'batch_start' => $i + 1
                    );
                }
            } catch (Exception $e) {
                clover_log("Error importing image for item {$item_id}: " . $e->getMessage());
                $tracker->update_image_progress(
                    $item_id,
                    $item_name,
                    'Importing Image',
                    'error',
                    'Image import failed: ' . $e->getMessage()
                );
                $processed_count++;

                // Continue to next item even on error
                if ($processed_count >= $batch_size) {
                    return array(
                        'status' => 'processing',
                        'processed_count' => $processed_count,
                        'total_processed' => $i + 1,
                        'batch_start' => $i + 1
                    );
                }
            }
        }

        // All images processed
        return array(
            'status' => 'completed',
            'processed_count' => $processed_count,
            'total_processed' => count($items),
            'batch_start' => count($items)
        );
    }

    /**
     * Import product image from Clover
     *
     * @param WC_Product $product The WooCommerce product object
     * @param string $item_id The Clover item ID
     * @return int|false The attachment ID on success, false on failure
     */
    public function import_product_image($product, $item_id)
    {
        $image_url = "https://cloverstatic.com/menu-assets/items/{$item_id}.jpeg";

        clover_log("=== IMAGE IMPORT START for item {$item_id}, product ID: {$product->get_id()} ===");
        clover_log("Image URL: {$image_url}");

        try {
            // Check if product already has an image - if so, delete it first to replace
            $current_image_id = $product->get_image_id();
            if ($current_image_id) {
                clover_log("Product {$product->get_id()} already has an image (ID: {$current_image_id}), replacing...");
                // Delete the old attachment
                wp_delete_attachment($current_image_id, true);
                clover_log("Old attachment deleted: {$current_image_id}");
                // Clear the cached image ID from the product object
                $product->set_image_id(0);
            }

            // Download the image
            $upload_dir = wp_upload_dir();
            clover_log('Upload dir: ' . print_r($upload_dir, true));

            if ($upload_dir['error']) {
                clover_log('Upload directory error: ' . $upload_dir['error']);
                return false;
            }

            $image_filename = basename($image_url);
            $image_path = $upload_dir['path'] . '/' . $image_filename;
            clover_log("Image path: {$image_path}");

            // Use WordPress HTTP API to download the image with increased timeout
            clover_log("Downloading image from: {$image_url}");
            $response = wp_remote_get($image_url, array(
                'timeout' => 120,  // Increased to 120 seconds for large images
                'stream' => true,
                'filename' => $image_path,
                'sslverify' => false  // Disable SSL verification for external CDN
            ));

            if (is_wp_error($response)) {
                $error_msg = $response->get_error_message();
                clover_log('WP Error downloading image: ' . $error_msg);

                // Check if it's a timeout and retry once
                if (strpos($error_msg, 'timed out') !== false) {
                    clover_log('Timeout detected, retrying once...');
                    $response = wp_remote_get($image_url, array(
                        'timeout' => 180,  // Even longer timeout for retry
                        'stream' => true,
                        'filename' => $image_path,
                        'sslverify' => false
                    ));

                    if (is_wp_error($response)) {
                        clover_log('Retry also failed: ' . $response->get_error_message());
                        return false;
                    }
                } else {
                    return false;
                }
            }

            $status_code = wp_remote_retrieve_response_code($response);
            clover_log("HTTP status code: {$status_code}");

            if ($status_code !== 200) {
                clover_log("Failed to download image for item {$item_id}: HTTP status {$status_code}");
                // Clean up the failed download
                if (file_exists($image_path)) {
                    @unlink($image_path);
                }

                // Return specific error code for 404
                if ($status_code === 404) {
                    return '404';  // Image not found on Clover
                }
                return false;  // Other error
            }

            // Check if file was downloaded successfully
            if (!file_exists($image_path)) {
                clover_log("Image file not found after download for item {$item_id}");
                return false;
            }

            $file_size = filesize($image_path);
            clover_log("Image downloaded successfully, size: {$file_size} bytes");

            // Get file type and validate it's an image
            $file_type = wp_check_filetype($image_filename);
            clover_log('File type: ' . print_r($file_type, true));

            if (strpos($file_type['type'], 'image/') !== 0) {
                clover_log("Downloaded file is not an image for item {$item_id}");
                @unlink($image_path);
                return false;
            }

            // Prepare attachment data
            $attachment = array(
                'post_mime_type' => $file_type['type'],
                'post_title' => sanitize_file_name($image_filename),
                'post_content' => '',
                'post_status' => 'inherit',
                'post_excerpt' => "Product image for {$product->get_name()}"
            );
            clover_log('Attachment data: ' . print_r($attachment, true));

            // Insert the attachment
            clover_log('Inserting attachment...');
            $image_id = wp_insert_attachment($attachment, $image_path, $product->get_id());

            if (is_wp_error($image_id)) {
                clover_log('Failed to insert attachment: ' . $image_id->get_error_message());
                @unlink($image_path);
                return false;
            }

            clover_log("Attachment inserted with ID: {$image_id}");

            // Include the image metadata functions if not already loaded
            require_once (ABSPATH . 'wp-admin/includes/image.php');

            // Generate and save metadata
            clover_log('Generating metadata...');
            $metadata = wp_generate_attachment_metadata($image_id, $image_path);
            clover_log('Metadata: ' . print_r($metadata, true));

            wp_update_attachment_metadata($image_id, $metadata);

            // Set as product image
            clover_log('Setting product image...');
            $product->set_image_id($image_id);
            $product->save();

            clover_log("Product saved, image ID: {$product->get_image_id()}");
            clover_log("=== IMAGE IMPORT SUCCESS for item {$item_id}, attachment ID: {$image_id} ===");

            return $image_id;
        } catch (Exception $e) {
            clover_log("=== IMAGE IMPORT EXCEPTION for item {$item_id} ===");
            clover_log('Exception: ' . $e->getMessage());
            clover_log('File: ' . $e->getFile() . ' on line ' . $e->getLine());
            // Clean up on error
            if (isset($image_path) && file_exists($image_path)) {
                @unlink($image_path);
            }
            return false;
        }
    }

    public function get_info_page()
    {
        ?>
        <div class="wrap">
            <style>
                #infoModal table.wp-list-table {
                    table-layout: auto !important;
                    width: auto !important;
                }

                #infoModal th,
                #infoModal td {
                    vertical-align: middle;
                }

                #infoModal th.checkbox-col,
                #infoModal td.checkbox-col {
                    width: 40px;
                    text-align: center;
                }

                #infoModal th.name-col,
                #infoModal td.name-col {
                    white-space: nowrap;
                }

                #infoModal th.id-col,
                #infoModal td.id-col {
                    width: 120px;
                    text-align: center;
                    white-space: nowrap;
                }

                #infoModal table.wp-list-table {
                    width: 900px !important;
                    table-layout: fixed !important;
                }
                #infoModal .modal-content {
                    background-color: #fff;
                    margin: 5% auto;
                    padding: 20px;
                    border: 1px solid #888;

                    width: min-content;
                    min-width: 720px;
                    max-width: 90vw;

                    max-height: 80vh;
                    overflow-y: auto;
                }
                input[type="checkbox"], #selectAll {
                    margin: unset;
                }

            </style>


            <h1>Getting data from Clover.</h1>

            <div class="stats-container" style="display: flex; justify-content: space-around; margin: 30px 0;">

    <div class="stat-box" style="text-align: center; cursor: pointer; padding: 20px 30px;" onclick="openModal('items')">
        <div class="stat-number" style="font-size: 48px; font-weight: bold; color: #0073aa; margin-bottom: 12px;">
            <?php echo $this->get_items_count(); ?>
        </div>
        <div class="stat-label" style="margin-top: 6px;">Items</div>
    </div>

    <div class="stat-box" style="text-align: center; cursor: pointer; padding: 20px 30px;" onclick="openModal('categories')">
        <div class="stat-number" style="font-size: 48px; font-weight: bold; color: #0073aa; margin-bottom: 12px;">
            <?php echo $this->get_categories_count(); ?>
        </div>
        <div class="stat-label" style="margin-top: 6px;">Categories</div>
    </div>

    <div class="stat-box" style="text-align: center; cursor: pointer; padding: 20px 30px;" onclick="openModal('modifiers')">
        <div class="stat-number" style="font-size: 48px; font-weight: bold; color: #0073aa; margin-bottom: 12px;">
            <?php echo $this->get_modifiers_count(); ?>
        </div>
        <div class="stat-label" style="margin-top: 6px;">Modifiers</div>
    </div>

    <div class="stat-box" style="text-align: center; cursor: pointer; padding: 20px 30px;" onclick="openModal('modifierGroups')">
        <div class="stat-number" style="font-size: 48px; font-weight: bold; color: #0073aa; margin-bottom: 12px;">
            <?php echo $this->get_modifier_groups_count(); ?>
        </div>
        <div class="stat-label" style="margin-top: 6px;">Modifiers Group</div>
    </div>

<!--    <div class="stat-box" style="text-align: center; cursor: pointer; padding: 20px 30px;" onclick="openModal('modifierGroups')">
        <div class="stat-number" style="font-size: 48px; font-weight: bold; color: #0073aa; margin-bottom: 12px;">
            <?php echo $this->get_tax_rates_count(); ?>
        </div>
        <div class="stat-label" style="margin-top: 6px;">Tax Rates</div>
    </div>-->
<!--    <div class="stat-box" style="text-align: center; cursor: pointer; padding: 20px 30px;" onclick="openModal('modifierGroups')">
        <div class="stat-number" style="font-size: 48px; font-weight: bold; color: #0073aa; margin-bottom: 12px;">
           0
        </div>
        <div class="stat-label" style="margin-top: 6px;">Orders Types No</div>
    </div>-->

</div>


            <!-- Modal -->
            <div id="infoModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
                <div class="modal-content">
                    <span class="close" style="color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer;" onclick="closeModal()">&times;</span>
                    <h2 id="modalTitle">Información</h2>
                    <div id="modalBody">
                        <!-- Category Filter for Items -->
                        <div id="categoryFilterContainer" style="display: none; margin-bottom: 15px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                            <label style="display: block; margin-bottom: 10px; font-weight: bold;">Filter by category:</label>
                            <div id="categoryCheckboxes" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; background: #fff; padding: 10px; border-radius: 3px;"></div>
                            <p style="font-size: 12px; color: #555; margin: 8px 0 0 0;">Select categories to filter items. Ctrl + click to select just that one category and unselect the rest.<br>At least one category must be selected to apply filter.</p>
                            <button type="button" class="button" onclick="applyCategoryFilterAndImport()" style="margin-top: 8px;">Apply filter and Import</button>
                            <button type="button" class="button" onclick="applyCategoryFilter()" style="margin-top: 8px; margin-left: 5px;">Apply filter</button>
                            <button type="button" class="button" onclick="selectAllCategories()" style="margin-top: 8px; margin-left: 5px;">Select all</button>
                        </div>

                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th class="checkbox-col">
                                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" checked>
                                    </th>
                                    <th class="name-col"><label for="selectAll">Name</label></th>
                                    <th class="id-col">ID</th>
                                </tr>
                            </thead>
                            <tbody id="modalTableBody">
                                <!-- Dynamic content will be inserted here -->
                            </tbody>
                        </table>
                        <p style="text-align: right; margin-top: 20px;">
                            <button type="button" class="button button-primary" onclick="importSelected()">Import selected</button>
                            <button type="button" class="button" onclick="closeModal()" style="display: none;">Cerrar</button>
                        </p>
                    </div>
                </div>
            </div>

            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
                    var allItemsData = []; // Store all items for client-side filtering

                    window.openModal = function(type) {
                        var title = '';
                        var action = '';

                        switch(type) {
                            case 'items':
                                title = 'Items';
                                action = 'clover_get_items';
                                // Show category filter for items
                                $('#categoryFilterContainer').show();
                                loadCategoriesIntoFilter();
                                break;
                            case 'categories':
                                title = 'Categories';
                                action = 'clover_get_categories';
                                $('#categoryFilterContainer').hide();
                                break;
                            case 'modifiers':
                                title = 'Modifiers';
                                action = 'clover_get_modifiers';
                                $('#categoryFilterContainer').hide();
                                break;
                            case 'modifierGroups':
                                title = 'Modifier Groups';
                                action = 'clover_get_modifier_groups';
                                $('#categoryFilterContainer').hide();
                                break;
                        }

                        document.getElementById('modalTitle').textContent = title;

                        // Show loading indicator
                        document.getElementById('modalTableBody').innerHTML = '<tr><td colspan="3">Loading...</td></tr>';
                        document.getElementById('infoModal').style.display = 'block';

                        // Fetch data via AJAX
                        $.post(ajaxUrl, {
                            action: action,
                            nonce: '<?php echo wp_create_nonce('clover_make_request'); ?>'
                        }, function(response) {
                            if (response.success) {
                                if (type === 'items') {
                                    allItemsData = response.data.items; // Store for filtering
                                }
                                populateModalTable(response.data.items, type);
                            } else {
                                document.getElementById('modalTableBody').innerHTML = '<tr><td colspan="3">Error: ' + response.data.error + '</td></tr>';
                            }
                        }).fail(function() {
                            document.getElementById('modalTableBody').innerHTML = '<tr><td colspan="3">Error loading data</td></tr>';
                        });
                    };

                    // Load categories into the filter dropdown
                    function loadCategoriesIntoFilter() {
                        $.post(ajaxUrl, {
                            action: 'clover_get_categories',
                            nonce: '<?php echo wp_create_nonce('clover_make_request'); ?>'
                        }, function(response) {
                            if (response.success) {
                                var $container = $('#categoryCheckboxes');
                                $container.empty();

                                if (response.data.items.length === 0) {
                                    $container.html('<p style="color: #999; font-style: italic;">No categories found</p>');
                                    return;
                                }

                                // Sort categories alphabetically by name
                                var sortedCategories = response.data.items.sort(function(a, b) {
                                    return a.name.localeCompare(b.name);
                                });

                                // Create checkbox list with all categories selected by default
                                var html = '<div style="display: flex; flex-wrap: wrap; gap: 8px;">';
                                sortedCategories.forEach(function(cat) {
                                    html += '<label class="category-filter-label" style="display: inline-flex; align-items: center; background: #fff; padding: 6px 10px; border: 1px solid #ddd; border-radius: 3px; cursor: pointer; font-size: 13px;">';
                                    html += '<input type="checkbox" class="category-filter-checkbox" name="category_filter[]" value="' + cat.id + '" style="margin-right: 6px;" checked>';
                                    html += cat.name;
                                    html += '</label>';
                                });
                                html += '</div>';

                                $container.html(html);

                                // Apply filter by default with all categories selected
                                applyCategoryFilter();

                                // Bind click event handler using jQuery - bind to label to capture clicks on both label and checkbox
                                $('#categoryCheckboxes').on('click', '.category-filter-label', function(e) {
                                    var checkbox = $(this).find('.category-filter-checkbox')[0];
                                    handleCategoryCheckboxChange(checkbox, e);
                                });
                            }
                        });
                    }

                    // Handle category checkbox change - prevent unchecking last category
                    window.handleCategoryCheckboxChange = function(checkbox, event) {
                        var $checkboxes = $('#categoryCheckboxes input[type="checkbox"]');
                        var checkedCount = $checkboxes.filter(':checked').length;

                        // Ctrl+click: select only this category, uncheck all others
                        if (event && (event.ctrlKey || event.metaKey)) {
                            $checkboxes.prop('checked', false);
                            checkbox.checked = true;
                            applyCategoryFilter();
                            return;
                        }

                        // If trying to uncheck and it's the last one, prevent it
                        if (!checkbox.checked && checkedCount === 0) {
                            checkbox.checked = true;
                            alert('At least one category must be selected.');
                        }
                    };

                    // Apply category filter to items
                    window.applyCategoryFilter = function() {
                        var selectedCategories = [];
                        $('#categoryCheckboxes input[type="checkbox"]:checked').each(function() {
                            selectedCategories.push($(this).val());
                        });

                        // Validate at least one category is selected
                        if (selectedCategories.length === 0) {
                            alert('Please select at least one category, or leave all unchecked to show all items without filtering.');
                            return;
                        }

                        // Filter items by selected categories
                        var filteredItems = allItemsData.filter(function(item) {
                            if (!item.categories || !item.categories.elements) {
                                return false;
                            }
                            // Check if item has any of the selected categories
                            for (var i = 0; i < item.categories.elements.length; i++) {
                                if (selectedCategories.indexOf(item.categories.elements[i].id) !== -1) {
                                    return true;
                                }
                            }
                            return false;
                        });

                        populateModalTable(filteredItems, 'items');
                    };

                    window.applyCategoryFilterAndImport = function() {
                        applyCategoryFilter();
                        importSelected();
                    }

                    // Select all categories and apply filter
                    window.selectAllCategories = function() {
                        $('#categoryCheckboxes input[type="checkbox"]').prop('checked', true);
                        applyCategoryFilter();
                    };

                    // Global variables for tracking import process
                    var currentPollInterval = null;
                    var currentLastProcessed = 0;
                    var currentNoProgressCount = 0;

                    window.closeModal = function() {
                        // Clear any ongoing intervals
                        if (currentPollInterval) {
                            clearInterval(currentPollInterval);
                            currentPollInterval = null;
                        }

                        // Reset the modal content to initial state
                        var modalBody = document.getElementById('modalBody');
                        var buttonsContainer = modalBody.querySelector('p[style*="text-align: right"]');

                        // Show the original buttons and hide progress container
                        if (buttonsContainer) {
                            buttonsContainer.style.display = 'block';
                            var closeButton = buttonsContainer.querySelector('button:last-child');
                            if (closeButton) {
                                closeButton.style.display = 'none'; // Hide close button
                                closeButton.textContent = 'Cerrar'; // Reset text
                            }
                        }

                        // Remove progress container if it exists
                        var progressContainer = document.getElementById('import-progress-container');
                        if (progressContainer) {
                            progressContainer.remove();
                        }

                        // Reset modal to initial state
                        document.getElementById('infoModal').style.display = 'none';

                        // Reset global variables
                        currentLastProcessed = 0;
                        currentNoProgressCount = 0;
                    };

                    window.toggleSelectAll = function(checkbox) {
                        var checkboxes = document.querySelectorAll('#modalTableBody input[type="checkbox"]');
                        for(var i = 0; i < checkboxes.length; i++) {
                            checkboxes[i].checked = checkbox.checked;
                        }
                    };

                    window.importSelected = function() {
                        var selectedRows = document.querySelectorAll('#modalTableBody input[type="checkbox"]:checked');
                        if(selectedRows.length === 0) {
                            alert('Por favor seleccione al menos un elemento para importar.');
                            return;
                        }

                        var selectedItems = [];
                        for(var i = 0; i < selectedRows.length; i++) {
                            selectedItems.push({
                                id: selectedRows[i].value,
                                name: selectedRows[i].closest('tr').querySelector('.item-name').textContent
                            });
                        }

                        // Determine the type based on the modal title and store globally
                        var type = '';
                        var title = document.getElementById('modalTitle').textContent;
                        if(title.includes('Item')) type = 'items';
                        else if(title.includes('Categor')) type = 'categories';
                        else if(title.includes('Modificad')) type = 'modifiers';
                        else if(title.includes('Grupo')) type = 'modifierGroups';

                        // Store type globally for importMore function
                        window.currentImportType = type;

                        // Get selected categories from filter (for items import)
                        var selectedCategories = [];
                        if (type === 'items') {
                            $('#categoryCheckboxes input[type="checkbox"]:checked').each(function() {
                                selectedCategories.push($(this).val());
                            });
                        }

                        // Hide the import button and show the progress UI
                        var importBtnHide = document.querySelector('#modalBody p:last-child');
                        if (importBtnHide) importBtnHide.style.display = 'none';

                        // Remove any existing progress container first
                        var existingProgress = document.getElementById('import-progress-container');
                        if (existingProgress) existingProgress.remove();

                        // Add progress tracking UI
                        var progressHtml = `
                            <div id="import-progress-container">
                                <div id="progress-bar-container" style="width: 100%; background-color: #f0f0f0; border-radius: 4px; margin: 10px 0; height: 20px;">
                                    <div id="progress-bar" style="width: 0%; height: 100%; background-color: #0073aa; border-radius: 4px; transition: width 0.3s;"></div>
                                </div>
                                <div id="progress-status" style="margin: 10px 0; font-weight: bold; text-align: center;">Starting import...</div>
                                <div id="progress-details" style="margin: 10px 0; max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background-color: #f9f9f9;">
                                    <div id="progress-logs">Initialization...</div>
                                </div>
                                <div style="text-align: center; margin-top: 10px;">
                                    <button type="button" class="button" id="cancel-import-btn" style="display: none;" onclick="cancelImport()">Cancel Import</button>
                                    <button type="button" class="button button-primary" id="retry-import-btn" style="display: none;" onclick="retryImport()">Retry Import</button>
                                    <button type="button" class="button button-primary" id="import-more-btn" style="display: none;" onclick="importMore()">Import More Items</button>
                                </div>
                            </div>
                        `;

                        document.getElementById('modalBody').insertAdjacentHTML('beforeend', progressHtml);

                        // Start the import process - only items use the async method for now
                        if (type === 'items') {
                            jQuery.post(ajaxUrl, {
                                action: 'clover_start_import_items',
                                items: selectedItems,
                                categories: selectedCategories,
                                nonce: '<?php echo wp_create_nonce('clover_make_request'); ?>'
                            }, function(response) {
                                if(response.success) {
                                    // Start the async import process
                                    startAsyncImport(response.data.process_id);
                                } else {
                                    alert('Error starting import: ' + (response.data.error || 'Unknown error'));
                                    var importBtnContainer = document.querySelector('#modalBody p:last-child');
                                    if (importBtnContainer) importBtnContainer.style.display = 'block';
                                    document.getElementById('import-progress-container').remove();
                                }
                            }).fail(function() {
                                alert('Error starting import: Connection failed');
                                var importBtnContainer2 = document.querySelector('#modalBody p:last-child');
                                if (importBtnContainer2) importBtnContainer2.style.display = 'block';
                                document.getElementById('import-progress-container').remove();

                                // Make sure to clear any intervals if they exist
                                if (currentPollInterval) {
                                    clearInterval(currentPollInterval);
                                    currentPollInterval = null;
                                }
                            });
                        } else {
                            // For other types, use the original method
                            jQuery.ajax({
                                url: ajaxUrl,
                                type: 'POST',
                                data: {
                                    action: 'clover_import_selected_' + type,
                                    items: selectedItems,
                                    nonce: '<?php echo wp_create_nonce('clover_make_request'); ?>'
                                },
                                timeout: 120000, // 2 minutes timeout
                                success: function(response) {
                                    if(response.success) {
                                        document.getElementById('progress-status').textContent = response.data.message || 'Importación completada exitosamente.';
                                        document.getElementById('progress-bar').style.width = '100%';

                                        // Add summary
                                        var summaryHtml = '<div style="margin-top: 10px; padding: 10px; background-color: #eef7ee; border: 1px solid #0a0; border-radius: 4px;">' +
                                                         '<h4>Import Summary:</h4>' +
                                                         '<p><em>' + (response.data.message || 'Import completed successfully.') + '</em></p>' +
                                                         '</div>';

                                        document.getElementById('progress-details').insertAdjacentHTML('afterend', summaryHtml);

                                        // Show close button
                                        document.querySelector('#modalBody p:last-child button:last-child').style.display = 'inline-block';
                                        document.querySelector('#modalBody p:last-child button:last-child').textContent = 'Close';
                                    } else {
                                        document.getElementById('progress-status').textContent = 'Error: ' + (response.data.error || 'Ocurrió un error durante la importación.');
                                        document.getElementById('progress-bar').style.backgroundColor = '#dc3232';
                                        document.getElementById('progress-bar').style.width = '100%';

                                        // Add error message
                                        var errorHtml = '<div style="margin-top: 10px; padding: 10px; background-color: #fdeaea; border: 1px solid #dc3232; border-radius: 4px;">' +
                                                       '<h4>Import Error:</h4>' +
                                                       '<p><em>' + (response.data.error || 'An error occurred during import.') + '</em></p>' +
                                                       '</div>';

                                        document.getElementById('progress-details').insertAdjacentHTML('afterend', errorHtml);

                                        // Show close button
                                        document.querySelector('#modalBody p:last-child button:last-child').style.display = 'inline-block';
                                        document.querySelector('#modalBody p:last-child button:last-child').textContent = 'Close';
                                    }
                                },
                                error: function(xhr, status, error) {
                                    document.getElementById('progress-status').textContent = 'Connection error during import: ' + error;
                                    document.getElementById('progress-bar').style.backgroundColor = '#dc3232';
                                    document.getElementById('progress-bar').style.width = '100%';

                                    // Add error message
                                    var errorHtml = '<div style="margin-top: 10px; padding: 10px; background-color: #fdeaea; border: 1px solid #dc3232; border-radius: 4px;">' +
                                                   '<h4>Connection Error:</h4>' +
                                                   '<p><em>Error de conexión durante la importación: ' + error + '</em></p>' +
                                                   '</div>';

                                    document.getElementById('progress-details').insertAdjacentHTML('afterend', errorHtml);

                                    // Show close button
                                    document.querySelector('#modalBody p:last-child button:last-child').style.display = 'inline-block';
                                    document.querySelector('#modalBody p:last-child button:last-child').textContent = 'Close';
                                }
                            });
                        }
                    };

                    // Function to start the async import process
                    function startAsyncImport(processId) {
                        // Clear any existing interval first
                        if (currentPollInterval) {
                            clearInterval(currentPollInterval);
                            currentPollInterval = null;
                        }

                        // Clear any existing summary to avoid duplicate detection
                        var existingSummary = document.querySelector('#import-summary');
                        if (existingSummary) existingSummary.remove();

                        // Store process ID for retry functionality
                        currentProcessId = processId;

                        // Track the last progress to detect if it's stuck
                        currentLastProcessed = 0;
                        currentNoProgressCount = 0;
                        currentLastPhase = 'importing_products'; // Track phase changes
                        var maxNoProgressAttempts = 20; // Increased to 20 to allow for slow image downloads (poll every 2s = 40 seconds)
                        var importCompleted = false; // Flag to prevent multiple completion handlers

                        // Set up polling to check progress and trigger processing
                        currentPollInterval = setInterval(function() {
                            // Skip if import already completed
                            if (importCompleted) return;

                            // First, trigger the next batch of processing
                            jQuery.post(ajaxUrl, {
                                action: 'clover_import_items_async',
                                process_id: processId,
                                nonce: '<?php echo wp_create_nonce('clover_make_request'); ?>'
                            }).always(function() {
                                // Then get the progress to update the UI
                                jQuery.post(ajaxUrl, {
                                    action: 'clover_get_import_progress',
                                    process_id: processId,
                                    nonce: '<?php echo wp_create_nonce('clover_make_request'); ?>'
                                }, function(response) {
                                    // Skip processing if import already completed
                                    if (importCompleted) return;

                                    if(response.success) {
                                        var progressData = response.data.progress;
                                        var percentage = response.data.percentage;
                                        var recentActivities = response.data.recent_activities;

                                        // Check if progress has stalled
                                        // During phase 1, check processed_items; during phase 2, check images_processed
                                        var isPhase2 = progressData.phase === 'importing_images' || response.data.status === 'importing_images';
                                        var currentProgress = isPhase2 ?
                                            (progressData.images_processed || 0) :
                                            progressData.processed_items;

                                        // Reset counter if phase changed (phase 1 -> phase 2 transition)
                                        if (progressData.phase !== currentLastPhase) {
                                            currentNoProgressCount = 0;
                                            currentLastPhase = progressData.phase;
                                            currentLastProcessed = 0; // Reset progress tracker for new phase
                                        }

                                        if (currentProgress === currentLastProcessed) {
                                            currentNoProgressCount++;
                                        } else {
                                            currentNoProgressCount = 0; // Reset counter when progress is made
                                        }
                                        currentLastProcessed = currentProgress;

                                        // Update progress bar
                                        document.getElementById('progress-bar').style.width = percentage + '%';

                                        // Update status text
                                        document.getElementById('progress-status').textContent = progressData.status;

                                        // Update progress logs - show ALL activities (cumulative history)
                                        var logsHtml = '';

                                        // Show all activities in reverse chronological order (newest first)
                                        var allActivities = recentActivities.slice().reverse();

                                        allActivities.forEach(function(activity) {
                                            console.log('Activity: ' + activity.name + ' - Status: ' + activity.status + ' - Step: ' + activity.step);

                                            // Determine icon and color based on status
                                            var statusIcon, statusColor;
                                            if (activity.status === 'success') {
                                                statusIcon = '✓';
                                                statusColor = '#0a0'; // Green
                                            } else if (activity.status === 'skipped') {
                                                statusIcon = '➔';
                                                statusColor = '#0077ff'; // Blue
                                            } else if (activity.status === 'warning') {
                                                statusIcon = '⚠';
                                                statusColor = '#ffa500'; // Orange
                                            } else if (activity.status === 'error') {
                                                statusIcon = '✗';
                                                statusColor = '#f00'; // Red
                                            } else {
                                                statusIcon = '•';
                                                statusColor = '#000'; // Blue for processing
                                            }

                                            var stepInfo = activity.step ? '[' + activity.step + '] ' : '';
                                            logsHtml += '<div style="padding: 5px 0; border-bottom: 1px solid #eee;">' +
                                                       '<span style="color: ' + statusColor + '; font-weight: bold; font-family: monospace; display: inline-block; width: 20px; text-align: center;">' + statusIcon + '</span> ' +
                                                       stepInfo +
                                                       '<strong>' + activity.name + '</strong> (' + activity.id + ') - ' +
                                                       (activity.message || activity.status) +
                                                       '</div>';
                                        });

                                        if(recentActivities.length === 0) {
                                            logsHtml = '<div>Waiting for processing to start...</div>';
                                        }

                                        document.getElementById('progress-logs').innerHTML = logsHtml;

                                        // Check if import is complete
                                        // Two-phase import: Phase 1 = products, Phase 2 = images
                                        var isPhase2 = progressData.phase === 'importing_images' || response.data.status === 'importing_images';
                                        var allProductsDone = progressData.processed_items >= progressData.total_items;
                                        var allImagesDone = progressData.images_processed && progressData.images_processed >= progressData.total_items;

                                        // Import is complete when:
                                        // - Phase 2 is done (all images imported), OR
                                        // - Phase 1 done and no phase 2 data exists (old behavior fallback)
                                        var importComplete = (isPhase2 && allImagesDone) || (allProductsDone && !isPhase2 && progressData.completed);

                                        if (importComplete || progressData.cancelled) {
                                            // Check if summary already exists to avoid duplicates
                                            var existingSummary = document.querySelector('#import-summary');
                                            if (!existingSummary) {
                                                // Set flag to prevent multiple completion handlers
                                                importCompleted = true;

                                                if (currentPollInterval) {
                                                    clearInterval(currentPollInterval);
                                                    currentPollInterval = null;
                                                }

                                                // Show completion message
                                                var completionMessage = 'Import completed successfully!';
                                                if (progressData.cancelled) {
                                                    completionMessage = 'Import was cancelled.';
                                                }

                                                // Add summary with phase info
                                                var errorCount = progressData.errors ? progressData.errors.length : 0;
                                                var successCount = progressData.processed_items - errorCount;
                                                var imagesImported = progressData.images_imported_success || 0;
                                                var imagesSkipped = (progressData.images_processed || 0) - imagesImported;

                                                var summaryHtml = '<div id="import-summary" style="margin-top: 10px; padding: 10px; background-color: #eef7ee; border: 1px solid #0a0; border-radius: 4px;">' +
                                                                 '<h4>Import summary:</h4>' +
                                                                 '<p><strong>Total items:</strong> ' + progressData.total_items + '</p>' +
                                                                 '<p><strong>Products imported:</strong> ' + successCount + '</p>' +
                                                                 '<p><strong>Images imported:</strong> ' + imagesImported + '</p>';

                                                if (imagesSkipped > 0) {
                                                    summaryHtml += '<p><strong>Images not found:</strong> ' + imagesSkipped + '</p>';
                                                }

                                                summaryHtml += '<p><strong>Errors:</strong> ' + errorCount + '</p>' +
                                                                 '<p><em>' + completionMessage + '</em></p>' +
                                                                 '</div>';

                                                document.getElementById('progress-details').insertAdjacentHTML('afterend', summaryHtml);

                                                // Clear polling interval
                                                if (currentPollInterval) {
                                                    clearInterval(currentPollInterval);
                                                    currentPollInterval = null;
                                                }

                                                // Show Close and Import More buttons
                                                var cancelBtn = document.getElementById('cancel-import-btn');
                                                if (cancelBtn) cancelBtn.style.display = 'none';
                                                document.getElementById('import-more-btn').style.display = 'inline-block';
                                                var closeBtn = document.querySelector('#modalBody p:last-child button:last-child');
                                                if (closeBtn) {
                                                    closeBtn.style.display = 'inline-block';
                                                    closeBtn.textContent = 'Close';
                                                }
                                            }
                                        } else if (currentNoProgressCount >= maxNoProgressAttempts) {
                                            // Check if error message already exists to avoid duplicates
                                            var existingError = document.querySelector('#import-error');
                                            if (!existingError) {
                                                // If no progress for too long, stop the process and show error
                                                if (currentPollInterval) {
                                                    clearInterval(currentPollInterval);
                                                    currentPollInterval = null;
                                                }

                                                document.getElementById('progress-status').textContent = 'Import appears to be stuck. Please try again.';
                                                document.getElementById('progress-bar').style.backgroundColor = '#dc3232';

                                                // Add error message
                                                var errorHtml = '<div id="import-error" style="margin-top: 10px; padding: 10px; background-color: #fdeaea; border: 1px solid #dc3232; border-radius: 4px;">' +
                                                               '<h4>Import Stuck:</h4>' +
                                                               '<p><em>The import process has not made progress for too long. The process may have encountered an error. Please try again or contact support.</em></p>' +
                                                               '</div>';

                                                document.getElementById('progress-details').insertAdjacentHTML('afterend', errorHtml);

                                                // Show Close and Retry buttons
                                                var cancelBtn2 = document.getElementById('cancel-import-btn');
                                                if (cancelBtn2) cancelBtn2.style.display = 'none';
                                                document.getElementById('retry-import-btn').style.display = 'inline-block';
                                                var closeBtn2 = document.querySelector('#modalBody p:last-child button:last-child');
                                                if (closeBtn2) {
                                                    closeBtn2.style.display = 'inline-block';
                                                    closeBtn2.textContent = 'Close';
                                                }
                                            }
                                        }
                                    } else if(response.data && response.data.error && response.data.progress && response.data.progress.timed_out) {
                                        // Check if timeout message already exists to avoid duplicates
                                        var existingTimeout = document.querySelector('#import-timeout');
                                        if (!existingTimeout) {
                                            // Handle timeout scenario
                                            if (currentPollInterval) {
                                                clearInterval(currentPollInterval);
                                                currentPollInterval = null;
                                            }

                                            document.getElementById('progress-status').textContent = 'Import has timed out. Please restart the import.';
                                            document.getElementById('progress-bar').style.backgroundColor = '#dc3232';

                                            // Add timeout message
                                            var errorHtml = '<div id="import-timeout" style="margin-top: 10px; padding: 10px; background-color: #fdeaea; border: 1px solid #dc3232; border-radius: 4px;">' +
                                                           '<h4>Import Timeout:</h4>' +
                                                           '<p><em>The import process has timed out. Please restart the import. If the problem persists, there may be an issue with your server or API connection.</em></p>' +
                                                           '</div>';

                                            document.getElementById('progress-details').insertAdjacentHTML('afterend', errorHtml);

                                            // Show Close and Retry buttons
                                            var cancelBtn3 = document.getElementById('cancel-import-btn');
                                            if (cancelBtn3) cancelBtn3.style.display = 'none';
                                            document.getElementById('retry-import-btn').style.display = 'inline-block';
                                            var closeBtn3 = document.querySelector('#modalBody p:last-child button:last-child');
                                            if (closeBtn3) {
                                                closeBtn3.style.display = 'inline-block';
                                                closeBtn3.textContent = 'Close';
                                            }
                                        }
                                    } else {
                                        console.error('Error getting progress:', response.data.error);
                                        if (currentPollInterval) {
                                            clearInterval(currentPollInterval);
                                            currentPollInterval = null;
                                        }
                                    }
                                }).fail(function() {
                                    console.error('Failed to get progress');
                                    if (currentPollInterval) {
                                        clearInterval(currentPollInterval);
                                        currentPollInterval = null;
                                    }
                                });
                            });
                        }, 2000); // Poll every 2 seconds

                        // Show cancel button
                        var cancelBtn = document.getElementById('cancel-import-btn');
                        if (cancelBtn) cancelBtn.style.display = 'inline-block';
                    }

                    // Function to cancel the import
                    window.cancelImport = function() {
                        if (!confirm('Are you sure you want to cancel the import? Products already imported will remain, but the import process will stop.')) {
                            return;
                        }

                        console.log('Cancelling import...');

                        // Stop the polling interval
                        if (currentPollInterval) {
                            clearInterval(currentPollInterval);
                            currentPollInterval = null;
                        }

                        // Update UI to show cancelled state
                        document.getElementById('progress-status').textContent = 'Import cancelled by user';
                        document.getElementById('progress-bar').style.backgroundColor = '#ffa500';

                        // Hide cancel button
                        var cancelBtn = document.getElementById('cancel-import-btn');
                        if (cancelBtn) cancelBtn.style.display = 'none';

                        // Show Import More button so user can continue with remaining items
                        var importMoreBtn = document.getElementById('import-more-btn');
                        if (importMoreBtn) importMoreBtn.style.display = 'inline-block';

                        // Show close button
                        var closeBtn = document.querySelector('#modalBody p:last-child button:last-child');
                        if (closeBtn) {
                            closeBtn.style.display = 'inline-block';
                            closeBtn.textContent = 'Close';
                        }

                        // Add cancellation message
                        var cancelMsg = '<div style="margin-top: 10px; padding: 10px; background-color: #fff3cd; border: 1px solid #ffa500; border-radius: 4px;">' +
                                       '<p><strong>Import Cancelled</strong></p>' +
                                       '<p>Products imported before cancellation have been saved. You can select remaining items and click "Import More Items" to continue, or click "Close" to exit.</p>' +
                                       '</div>';

                        document.getElementById('progress-details').insertAdjacentHTML('beforeend', cancelMsg);

                        // Optionally mark the process as cancelled in database
                        if (currentProcessId) {
                            jQuery.post(ajaxUrl, {
                                action: 'clover_cancel_import',
                                process_id: currentProcessId,
                                nonce: '<?php echo wp_create_nonce('clover_make_request'); ?>'
                            });
                        }
                    };

                    // Function to retry the import
                    window.retryImport = function() {
                        // Hide retry and close buttons
                        document.getElementById('retry-import-btn').style.display = 'none';
                        var closeBtnR = document.querySelector('#modalBody p:last-child button:last-child');
                        if (closeBtnR) closeBtnR.style.display = 'none';

                        // Reset progress UI
                        document.getElementById('progress-bar').style.width = '0%';
                        document.getElementById('progress-bar').style.backgroundColor = '#0073aa';
                        document.getElementById('progress-status').textContent = 'Retrying import...';
                        document.getElementById('progress-logs').innerHTML = 'Starting retry...';

                        // Remove any existing summary or error messages
                        var existingSummary = document.querySelector('#import-summary');
                        if (existingSummary) existingSummary.remove();
                        var existingError = document.querySelector('#import-error');
                        if (existingError) existingError.remove();
                        var existingTimeout = document.querySelector('#import-timeout');
                        if (existingTimeout) existingTimeout.remove();

                        // Show cancel button
                        var cancelBtnR = document.getElementById('cancel-import-btn');
                        if (cancelBtnR) cancelBtnR.style.display = 'inline-block';

                        // Restart the import with the same items
                        startAsyncImport(currentProcessId);
                    };

                    // Function to import more items
                    window.importMore = function() {
                        console.log('Import More clicked');

                        // Stop the polling interval
                        if (currentPollInterval) {
                            clearInterval(currentPollInterval);
                            currentPollInterval = null;
                        }

                        // Hide import more button
                        document.getElementById('import-more-btn').style.display = 'none';

                        // Reset progress bar
                        var progressBar = document.getElementById('progress-bar');
                        if (progressBar) {
                            progressBar.style.width = '0%';
                            progressBar.style.backgroundColor = '#0073aa';
                        }
                        var progressStatus = document.getElementById('progress-status');
                        if (progressStatus) {
                            progressStatus.textContent = 'Starting import...';
                        }

                        // Remove progress container
                        var progressContainer = document.getElementById('import-progress-container');
                        if (progressContainer) progressContainer.remove();

                        // Remove any existing summary or error messages
                        var existingSummary = document.querySelector('#import-summary');
                        if (existingSummary) existingSummary.remove();
                        var existingError = document.querySelector('#import-error');
                        if (existingError) existingError.remove();

                        // Show the original import button
                        var importBtnContainer = document.querySelector('#modalBody p:last-child');
                        if (importBtnContainer) {
                            importBtnContainer.style.display = 'block';
                            var buttons = importBtnContainer.querySelectorAll('button');
                            buttons.forEach(function(btn) {
                                btn.style.display = 'inline-block';
                                if (btn.id === 'import_products_btn') {
                                    btn.textContent = 'Importar productos';
                                    btn.disabled = false;
                                }
                            });
                        }

                        console.log('Modal reset for new selection. Type:', window.currentImportType);
                        // User can now select different items and click "Importar productos" again
                    };

                   function populateModalTable(items, type) {
                        var tbody = document.getElementById('modalTableBody');
                        tbody.innerHTML = '';

                        if (!items || items.length === 0) {
                            tbody.innerHTML = '<tr><td colspan="3">No hay elementos disponibles</td></tr>';
                            return;
                        }

                        items.forEach(function(item) {
                            var row = document.createElement('tr');

                            // Checkbox column
                            var checkboxCell = document.createElement('td');
                            checkboxCell.className = 'checkbox-col';
                            var checkbox = document.createElement('input');
                            checkbox.type = 'checkbox';
                            checkbox.value = item.id;
                            checkbox.checked = true; // Check all items by default
                            checkboxCell.appendChild(checkbox);

                            // Name column
                            var nameCell = document.createElement('td');
                            nameCell.className = 'name-col item-name';
                            nameCell.textContent =
                                item.name || item.label || item.title || item.displayName || item.noun || 'Sin nombre';

                            // ID column
                            var idCell = document.createElement('td');
                            idCell.className = 'id-col';
                            idCell.textContent = item.id;

                            row.appendChild(checkboxCell);
                            row.appendChild(nameCell);
                            row.appendChild(idCell);

                            tbody.appendChild(row);
                        });
                    }


                    // Close modal when clicking outside of it
                    window.onclick = function(event) {
                        var modal = document.getElementById('infoModal');
                        if (event.target == modal) {
                            closeModal();
                        }
                    };

                    // Also handle the close button click
                    document.addEventListener('click', function(event) {
                        if (event.target.classList.contains('close')) {
                            closeModal();
                        }
                    });
                });
            </script>
        </div>
        <?php
    }

    // Helper methods to get counts

    public function get_items_count()
    {
        $config = require CLOVER_PLUGIN_PATH . 'config/api.php';
        $service = new \Src\Services\OrderService($config);

        try {
            $response = $service->getItems(['limit' => 1000]);
            // clover_log(print_r($response['data']['elements'] ,true));
            return !empty($response['data']['elements']) ? count($response['data']['elements']) : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    public function get_categories_count()
    {
        $config = require CLOVER_PLUGIN_PATH . 'config/api.php';
        $service = new \Src\Services\OrderService($config);

        try {
            $response = $service->getAllCategory(['limit' => 1000]);
            // clover_log(print_r($response['data']['elements'] ,true));
            return !empty($response['data']['elements']) ? count($response['data']['elements']) : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    public function get_modifiers_count()
    {
        $config = require CLOVER_PLUGIN_PATH . 'config/api.php';
        $service = new \Src\Services\OrderService($config);

        try {
            $response = $service->getAllModifiers(['limit' => 1000]);
            // clover_log(print_r($response['data']['elements'] ,true));
            return !empty($response['data']['elements']) ? count($response['data']['elements']) : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    public function get_modifier_groups_count()
    {
        $config = require CLOVER_PLUGIN_PATH . 'config/api.php';
        $service = new \Src\Services\OrderService($config);

        try {
            $response = $service->getAllModifiersGroup(['limit' => 1000]);
            //  clover_log(print_r($response['data']['elements'] ,true));
            return !empty($response['data']['elements']) ? count($response['data']['elements']) : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    public function get_tax_rates_count()
    {
        $config = require CLOVER_PLUGIN_PATH . 'config/api.php';
        $service = new \Src\Services\OrderService($config);

        try {
            $response = $service->getTaxRates(['limit' => 1000]);
            //  clover_log(print_r($response['data']['elements'] ,true));
            return !empty($response['data']['elements']) ? count($response['data']['elements']) : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    public function import_customers_page()
    {
        ?>
            <div class="wrap">
                <h1>Import Customers from Clover</h1>

                <div class="card" style="padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; background: #fff;">
                    <h2>Import All Customers</h2>
                    <p>Click the button below to import all customers from your Clover account to WooCommerce.</p>

                   <p class="submit">
                       <button type="button" id="import-all-customers-btn" class="button button-primary button-large">
                           Import All Customers
                       </button>
                       <span id="import-all-customers-spinner" class="spinner" style="float:none; display:inline-block;"></span>
                   </p>

                   <div id="import-all-customers-result" style="margin-top: 20px;"></div>
               </div>
           </div>

           <script type="text/javascript">
               jQuery(document).ready(function($) {
                   var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
                   var nonce = '<?php echo wp_create_nonce('clover_make_request'); ?>';

                   // Handler para importar todos los clientes
                   $('#import-all-customers-btn').click(function() {
                       if (!confirm('Are you sure you want to import ALL customers from Clover? This may take a while.')) {
                           return;
                       }

                     // Deshabilitar botón y mostrar spinner
                       $(this).prop('disabled', true);
                     $('#import-all-customers-spinner').addClass('is-active');

                      $.post(ajaxUrl, {
                           action: 'clover_import_all_customers',
                           nonce: nonce
                       }, function(response) {
                           if (response.success) {
                               var successResult = '<div class="notice notice-success is-dismissible"><p>' +
                                   response.data.message +
                                   '</p></div>';
                               $('#import-all-customers-result').html(successResult);
                           } else {
                              var errorResult = '<div class="notice notice-error is-dismissible"><p>' +
                                   response.data.error +
                                   '</p></div>';
                              $('#import-all-customers-result').html(errorResult);
                           }
                       }).fail(function() {
                           var errorResult = '<div class="notice notice-error is-dismissible"><p>Error in connection. Please try again.</p></div>';
                           $('#import-all-customers-result').html(errorResult);
                       }).always(function() {
                           // Rehabilitar botón y ocultar spinner
                           $('#import-all-customers-btn').prop('disabled', false);
                           $('#import-all-customers-spinner').removeClass('is-active');
                       });
                   });

                   // Handler para importar un cliente específico
                   $('#import-single-customer-btn').click(function() {
                       var customerId = $('#customer_id_input').val().trim();

                      if (!customerId) {
                           alert('Please enter a customer ID');
                           return;
                       }

                       // Deshabilitar botón y mostrar spinner
                      $(this).prop('disabled', true);
                       $('#import-single-customer-spinner').addClass('is-active');

                       $.post(ajaxUrl, {
                          action: 'clover_import_single_customer',
                          customer_id: customerId,
                          nonce: nonce
                      }, function(response) {
                          if (response.success) {
                              var successResult = '<div class="notice notice-success is-dismissible"><p>' +
                                  response.data.message +
                                  '</p></div>';
                              $('#import-single-customer-result').html(successResult);
                          } else {
                              var errorResult = '<div class="notice notice-error is-dismissible"><p>' +
                                  response.data.error +
                                  '</p></div>';
                            $('#import-single-customer-result').html(errorResult);
                          }
                      }).fail(function() {
                          var errorResult = '<div class="notice notice-error is-dismissible"><p>Error in connection. Please try again.</p></div>';
                          $('#import-single-customer-result').html(errorResult);
                      }).always(function() {
                          // Rehabilitar botón y ocultar spinner
                          $('#import-single-customer-btn').prop('disabled', false);
                          $('#import-single-customer-spinner').removeClass('is-active');
                     });
                  });
              });
          </script>
          <?php
    }

    /**
     * Agregar botón de sincronización con Clover en el perfil de usuario
     */
    public function add_clover_sync_button($user)
    {
        $clover_id = get_user_meta($user->ID, 'clover_customer_id', true);
        ?>
           <h3>Clover Integration</h3>
           <table class="form-table">
               <tr>
                  <th><label>Clover Customer ID</label></th>
                  <td>
                      <?php if ($clover_id): ?>
                          <code><?php echo esc_html($clover_id); ?></code>
                          <p class="description">Este usuario está vinculado a Clover</p>
                      <?php else: ?>
                          <span style="color: #dc3232;">No vinculado</span>
                          <p class="description">Se creará en Clover al sincronizar</p>
                      <?php endif; ?>
                  </td>
              </tr>
              <tr>
                  <th><label>Sincronización Manual</label></th>
                  <td>
                      <button type="button" id="sync-to-clover" class="button button-primary">
                          <?php echo $clover_id ? 'Actualizar en Clover' : 'Crear en Clover'; ?>
                      </button>
                      <span class="spinner" id="clover-sync-spinner"></span>
                      <div id="clover-sync-result" style="margin-top: 10px;"></div>
                  </td>
              </tr>
          </table>

          <script type="text/javascript">
          jQuery(document).ready(function($) {
              $('#sync-to-clover').click(function() {
                  var userId = '<?php echo intval($user->ID); ?>';
                  var nonce = '<?php echo wp_create_nonce('clover_make_request'); ?>';
                  var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
                  var $btn = $(this);
                  var $spinner = $('#clover-sync-spinner');
                  var $result = $('#clover-sync-result');

                 $btn.prop('disabled', true);
                  $spinner.addClass('is-active');
                  $result.html('');

                  $.post(ajaxUrl, {
                      action: 'clover_sync_single_user_to_clover',
                      user_id: userId,
                      nonce: nonce
                  }, function(response) {
                      if (response.success) {
                          $result.html('<span style="color: #46b450;">✓ ' + response.data.message + '</span>');
                      } else {
                          $result.html('<span style="color: #dc3232;">✗ ' + response.data.error + '</span>');
                      }
                  }).fail(function() {
                      $result.html('<span style="color: #dc3232;">✗ Error de conexión</span>');
                  }).always(function() {
                      $btn.prop('disabled', false);
                      $spinner.removeClass('is-active');
                  });
              });
          });
          </script>
          <?php
    }
}
