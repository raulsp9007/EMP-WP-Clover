<?php

namespace Src\Services;

use Src\Core\HttpClient;

abstract class BaseService
{
    protected string $baseUrl;
    protected array $headers;

    /**
     * Get the base URL
     *
     * @return string
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Get the headers
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function __construct(array $config)
    {
        $valueId = get_option('clover_merchid');
        $valueToken = get_option('clover_token');
        $value = get_option('clover_api_base_url', 'https://api.clover.com/v444/merchants/');

        $this->baseUrl = rtrim($value, '/') .'/'
        .$valueId                         //$config['merchID']
        ;

        $this->headers = [
            'Authorization: Bearer ' .$valueToken,  // $config['tokenBearer']
            'Accept: application/json',
            'Content-Type: application/json'
        ];
    }

    protected function get(string $endpoint, array $params = []): array
    {
        if ($params) {
            // Check if the endpoint already contains query parameters
            $separator = strpos($endpoint, '?') !== false ? '&' : '?';
            $endpoint .= $separator . http_build_query($params);
        }
        clover_log(print_r('GET base '.$this->baseUrl . $endpoint ,true));

        return HttpClient::get(
            $this->baseUrl . $endpoint,
            $this->headers
        );
    }

    protected function post(string $endpoint, array $data = []): array
    {
        clover_log(print_r('POST base'.$this->baseUrl . $endpoint ,true));

        return  HttpClient::post(
            $this->baseUrl . $endpoint,
            $this->headers,
            $data
        );
    }
}
