<?php

namespace Src\Cron;

class CustomerSync
{
    public static function run(): void
    {
        clover_log('CRON: Iniciando sincronización de clientes');

        // Ejecuta tu función de importación existente
        // Opcional: pasar timestamp del último sync
        //$lastSync = get_option('clover_last_sync_time', 0);
        clover_import_all_customers_logic();

        // Guardar timestamp del sync actual
        //update_option('clover_last_sync_time', time());

        clover_log('CRON: Sincronización de clientes finalizada');
    }
}
