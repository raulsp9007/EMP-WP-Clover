<?php
if (!defined('ABSPATH'))
    exit;

class WPOrders_Integration
{
    public function __construct()
    {
        /* add_action(
             'woocommerce_checkout_order_processed',
             [$this, 'send_order_to_api'],
            10,
           3
        );*/
        // Send order to Clover API when status changes to processing/completed
        add_action('woocommerce_order_status_changed', [$this, 'send_order_to_api_on_status_change'], 10, 3);
    }

    /**
     * Wrapper to send order to API when order status changes to paid status
     */
    public function send_order_to_api_on_status_change($order_id, $old_status, $new_status)
    {
        // Only process orders that are being paid/fulfilled
        if (!in_array($new_status, ['processing', 'completed'])) {
            return;
        }

        // Call the existing method
        $this->send_order_to_api($order_id);
    }

    public function send_order_to_api($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $already_sent = $order->get_meta('_clover_api_sent', true);
        if (!empty($already_sent)) {
            clover_log("WPOrders: Order {$order_id} already sent to Clover API, skipping duplicate.");
            return;
        }

        $config       = require __DIR__ . '/../config/api.php';
        $orderService = new \Src\Services\OrderService($config);

        // Discount settings
        $disc_enabled = get_option('clover_global_discount_enabled', '0');
        $disc_percent = floatval(get_option('clover_global_discount_percent', '0'));
        $disc_on_mods = get_option('clover_global_discount_apply_modifiers', '0');

        // Tax rates to apply on every line item (ad-hoc items accept taxRates)
        $enabled_tax_ids  = get_option('clover_enabled_tax_rates', []);
        if (!is_array($enabled_tax_ids)) $enabled_tax_ids = [];
        $all_tax_rates    = json_decode(get_option('clover_tax_rates_cache', '[]'), true) ?: [];
        $line_item_taxes  = array_values(array_map(
            fn($tr) => ['id' => $tr['id']],
            array_filter($all_tax_rates, fn($tr) => in_array($tr['id'], $enabled_tax_ids))
        ));

        // Build line items — ad-hoc (no item.id) so taxRates overrides are respected by Clover
        $lineItems = [];

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;

            $external_id = $product->get_sku();
            if (empty($external_id)) continue;

            // Build inline modifications from _custom_modifier_data
            $modifications    = [];
            $custom_modifiers = null;

            foreach ($item->get_meta_data() as $meta) {
                if ($meta->key === '_custom_modifier_data' || $meta->key === 'custom_modifiers') {
                    $custom_modifiers = $meta->value;
                    break;
                }
            }

            if (is_array($custom_modifiers)) {
                foreach ($custom_modifiers as $mod_data) {
                    $modifier_clover_id = $mod_data['clover_id'] ?? ($mod_data['id'] ?? null);
                    if (!$modifier_clover_id) continue;

                    $original_price  = floatval($mod_data['original_price'] ?? ($mod_data['price'] ?? 0));
                    $effective_price = $original_price;

                    if ($disc_enabled === '1' && $disc_percent > 0 && $disc_on_mods === '1') {
                        $effective_price = $original_price * (1 - $disc_percent / 100);
                    }

                    $modifications[] = [
                        'modifier' => ['id' => $modifier_clover_id],
                        'name'     => $mod_data['name'] ?? '',
                        'amount'   => intval(round($effective_price * 100)),
                    ];
                }
            }

            // Price: use product base price only (get_subtotal includes modifier prices — send those separately via modifications[])
            $base_price = floatval(get_post_meta($product->get_id(), '_regular_price', true));
            if ($base_price <= 0) $base_price = floatval($product->get_price());

            // Apply active discount manually (price filters may not fire in backend context)
            $tab_apply = get_option('clover_discount_apply_to_orders', '0');
            $tab_pct   = floatval(get_option('clover_discount_cached_percent', '0'));
            if ($disc_enabled === '1' && $disc_percent > 0) {
                $base_price = $base_price * (1 - $disc_percent / 100);
            } elseif ($tab_apply === '1' && $tab_pct > 0) {
                $base_price = $base_price * (1 - $tab_pct / 100);
            }

            $unit_price_cents = intval(round($base_price * 100));

            $line_item = [
                'name'  => $product->get_name(),
                'price' => $unit_price_cents,
            ];

            if (!empty($line_item_taxes)) {
                $line_item['taxRates'] = $line_item_taxes;
            }

            if (!empty($modifications)) {
                $line_item['modifications'] = $modifications;
            }

            $lineItems[] = $line_item;
        }

