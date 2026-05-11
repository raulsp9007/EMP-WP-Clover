<?php
/**
 * Clover HTTP Client Class
 * Handles HTTP requests to the Clover API
 */

if (!defined('ABSPATH')) {
    exit;
}

class Clover_HttpClient {
    
    /**
     * Make an HTTP request to the Clover API
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string $endpoint API endpoint
     * @param array $data Request body data for POST/PUT requests
     * @return array|WP_Error Response from the API
     */
    public function request($method, $endpoint, $data = array()) {
        // Get API credentials from options
        $merchid = get_option('clover_merchid');
        $token = get_option('clover_token');
        $base_url = get_option('clover_api_base_url', 'https://api.clover.com/v3/merchants/');

        // Validate credentials
        if (empty($merchid) || empty($token)) {
            return new WP_Error('missing_credentials', 'Merchid and Token are required.');
        }

        // Check if the endpoint already contains merchants/{merchant_id} or similar pattern
        // If not, prepend the merchant path
        $normalizedEndpoint = ltrim($endpoint, '/');
        if (!preg_match('/^merchants\/(?:\{merchant_id\}|[^\/]+)\//', $normalizedEndpoint)) {
            // Prepend merchant path if not already present
            $normalizedEndpoint = 'merchants/' . $merchid . '/' . $normalizedEndpoint;
        }

        // Construct the full URL
        $url = rtrim($base_url, '/') . '/' . $normalizedEndpoint;

        // Replace placeholder in URL if present
        $url = str_replace('{merchant_id}', $merchid, $url);

        // Prepare headers
        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        );

        // Prepare request arguments
        $args = array(
            'method' => strtoupper($method),
            'headers' => $headers,
            'timeout' => 30,
            'httpversion' => '1.1',
        );

        // Add body for methods that support it
        if (in_array(strtoupper($method), array('POST', 'PUT', 'PATCH')) && !empty($data)) {
            $args['body'] = json_encode($data);
        }

        // Make the request using WordPress HTTP API
        $response = wp_remote_request($url, $args);

        return $response;
    }
    
    /**
     * Make a GET request to the Clover API
     *
     * @param string $endpoint API endpoint
     * @return array|WP_Error Response from the API
     */
    public function get($endpoint) {
        return $this->request('GET', $endpoint);
    }
    
    /**
     * Make a POST request to the Clover API
     *
     * @param string $endpoint API endpoint
     * @param array $data Request body data
     * @return array|WP_Error Response from the API
     */
    public function post($endpoint, $data = array()) {
        return $this->request('POST', $endpoint, $data);
    }
    
    /**
     * Make a PUT request to the Clover API
     *
     * @param string $endpoint API endpoint
     * @param array $data Request body data
     * @return array|WP_Error Response from the API
     */
    public function put($endpoint, $data = array()) {
        return $this->request('PUT', $endpoint, $data);
    }
    
    /**
     * Make a DELETE request to the Clover API
     *
     * @param string $endpoint API endpoint
     * @return array|WP_Error Response from the API
     */
    public function delete($endpoint) {
        return $this->request('DELETE', $endpoint);
    }
}