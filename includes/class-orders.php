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
        // Check if order has already been processed to prevent duplicates
        $order = wc_get_order($order_id);
        if (!$order)
            return;

        clover_log('Order: ' . print_r($order, true));

        // Check if this order has already been successfully sent to the API - EARLY EXIT IF ALREADY SENT
        $already_sent = $order->get_meta('_clover_api_sent', true);
        if (!empty($already_sent)) {
            clover_log("WPOrders: Order {$order_id} already sent to Clover API, skipping duplicate.");
            return;
        }

        $config = require __DIR__ . '/../config/api.php';
        $orderService = new \Src\Services\OrderService($config);

        // Note: Payment status is now handled via payment records API, not order state field

        // Calcular el total de la orden basado en items y modificadores
        $totalAmount = 0;
        $lineItems = [];
        $note_lines = [];

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product)
                continue;

            // SKU o meta del producto
            $external_id = $product->get_sku();
            if (empty($external_id))
                continue;

            // Obtener el precio del producto (ya incluye modificadores si se calcularon correctamente)
            $item_total = floatval($item->get_total());  // Total real del item (con modificadores incluidos)
            $quantity = $item->get_quantity();

            clover_log('Item ID: ' . $item->get_id() . ' - Item total (with modifiers): ' . $item_total . ' - Quantity: ' . $quantity);

            // El total ya incluye los modificadores gracias a woocommerce_before_calculate_totals
            $item_total_with_addons = $item_total;
            $totalAmount += $item_total_with_addons;

            // Agregar el item a la lista de items de Clover
            $lineItems[] = [
                'item' => [
                    'id' => $external_id,
                    'price' => intval($item_total_with_addons * 100)
                ],
                'name' => $product->get_name(),
                'quantity' => $quantity
            ];
        }

        // Unir todas las líneas en una sola nota
        $customer_note = $order->get_customer_note();

        // Get customer name: Logged in user OR Billing Name for guests
        $user_id = $order->get_customer_id();
        $customer_name = '';

        if ($user_id > 0) {
            // Logged in user
            $user = get_user_by('id', $user_id);
            if ($user) {
                $customer_name = trim($user->first_name . ' ' . $user->last_name);
            }
        } else {
            // Guest user: Use billing name
            $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        }

        // Build the note with customer name
        $note = '';

        if (!empty($customer_name)) {
            $note .= $customer_name . '. ';
        }

        if (!empty($customer_note)) {
            $note .= 'Special instructions: ' . $customer_note;
        }

        $note = trim($note);

        clover_log('Order Total Amount (with modifiers): ' . $totalAmount);
        clover_log('Order Total in Cents: ' . ($totalAmount * 100));

        // Check if the logged-in user has a Clover customer ID
        $current_user_id = get_current_user_id();
        $clover_customer_id = get_user_meta($current_user_id, 'clover_customer_id', true);

        // Construir el body para crear la orden en Clover
        $employee_id = get_option('clover_employee_id', '');

        // Resolve Clover Order Type from shipping method mapping, fallback to default
        $clover_order_type_id = null;
        $shipping_methods = $order->get_shipping_methods();

        if (!empty($shipping_methods)) {
            $shipping_method = reset($shipping_methods);
            $map_key = $shipping_method->get_method_id() . ':' . $shipping_method->get_instance_id();
            $order_type_map = get_option('clover_order_type_map', array());
            if (is_array($order_type_map) && !empty($order_type_map[$map_key])) {
                $clover_order_type_id = $order_type_map[$map_key];
                clover_log("WPOrders: Order type resolved from mapping key '{$map_key}': {$clover_order_type_id}");
            }
        }

        if (empty($clover_order_type_id)) {
            $clover_order_type_id = get_option('clover_default_order_type_id', '');
            if (!empty($clover_order_type_id)) {
                clover_log("WPOrders: Order type resolved from default: {$clover_order_type_id}");
            } else {
                clover_log('WPOrders: No order type configured — orderType field will be omitted.');
            }
        }

        $body = [
            'note' => $note,
            'total' => $totalAmount * 100,  // Clover usa centavos
        ];

        if (!empty($clover_order_type_id)) {
            $body['orderType'] = ['id' => $clover_order_type_id];
        }

        // Add employee only if configured
        if (!empty($employee_id)) {
            $body['employee'] = [
                'id' => $employee_id
            ];
        }

        // Add customer reference if user has Clover customer ID
        if (!empty($clover_customer_id)) {
            // Get customer name from WordPress user data
            $user = get_user_by('ID', $current_user_id);
            $customer_data = array(
                'id' => $clover_customer_id
            );

            if ($user) {
                $customer_data['firstName'] = $user->first_name ?: $user->display_name;
                $customer_data['lastName'] = $user->last_name ?: '';
            }

            $body['customers'] = array($customer_data);
            clover_log('WPOrders: Adding customer to order: ' . json_encode($customer_data));
        } else {
            clover_log('WPOrders: No Clover customer ID found for user ' . $current_user_id);
        }

        if (empty($body)) {
            clover_log('WPOrders: No data to send, order not sent');
            return;
        }

        try {
            // Log the API request details
            clover_log('CLOVER API REQUEST - Creating Order:');
            clover_log('URL: ' . $orderService->getBaseUrl() . '/orders');
            clover_log('HEADERS: ' . print_r($orderService->getHeaders(), true));
            clover_log('BODY: ' . json_encode($body, JSON_PRETTY_PRINT));

            // Crear la orden en Clover
            $response = $orderService->createOrder($body);
            clover_log('ORDER CREATION API RESPONSE: ' . print_r($response, true));

            // Verificar si la creación fue exitosa
            if (isset($response['status']) && $response['status'] >= 200 && $response['status'] < 300 && isset($response['data']['id'])) {
                $cloverOrderId = $response['data']['id'];
                clover_log("CLOVER ORDER CREATED SUCCESSFULLY with ID: {$cloverOrderId}");

                // Ahora enviar los ítems individuales a la orden creada
                $itemsPayload = $this->prepareItemsPayload($order);

                // Log the bulk items API request details
                clover_log("CLOVER API REQUEST - Adding Bulk Line Items to Order: {$cloverOrderId}");
                clover_log('URL: ' . $orderService->getBaseUrl() . "/orders/{$cloverOrderId}/bulk_line_items");
                clover_log('HEADERS: ' . print_r($orderService->getHeaders(), true));
                clover_log('PAYLOAD: ' . json_encode($itemsPayload, JSON_PRETTY_PRINT));

                // Enviar los ítems a la orden creada
                $bulkItemsResponse = $orderService->addBulkLineItems($cloverOrderId, $itemsPayload);
                clover_log('BULK LINE ITEMS API RESPONSE: ' . print_r($bulkItemsResponse, true));

                // Verificar si la adición de ítems fue exitosa
                if (isset($bulkItemsResponse['status']) && $bulkItemsResponse['status'] >= 200 && $bulkItemsResponse['status'] < 300 && isset($bulkItemsResponse['data'])) {
                    clover_log("BULK LINE ITEMS ADDED SUCCESSFULLY to Order: {$cloverOrderId}");

                    // Procesar modificadores para cada item
                    $this->processModifiers($order, $orderService, $cloverOrderId, $bulkItemsResponse);

                    // Aplicar tax rate a cada line item si está configurado
                    $this->processTaxRates($orderService, $cloverOrderId, $bulkItemsResponse);

                    // Mark order as paid using payment record API (if enabled)
                    $auto_mark_paid = get_option('clover_auto_mark_as_paid', '1');
                    if ($auto_mark_paid === '1') {
                        $tender_id = get_option('clover_payment_tender_id', '');
                        if (!empty($tender_id)) {
                            clover_log("PAYMENT STATUS: Creating payment record for order {$cloverOrderId} with tender {$tender_id}");
                            try {
                                $order_total_cents = intval($totalAmount * 100);
                                $paymentResponse = $orderService->createPaymentForOrder($cloverOrderId, $tender_id, $order_total_cents);

                                if (isset($paymentResponse['status']) && $paymentResponse['status'] >= 200 && $paymentResponse['status'] < 300) {
                                    clover_log("PAYMENT STATUS: Order {$cloverOrderId} marked as PAID successfully");
                                    $order->add_order_note('Order marked as paid via API (Tender: ' . $tender_id . ')');
                                } else {
                                    clover_log("PAYMENT STATUS: Failed to mark order {$cloverOrderId} as paid. Status: " . ($paymentResponse['status'] ?? 'unknown'));
                                    $order->add_order_note('Failed to mark order as paid via API');
                                }
                            } catch (\Exception $paymentException) {
                                clover_log('PAYMENT STATUS ERROR: ' . $paymentException->getMessage());
                                $order->add_order_note('Payment status error: ' . $paymentException->getMessage());
                            }
                        } else {
                            clover_log("PAYMENT STATUS: No tender ID configured. Order {$cloverOrderId} will NOT be marked as paid automatically.");
                        }
                    } else {
                        clover_log("PAYMENT STATUS: Auto-mark as paid is DISABLED. Order {$cloverOrderId} will arrive as UNPAID in Clover.");
                    }

                    // Auto-print order if enabled
                    $auto_print = get_option('clover_auto_print_orders', '1');
                    if ($auto_print === '1') {
                        clover_log("AUTO-PRINT: Attempting to print order {$cloverOrderId}");
                        try {
                            $printResponse = $orderService->printOrder($cloverOrderId);
                            if (isset($printResponse['status']) && $printResponse['status'] >= 200 && $printResponse['status'] < 300) {
                                clover_log("AUTO-PRINT: Order {$cloverOrderId} sent to printer successfully");
                                $order->add_order_note('Order sent to Clover printer');
                            } else {
                                clover_log("AUTO-PRINT: Failed to print order {$cloverOrderId}. Status: " . ($printResponse['status'] ?? 'unknown'));
                                $order->add_order_note('Failed to send order to Clover printer');
                            }
                        } catch (\Exception $printException) {
                            clover_log('AUTO-PRINT ERROR: ' . $printException->getMessage());
                            $order->add_order_note('Print error: ' . $printException->getMessage());
                        }
                    }

                    // Marcar la orden como enviada para prevenir duplicados - ONLY AFTER SUCCESSFUL API CALL
                    $order->update_meta_data('_clover_api_sent', time());

                    // Guardar el ID de la orden de Clover
                    $order->update_meta_data('_clover_order_id', $cloverOrderId);

                    $order->save();

                    clover_log("WPOrders: Order {$order_id} successfully sent to Clover API with ID: {$cloverOrderId}");
                } else {
                    clover_log('WPOrders: Failed to add bulk line items to order in Clover API. Status: ' . ($bulkItemsResponse['status'] ?? 'unknown') . ' Response: ' . print_r($bulkItemsResponse['data'] ?? [], true));
                    $order->add_order_note('Failed to add line items to Clover order ' . $cloverOrderId . '. Order exists in Clover but is empty.');
                    // Do NOT mark as sent — allow retry on next status change
                }
            } else {
                clover_log('WPOrders: Failed to create order in Clover API. Status: ' . ($response['status'] ?? 'unknown'));

                // DON'T mark as sent if the main order creation failed - allow retries for genuine API failures
                // Only log the failure for monitoring
                $order->add_order_note('Failed to send order to Clover API. Will retry on next attempt.');
            }
        } catch (\Exception $e) {
            clover_log('CREATE ORDER API ERROR: ' . $e->getMessage());

            // DON'T mark as sent if there was an exception - allow retries for genuine API failures
            $order->add_order_note('API error when sending order to Clover: ' . $e->getMessage());
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

            // Unit price in cents (Clover expects unit price, not total)
            $unit_price_cents = intval(round($base_price * 100));

            // Add one entry per unit (Clover line items are per-unit)
            for ($i = 0; $i < $quantity; $i++) {
                $itemsPayload['items'][] = [
                    'item'  => ['id' => $external_id],
                    'name'  => $product->get_name(),
                    'price' => $unit_price_cents,
                ];
            }
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

                                $modifierPrice = isset($mod_data['price']) ? intval(floatval($mod_data['price']) * 100) : null;

                                $modifierPayload = [
                                    'modifier' => [
                                        'id' => $modifierId
                                    ]
                                ];

                                if ($modifierPrice !== null && $modifierPrice > 0) {
                                    $modifierPayload['modifier']['price'] = $modifierPrice;
                                    clover_log("ADDING MODIFIER PRICE (stored from checkout): {$modifierPrice} cents");
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

    /**
     * Apply configured Clover tax rate to every line item in the order
     */
    private function processTaxRates($orderService, $cloverOrderId, $bulkItemsResponse)
    {
        $tax_enabled = get_option('clover_tax_enabled', '0');
        $tax_rate_id = get_option('clover_tax_rate_id', '');

        if ($tax_enabled !== '1' || empty($tax_rate_id)) {
            clover_log("TAX RATES: Disabled or no tax rate configured — skipping.");
            return;
        }

        clover_log("TAX RATES: Applying tax rate {$tax_rate_id} to all line items for order {$cloverOrderId}");

        // Collect all line item IDs from bulk response
        $lineItemIds = [];
        if (is_array($bulkItemsResponse['data'])) {
            foreach ($bulkItemsResponse['data'] as $lineItem) {
                if (isset($lineItem['id'])) {
                    $lineItemIds[] = $lineItem['id'];
                }
            }
        }

        if (empty($lineItemIds)) {
            clover_log("TAX RATES: No line item IDs found in bulk response — cannot apply taxes.");
            return;
        }

        foreach ($lineItemIds as $lineItemId) {
            $response = $orderService->applyTaxRateToLineItem($cloverOrderId, $lineItemId, $tax_rate_id);
            clover_log("TAX RATES: Applied to line item {$lineItemId} — status: " . ($response['status'] ?? 'unknown'));
        }
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
