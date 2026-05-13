<?php

namespace Src\Services;

class OrderService extends BaseService
{
    // GET /orders
    public function getOrders(array $params = []): array
    {
        return $this->get('/orders', $params);
    }

    // GET Single Order /orders/{orderId} /orders/orderId
    public function getOrder(string $orderId): array
    {
        return $this->get("/orders/{$orderId}");
    }

    // GET /orders/{orderId}/line-items
    public function getLineItems(string $orderId): array
    {
        return $this->get("/orders/{$orderId}/line_items");
    }

    // GET /items
    public function getItems(array $params = []): array
    {
        return $this->get('/items', $params);
    }

    // GET /items by category
    public function getItemsByCategory(string $idCategory = '', array $params = []): array
    {
        return $this->get("/categories/{$idCategory}/items", $params);
    }

    // Get Category
    public function getCategory(string $idCategory = '', array $params = []): array
    {
        return $this->get("/categories/{$idCategory}", $params);
    }

    // Get All Category
    public function getAllCategory(array $params = []): array
    {
        return $this->get('/categories', $params);
    }

    // Get All Modifiers
    public function getAllModifiers(array $params = []): array
    {
        return $this->get('/modifiers', $params);
    }

    // Get All Modifiers Group
    public function getAllModifiersGroup(array $params = []): array
    {
        return $this->get('/modifier_groups', $params);
    }

    // Get All Tax Rates
    public function getTaxRates(array $params = []): array
    {
        return $this->get('/tax_rates', $params);
    }

    public function createAtomicOrder(array $payload): array
    {
        return $this->post('/atomic_order/orders', $payload);
    }

    // PATCH /orders/{orderId} — update order fields (employee, note, etc.)
    public function updateOrder(string $orderId, array $payload): array
    {
        return $this->post("/orders/{$orderId}", $payload);
    }

    // Nuevo método para crear una orden básica
    public function createOrder(array $payload): array
    {
        // clover_log('CREATE ORDER PAYLOAD: ' . print_r($payload, true));
        return $this->post('/orders', $payload);
    }

    // Nuevo método para añadir items en bloque a una orden
    public function addBulkLineItems(string $orderId, array $payload): array
    {
        // clover_log('ADD BULK LINE ITEMS PAYLOAD: ' . print_r($payload, true));
        return $this->post("/orders/{$orderId}/bulk_line_items", $payload);
    }

    // Nuevo método para añadir modificaciones a un line item
    public function addModificationToLineItem(string $orderId, string $lineItemId, array $payload): array
    {
        // clover_log('ADD MODIFICATION TO LINE ITEM PAYLOAD: ' . print_r($payload, true));
        return $this->post("/orders/{$orderId}/line_items/{$lineItemId}/modifications", $payload);
    }

    // Nuevo método para enviar orden a impresora Clover
    public function printOrder(string $orderId): array
    {
        $payload = [
            'orderRef' => [
                'id' => $orderId
            ]
        ];
        clover_log('PRINT ORDER: Sending order ' . $orderId . ' to printer');
        return $this->post('/print_event', $payload);
    }

    /**
     * Create a payment record on an order to mark it as paid
     * 
     * @param string $orderId Clover order ID
     * @param string $tenderId Tender ID (e.g., Check, Cash, Credit Card)
     * @param int $amount Amount in cents (optional, defaults to order total)
     * @return array API response
     */
    public function createPaymentForOrder(string $orderId, string $tenderId, int $amount = null): array
    {
        $payload = [
            'amount' => $amount,  // Will be set from order if null
            'tender' => [
                'id' => $tenderId
            ]
        ];
        
        clover_log("CREATE PAYMENT: Order {$orderId}, Tender {$tenderId}, Amount: " . ($amount ?? 'order total'));
        
        return $this->post("/orders/{$orderId}/payments", $payload);
    }

    /**
     * Get all tenders for the merchant
     *
     * @return array List of tenders
     */
    public function getTenders(): array
    {
        return $this->get('/tenders');
    }

    /**
     * Get all employees for the merchant
     *
     * @return array List of employees
     */
    public function getEmployees(): array
    {
        return $this->get('/employees');
    }

    /**
     * Get all order types for the merchant
     *
     * @return array List of order types
     */
    public function getOrderTypes(): array
    {
        return $this->get('/order_types');
    }

    public function updateLineItem(string $orderId, string $lineItemId, array $payload): array
    {
        return $this->post("/orders/{$orderId}/line_items/{$lineItemId}", $payload);
    }

    public function addTaxRateToLineItem(string $orderId, string $lineItemId, array $payload): array
    {
        return $this->post("/orders/{$orderId}/line_items/{$lineItemId}/tax_rates", $payload);
    }

    public function getDiscounts(array $params = []): array
    {
        return $this->get('/discounts', $params);
    }

    // Public method to get raw data from any endpoint
    public function getData(string $endpoint, array $params = []): array
    {
        return $this->get($endpoint, $params);
    }
}
