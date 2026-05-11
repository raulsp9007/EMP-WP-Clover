<?php

namespace Src\Customers\Hooks;

use Src\Customers\Services\CustomerSyncService;

class CustomerHooks
{
    public static function register()
    {
        add_action('user_register', [self::class, 'onUserRegister'], 10, 1);
        add_action('profile_update', [self::class, 'onProfileUpdate'], 10, 2);
    }

    public static function onUserRegister($user_id)
    {
        clover_log('HOOK user_register ejecutado: ' . $user_id);
        CustomerSyncService::handleCreate($user_id);
    }

    public static function onProfileUpdate($user_id, $old_user_data)
    {
        clover_log('HOOK user_Update ejecutado: ' . $user_id);

        CustomerSyncService::handleUpdate($user_id);
    }
}