        // Delivery fee — add as ad-hoc line item (no taxRates, no item.id)
        $shipping_total = floatval($order->get_shipping_total());
        if ($shipping_total > 0) {
            $shipping_methods_raw = $order->get_shipping_methods();
            $shipping_label       = 'Delivery Fee';
            if (!empty($shipping_methods_raw)) {
                $sm = reset($shipping_methods_raw);
                $shipping_label = $sm->get_method_title() ?: $sm->get_name() ?: 'Delivery Fee';
            }
            $lineItems[] = [
                'name'  => $shipping_label,
                'price' => intval(round($shipping_total * 100)),
                // No taxRates — delivery fee is tax-exempt
            ];
            clover_log("DELIVERY FEE: '{$shipping_label}' = \${$shipping_total}");
        }

        // Note
        $customer_note = $order->get_customer_note();
        $user_id       = $order->get_customer_id();
        $customer_name = '';

        if ($user_id > 0) {
            $user = get_user_by('id', $user_id);
            if ($user) $customer_name = trim($user->first_name . ' ' . $user->last_name);
        } else {
            $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        }

        $note = trim(
            (!empty($customer_name) ? $customer_name . '. ' : '') .
            (!empty($customer_note) ? 'Special instructions: ' . $customer_note : '')
        );

        // Order type
        $clover_order_type_id = null;
        $shipping_methods     = $order->get_shipping_methods();

        if (!empty($shipping_methods)) {
            $shipping_method  = reset($shipping_methods);
            $map_key          = $shipping_method->get_method_id() . ':' . $shipping_method->get_instance_id();
            $order_type_map   = get_option('clover_order_type_map', []);
            if (!empty($order_type_map[$map_key])) {
                $clover_order_type_id = $order_type_map[$map_key];
            }
        }

        if (empty($clover_order_type_id)) {
            $clover_order_type_id = get_option('clover_default_order_type_id', '');
        }

        // Build orderCart
        $orderCart = [
            'lineItems' => $lineItems,
            'note'      => $note,
            'total'     => intval(round(floatval($order->get_total()) * 100)),
        ];

        if (!empty($clover_order_type_id)) {
            $orderCart['orderType'] = ['id' => $clover_order_type_id];
        }

        $employee_id = get_option('clover_employee_id', '');
        if (!empty($employee_id)) {
            $orderCart['employee'] = ['id' => $employee_id];
        }

        $current_user_id    = get_current_user_id();
        $clover_customer_id = get_user_meta($current_user_id, 'clover_customer_id', true);

        if (!empty($clover_customer_id)) {
            $customer_data = ['id' => $clover_customer_id];
            $wp_user       = get_user_by('ID', $current_user_id);
            if ($wp_user) {
                $customer_data['firstName'] = $wp_user->first_name ?: $wp_user->display_name;
                $customer_data['lastName']  = $wp_user->last_name ?: '';
            }
            $orderCart['customers'] = [$customer_data];
        }

        $clover_discount_apply = get_option('clover_discount_apply_to_orders', '0');
        $clover_discount_id    = get_option('clover_discount_id', '');
        if ($clover_discount_apply === '1' && !empty($clover_discount_id)) {
            $orderCart['discounts'] = [
                ['discount' => ['id' => $clover_discount_id]],
            ];
        }


        $payload = ['orderCart' => $orderCart];

