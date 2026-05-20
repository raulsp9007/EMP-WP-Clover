<?php

namespace Src\Core;

require_once __DIR__ . '/../Cron/CustomerSync.php';

class Plugin
{
    private static $initialized = false;

    public static function init()
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        // Registrar el CRON
        add_action('init', [self::class, 'registerCustomerSyncCron']);
        add_action('clover_daily_customer_sync', [self::class, 'executeCustomerSync']);
    }

    /**
     * Registrar el CRON de sincronización diaria (24 horas)
     */
    public static function registerCustomerSyncCron(): void
    {
        if (!wp_next_scheduled('clover_daily_customer_sync')) {
            // daily = cada 24 horas
            wp_schedule_event(time(), 'daily', 'clover_daily_customer_sync');
            clover_log('CRON registrado: clover_daily_customer_sync (cada 24h)');
        }
    }

    /**
     * Ejecutar la sincronización de clientes
     */
    public static function executeCustomerSync(): void
    {
        clover_log('CRON ejecutando: CustomerSync');
        \Src\Cron\CustomerSync::run();
    }
}
