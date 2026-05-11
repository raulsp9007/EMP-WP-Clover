<?php

namespace Src\Core;

class HttpClient
{
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        array $body = []
    ): array {
        $ch = curl_init($url);

        $defaultHeaders = [
            'Content-Type: application/json'
        ];

        $headers = array_merge($defaultHeaders, $headers);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 45, // Increased timeout to 45 seconds
            CURLOPT_CONNECTTIMEOUT => 15, // Added connection timeout
        ]);

        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            throw new \Exception(curl_error($ch));
        }

        curl_close($ch);

        return [
            'status' => $status,
            'data' => json_decode($response, true)
        ];
    }

    public static function get(string $url, array $headers = []): array
    {
        clover_log('GET');
        return self::request('GET', $url, $headers);
    }

    public static function post(string $url, array $headers = [], array $data = []): array
    {
        clover_log('POST http '.$url);
        return self::request(
            'POST',
            $url,
            $headers,
            $data
        );
    }



}