        try {
            clover_log('ATOMIC ORDER PAYLOAD: ' . json_encode($payload, JSON_PRETTY_PRINT));

            $response = $orderService->createAtomicOrder($payload);
            clover_log('ATOMIC ORDER RESPONSE: ' . print_r($response, true));

            if (isset($response['status']) && $response['status'] >= 200 && $response['status'] < 300 && isset($response['data']['id'])) {
                $cloverOrderId = $response['data']['id'];
                clover_log("ATOMIC ORDER CREATED: {$cloverOrderId}");

                // Mark as paid
                $auto_mark_paid = get_option('clover_auto_mark_as_paid', '1');
                if ($auto_mark_paid === '1') {
                    $tender_id = get_option('clover_payment_tender_id', '');
                    if (!empty($tender_id)) {
                        try {
                            $order_total_cents = intval(round(floatval($order->get_total()) * 100));
                            $paymentResponse   = $orderService->createPaymentForOrder($cloverOrderId, $tender_id, $order_total_cents);

                            if (isset($paymentResponse['status']) && $paymentResponse['status'] >= 200 && $paymentResponse['status'] < 300) {
                                clover_log("PAYMENT: Order {$cloverOrderId} marked as paid");
                                $order->add_order_note('Order marked as paid via API (Tender: ' . $tender_id . ')');
                            } else {
                                clover_log("PAYMENT FAILED for {$cloverOrderId}: " . print_r($paymentResponse['data'] ?? [], true));
                                $order->add_order_note('Failed to mark order as paid via API');
                            }
                        } catch (\Exception $e) {
                            clover_log('PAYMENT ERROR: ' . $e->getMessage());
                            $order->add_order_note('Payment error: ' . $e->getMessage());
                        }
                    } else {
                        clover_log("PAYMENT: No tender ID configured — order will be UNPAID in Clover.");
                    }
                }

                // Auto-print
                $auto_print = get_option('clover_auto_print_orders', '1');
                if ($auto_print === '1') {
                    try {
                        $printResponse = $orderService->printOrder($cloverOrderId);
                        if (isset($printResponse['status']) && $printResponse['status'] >= 200 && $printResponse['status'] < 300) {
                            clover_log("PRINT: Order {$cloverOrderId} sent to printer");
                            $order->add_order_note('Order sent to Clover printer');
                        } else {
                            clover_log("PRINT FAILED for {$cloverOrderId}: status " . ($printResponse['status'] ?? 'unknown'));
                        }
                    } catch (\Exception $e) {
                        clover_log('PRINT ERROR: ' . $e->getMessage());
                    }
                }

                $order->update_meta_data('_clover_api_sent', time());
                $order->update_meta_data('_clover_order_id', $cloverOrderId);
                $order->save();

                clover_log("WPOrders: Order {$order_id} sent to Clover as {$cloverOrderId}");

            } else {
                clover_log('ATOMIC ORDER FAILED: status=' . ($response['status'] ?? 'unknown') . ' data=' . print_r($response['data'] ?? [], true));
                $order->add_order_note('Failed to send order to Clover. Check logs.');
            }
        } catch (\Exception $e) {
            clover_log('ATOMIC ORDER ERROR: ' . $e->getMessage());
            $order->add_order_note('API error: ' . $e->getMessage());
        }
    }

    /**
     * Prepara el payload para enviar los items en bloque
     */
    private function prepareItemsPayload($order)
    {
        $itemsPayload = ['items' => []];

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product)
                continue;

            // SKU o meta del producto
            $external_id = $product->get_sku();
            if (empty($external_id))
                continue;

            // Base price only (no modifiers) — modifiers are sent via addModificationToLineItem
            $quantity   = $item->get_quantity();
            $base_price = floatval(get_post_meta($product->get_id(), '_regular_price', true));

            // Fallback if regular price is empty
            if ($base_price <= 0) {
                $base_price = floatval($product->get_price());
            }

            // Mirror the global discount applied in update_cart_item_price
            $discount_enabled = get_option('clover_global_discount_enabled', '0');
            $discount_percent = floatval(get_option('clover_global_discount_percent', '0'));
            if ($discount_enabled === '1' && $discount_percent > 0) {
                $base_price = $base_price * (1 - $discount_percent / 100);
            }

            $item_price_cents = intval(round($base_price * $quantity * 100));

            // Agregar el item al payload
            $itemsPayload['items'][] = [
                'item' => [
                    'id' => $external_id,
                ],
                'name' => $product->get_name(),
                'price' => $item_price_cents
            ];
        }

        return $itemsPayload;
    }

    /**
     * Procesa los modificadores para cada item de la orden
     */
    private function processModifiers($order, $orderService, $cloverOrderId, $bulkItemsResponse)
    {
        clover_log("PROCESSING MODIFIERS for Order: {$cloverOrderId}");

        // Build a SKU->lineItemId map so modifiers are assigned to the correct product
        // regardless of the order Clover returns line items in the bulk response.
        $lineItemIds = $this->extractLineItemIds($bulkItemsResponse);
        clover_log('LINE ITEM MAP extracted: ' . print_r($lineItemIds, true));

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product)
                continue;

            // SKU = Clover item ID
            $external_id = $product->get_sku();
            if (empty($external_id))
                continue;

            clover_log("Processing modifiers for item: {$external_id}, quantity: {$item->get_quantity()}");

            // Obtener los metadatos del producto
            $item_meta = $item->get_formatted_meta_data();
            clover_log('Item formatted meta data: ' . print_r($item_meta, true));

            // Tambien obtener los metadatos sin formato para capturar nuestros modificadores personalizados
            $raw_meta_data = $item->get_meta_data();
            clover_log('Item raw meta data: ' . print_r($raw_meta_data, true));

            // Verificar si existen nuestros modificadores personalizados en los metadatos sin formato
            $custom_modifiers = null;
            foreach ($raw_meta_data as $meta) {
                if ($meta->key === '_custom_modifier_data' || $meta->key === 'custom_modifiers') {
                    $custom_modifiers = $meta->value;
                    clover_log('Found custom modifier data in raw meta: ' . print_r($custom_modifiers, true));
                    break;
                }
            }

            // Look up line item ID by SKU — deterministic, order-independent match
            if (isset($lineItemIds[$external_id])) {
                $lineItemId = $lineItemIds[$external_id];
                clover_log("Processing line item ID: {$lineItemId} for product {$external_id}");

                // Procesar los modificadores personalizados si existen
                if ($custom_modifiers) {
                    clover_log('Processing custom modifiers: ' . print_r($custom_modifiers, true));

                    // Si custom_modifiers es un array de modificadores
                    if (is_array($custom_modifiers)) {
                        foreach ($custom_modifiers as $mod_data) {
                            $modifierId = null;

                            // Buscar el ID del modificador en diferentes posibles ubicaciones
                            if (isset($mod_data['clover_id'])) {
                                $modifierId = $mod_data['clover_id'];
                            } elseif (isset($mod_data['id'])) {
                                $modifierId = $mod_data['id'];
                            }

                            if ($modifierId) {
                                clover_log("PROCESSING CUSTOM MODIFIER ID: {$modifierId} for line item {$lineItemId}");

                                // Always derive effective price from original_price + current discount settings
                                // to avoid sending original price when a discount is active
                                $original_price = isset($mod_data['original_price'])
                                    ? floatval($mod_data['original_price'])
                                    : (isset($mod_data['price']) ? floatval($mod_data['price']) : 0);

                                $effective_price = $original_price;
                                $disc_enabled  = get_option('clover_global_discount_enabled', '0');
                                $disc_percent  = floatval(get_option('clover_global_discount_percent', '0'));
                                $disc_on_mods  = get_option('clover_global_discount_apply_modifiers', '0');

                                clover_log("MODIFIER DISCOUNT DEBUG — original_price: {$original_price} | disc_enabled: '{$disc_enabled}' | disc_percent: {$disc_percent} | disc_on_mods: '{$disc_on_mods}'");

                                if ($disc_enabled === '1' && $disc_percent > 0 && $disc_on_mods === '1') {
                                    $effective_price = $original_price * (1 - $disc_percent / 100);
                                    clover_log("MODIFIER DISCOUNT APPLIED — effective_price: {$effective_price}");
                                } else {
                                    clover_log("MODIFIER DISCOUNT NOT APPLIED — condition failed: disc_enabled={$disc_enabled}, disc_percent={$disc_percent}, disc_on_mods={$disc_on_mods}");
                                }

                                $modifierPrice = $original_price > 0 ? intval(round($effective_price * 100)) : null;

                                $modifierPayload = [
                                    'modifier' => [
                                        'id' => $modifierId
                                    ]
                                ];

                                if ($modifierPrice !== null && $modifierPrice > 0) {
                                    $modifierPayload['amount'] = $modifierPrice;
                                    clover_log("ADDING MODIFIER PRICE via amount field: {$modifierPrice} cents (original: " . intval($original_price * 100) . " cents)");
                                }

                                // Log the modifier API request details
                                clover_log('CLOVER API REQUEST - Adding Modification to Line Item:');
                                clover_log('URL: ' . $orderService->getBaseUrl() . "/orders/{$cloverOrderId}/line_items/{$lineItemId}/modifications");
                                clover_log('HEADERS: ' . print_r($orderService->getHeaders(), true));
                                clover_log('PAYLOAD: ' . json_encode($modifierPayload, JSON_PRETTY_PRINT));

                                // Enviar el modificador al line item
                                $modifierResponse = $orderService->addModificationToLineItem($cloverOrderId, $lineItemId, $modifierPayload);
                                clover_log('MODIFIER API RESPONSE: ' . print_r($modifierResponse, true));
                            } else {
                                clover_log('NO VALID MODIFIER ID FOUND in custom modifier data: ' . print_r($mod_data, true));
                            }
                        }
                    } else {
                        clover_log('Custom modifiers data is not an array: ' . print_r($custom_modifiers, true));
                    }
                }

                // Procesar tambien los metadatos formateados por si acaso
                foreach ($item_meta as $meta) {
                    clover_log("Checking formatted meta: key='{$meta->key}', value='{$meta->value}', display_value='{$meta->display_value}'");

                    // Filtrar los metadatos relacionados con nuestros modificadores personalizados
                    // Solo buscar nuestras claves personalizadas
                    $is_modifier_meta = false;

                    if (strpos($meta->key, 'custom_modifiers') !== false) {
                        $is_modifier_meta = true;
                        clover_log("Found custom modifier meta: key='{$meta->key}'");
                    } elseif (strpos($meta->key, '_custom_modifier') !== false) {
                        $is_modifier_meta = true;
                        clover_log("Found custom modifier data meta: key='{$meta->key}'");
                    } elseif (strpos($meta->key, 'modifier') !== false) {
                        $is_modifier_meta = true;
                        clover_log("Found potential modifier meta: key='{$meta->key}'");
                    }

                    if ($is_modifier_meta) {
                        // Extraer el ID del modificador del valor del meta
                        $modifierId = $this->extractModifierIdFromMeta($meta);

                        if ($modifierId) {
                            clover_log("EXTRACTED MODIFIER ID: {$modifierId} for line item {$lineItemId}");

                            $modifierPrice = null;
                            $modifier = $this->get_modifier_by_name_or_value($meta->value);
                            if ($modifier && isset($modifier['price'])) {
                                $modifierPrice = intval(floatval($modifier['price']) * 100);
                            }

                            $modifierPayload = [
                                'modifier' => [
                                    'id' => $modifierId
                                ]
                            ];

                            if ($modifierPrice !== null && $modifierPrice > 0) {
                                $modifierPayload['modifier']['price'] = $modifierPrice;
                                clover_log("ADDING MODIFIER PRICE (lookup fallback): {$modifierPrice} cents");
                            }

                            // Log the modifier API request details
                            clover_log('CLOVER API REQUEST - Adding Modification to Line Item:');
                            clover_log('URL: ' . $orderService->getBaseUrl() . "/orders/{$cloverOrderId}/line_items/{$lineItemId}/modifications");
                            clover_log('HEADERS: ' . print_r($orderService->getHeaders(), true));
                            clover_log('PAYLOAD: ' . json_encode($modifierPayload, JSON_PRETTY_PRINT));

                            // Enviar el modificador al line item
                            $modifierResponse = $orderService->addModificationToLineItem($cloverOrderId, $lineItemId, $modifierPayload);
                            clover_log('MODIFIER API RESPONSE: ' . print_r($modifierResponse, true));
                        } else {
                            clover_log("NO MODIFIER ID EXTRACTED for meta key: {$meta->key}");
                        }
                    }
                }
            } else {
                clover_log("No line item ID found in map for SKU: {$external_id}");
            }
        }
    }

    /**
     * Builds a map of [clover_item_id => line_item_id] from the bulk_line_items response.
     * Keying by SKU instead of position ensures modifiers are assigned to the correct
     * product regardless of the order Clover returns line items.
     */
    private function extractLineItemIds($bulkItemsResponse)
    {
        clover_log('EXTRACTING LINE ITEM IDS from bulk response: ' . print_r($bulkItemsResponse, true));

        $lineItemMap = [];
        if (is_array($bulkItemsResponse['data'])) {
            clover_log('Found data in bulk response: ' . print_r($bulkItemsResponse['data'], true));

            foreach ($bulkItemsResponse['data'] as $lineItem) {
                if (isset($lineItem['id']) && isset($lineItem['item']['id'])) {
                    $cloverItemId = $lineItem['item']['id'];
                    $lineItemMap[$cloverItemId] = $lineItem['id'];
                    clover_log("Mapped clover item {$cloverItemId} => line item {$lineItem['id']}");
                } else {
                    clover_log('Line item missing id or item.id: ' . print_r($lineItem, true));
                }
            }
        } else {
            clover_log('No valid data found in bulk response. Response: ' . print_r($bulkItemsResponse, true));
        }
        clover_log('FINAL lineItemMap: ' . print_r($lineItemMap, true));
        return $lineItemMap;
    }

    // Método para extraer el ID del modificador del meta
    private function extractModifierIdFromMeta($meta)
    {
        // Esta función intenta extraer el ID del modificador del objeto meta
        // La implementación dependerá de cómo se almacenen los IDs de modificadores en tu sistema

        // Buscar en el valor principal del meta
        $value = $meta->value;

        // Si es un array complejo, intentar encontrar el ID
        if (is_array($value) && isset($value['id'])) {
            return $value['id'];
        }

        // Si es un string que contiene el ID en algún formato
        if (is_string($value)) {
            // Buscar patrones comunes donde podría estar el ID
            if (preg_match('/id["\']?\s*[:=]\s*["\']?([A-Z0-9]+)/i', $value, $matches)) {
                return $matches[1];
            }

            // Buscar patrones específicos para IDs de Clover (usualmente 13 caracteres alfanuméricos)
            if (preg_match('/([A-Z0-9]{13})/', $value, $matches)) {
                return $matches[1];
            }
        }

        // Buscar en el display_value si existe
        if (property_exists($meta, 'display_value')) {
            $displayValue = $meta->display_value;
            if (preg_match('/([A-Z0-9]{13})/', $displayValue, $matches)) {
                return $matches[1];
            }
        }

        // Buscar en el nombre/key del meta por si el ID está allí
        $key = $meta->key;
        if (preg_match('/[A-Z0-9]{13}/', $key, $matches)) {  // Patrón típico de ID de Clover
            return $matches[0];
        }

        // Buscar en el nombre del producto o descripción si está disponible
        // Esto cubre el caso donde el ID está en la descripción del addon de YITH
        if (property_exists($meta, 'display_key')) {
            $displayKey = $meta->display_key;
            if (preg_match('/([A-Z0-9]{13})/', $displayKey, $matches)) {
                return $matches[1];
            }
        }

        // Buscar en el display_value buscando un formato específico como "Clover ID: XXXXXXXXXXXXX"
        if (property_exists($meta, 'display_value')) {
            $displayValue = $meta->display_value;
            // Buscar patrones como "Clover ID: XXXXXXXXXXXXX" o "ID: XXXXXXXXXXXXX"
            if (preg_match('/(?:Clover\s*ID|ID)[:\s]+([A-Z0-9]{13})/i', $displayValue, $matches)) {
                return $matches[1];
            }
            // Buscar cualquier secuencia de 13 caracteres alfanuméricos en el display_value
            if (preg_match('/([A-Z0-9]{13})/', $displayValue, $matches)) {
                return $matches[1];
            }
        }

        // NEW: Check if this is a custom modifier from our custom system
        if (property_exists($meta, 'display_value')) {
            $displayValue = $meta->display_value;

            // If the display value contains a known modifier name, look up its Clover ID
            $modifier = get_modifier_by_name_or_value($displayValue);
            if ($modifier && isset($modifier['clover_id'])) {
                return $modifier['clover_id'];
            }
        }

        // NEW: Check if the value itself corresponds to a known modifier
        if (is_string($value)) {
            $modifier = get_modifier_by_name_or_value($value);
            if ($modifier && isset($modifier['clover_id'])) {
                return $modifier['clover_id'];
            }
        }

        // Por defecto, devolver null si no se puede extraer
        return null;
    }

    /**
     * Helper function to get modifier by name or value
     */
    private function get_modifier_by_name_or_value($search_value)
    {
        // This function would search through all products to find a modifier with the given name or value
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
                        // Check if the search value matches the modifier name or any other identifying field
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
                    }
                }
            }
        }

        return null;
    }
}

