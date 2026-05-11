<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

spl_autoload_register(function ($class) {
    $path = __DIR__ . '/../' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

$config = require __DIR__ . '/../config/api.php';

$orderService = new \Src\Services\OrderService($config);

// Todas las órdenes
//$orders = $orderService->getOrders();

// Orden puntual
$orders = $orderService->getOrder('89XG2VABXTSFY');

// Line items per orders
//$orders = $orderService->getLineItems('89XG2VABXTSFY');

//POST
/*
    $payload = [
    'orderCart' => [
        'orderType' => [
            'taxable'          => false,
            'isDefault'        => false,
            'filterCategories' => false,
            'isHidden'         => false,
            'isDeleted'        => false
        ],
        'groupLineItems' => false
    ]
];
    $response = $orderService->createAtomicOrder($payload);

    var_dump($response);

*/ 

echo '<pre>';
var_dump($orders);
