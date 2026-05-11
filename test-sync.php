<?php
// test-sync.php
// Archivo para probar sincronización de clientes

// Cargar WordPress
require_once __DIR__ . '/../../../wp-load.php'; // Ajusta según tu ruta

// Registrar errores para verlos en pantalla
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Incluir tu clase CustomerSync si no se carga automáticamente
require_once __DIR__ . '/src/Cron/CustomerSync.php';

echo "<pre>Iniciando prueba de sincronización...\n";

// Ejecutar la sincronización
try {
    \Src\Cron\CustomerSync::run();
    echo "Sincronización ejecutada. Revisa los logs.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "Prueba finalizada.</pre>";