new WPOrders_Integration();

/**
 * Clover order status meta box + resend button
 */
class Clover_Order_Metabox
{
    public function __construct()
    {
        add_action('add_meta_boxes', [$this, 'register_meta_box']);
        add_action('wp_ajax_clover_resend_order', [$this, 'handle_resend']);
    }

    public function register_meta_box()
    {
        $screens = ['shop_order', 'woocommerce_page_wc-orders'];
        foreach ($screens as $screen) {
            add_meta_box(
                'clover_order_status',
                'Clover',
                [$this, 'render_meta_box'],
                $screen,
                'side',
                'high'
            );
        }
    }

    public function render_meta_box($post_or_order)
    {
        $order_id = is_a($post_or_order, 'WP_Post')
            ? $post_or_order->ID
            : $post_or_order->get_id();

        $order        = wc_get_order($order_id);
        $clover_id    = $order->get_meta('_clover_order_id', true);
        $sent_at      = $order->get_meta('_clover_api_sent', true);
        $nonce        = wp_create_nonce('clover_resend_order_' . $order_id);

        echo '<p style="margin:0 0 8px">';
        if ($clover_id) {
            echo '<strong>Clover ID:</strong> ' . esc_html($clover_id) . '<br>';
            echo '<strong>Sent:</strong> ' . ($sent_at ? date('Y-m-d H:i', $sent_at) : '—');
        } else {
            echo '<em>Not sent yet</em>';
        }
        echo '</p>';

        echo '<button type="button" id="clover-resend-btn" class="button button-secondary" style="width:100%"
            data-order-id="' . esc_attr($order_id) . '"
            data-nonce="' . esc_attr($nonce) . '">
            Resend to Clover
        </button>';
        echo '<span id="clover-resend-msg" style="display:block;margin-top:6px;font-size:12px"></span>';

        ?>
        <script>
        (function($){
            $('#clover-resend-btn').on('click', function(){
                var btn = $(this);
                var msg = $('#clover-resend-msg');
                btn.prop('disabled', true).text('Sending...');
                msg.text('').css('color','');
                $.post(ajaxurl, {
                    action:   'clover_resend_order',
                    order_id: btn.data('order-id'),
                    nonce:    btn.data('nonce')
                }, function(res){
                    if (res.success) {
                        msg.css('color','green').text(res.data.message);
                        btn.text('Resend to Clover');
                    } else {
                        msg.css('color','red').text(res.data.error || 'Error');
                        btn.text('Resend to Clover');
                    }
                    btn.prop('disabled', false);
                }).fail(function(){
                    msg.css('color','red').text('Request failed');
                    btn.prop('disabled', false).text('Resend to Clover');
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    public function handle_resend()
    {
        $order_id = intval($_POST['order_id'] ?? 0);

        if (!$order_id || !wp_verify_nonce($_POST['nonce'] ?? '', 'clover_resend_order_' . $order_id)) {
            wp_send_json_error(['error' => 'Security check failed']);
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['error' => 'Insufficient permissions']);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['error' => 'Order not found']);
        }

        // Clear Clover metas so send_order_to_api runs fresh
        $order->delete_meta_data('_clover_api_sent');
        $order->delete_meta_data('_clover_order_id');
        $order->save();

        $integration = new WPOrders_Integration();
        $integration->send_order_to_api($order_id);

        // Refresh order to read updated metas
        $order    = wc_get_order($order_id);
        $sent_at  = $order->get_meta('_clover_api_sent', true);
        $clover_id = $order->get_meta('_clover_order_id', true);

        if ($sent_at && $clover_id) {
            wp_send_json_success(['message' => 'Sent. Clover ID: ' . $clover_id]);
        } else {
            wp_send_json_error(['error' => 'Send failed — check plugin logs']);
        }
    }
}

new Clover_Order_Metabox();
