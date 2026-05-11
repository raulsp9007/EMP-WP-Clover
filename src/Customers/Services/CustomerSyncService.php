<?php

namespace Src\Customers\Services;

class CustomerSyncService
{
    public static function handleCreate($user_id)
    {
        if (get_option('_clover_is_importing')) {
            return;
        }

        $existing = get_user_meta($user_id, 'clover_customer_id', true);

        if ($existing) {
            return;
        }

        $user = get_userdata($user_id);
        if (!$user || empty($user->user_email)) {
            return;
        }

        $mapper = new \Src\Customers\Mappers\UserToCloverMapper();
        $customer_data = $mapper->mapForCreate($user_id);

        $service = self::getCustomerService();
        $response = $service->createCustomer($customer_data);



        if (isset($response['data']['id'])) {
            $customer_id = $response['data']['id'];
            update_user_meta($user_id, 'clover_customer_id', $response['data']['id']);
             // Agregar teléfono si existe y guardar el ID
             $phone = get_user_meta($user_id, 'billing_phone', true);
              if (!empty($phone)) {
                $phoneResponse = $service->addCustomerPhone($customer_id, $phone);
                 if (isset($phoneResponse['data']['id'])) {
                    update_user_meta($user_id, 'clover_phone_id', $phoneResponse['data']['id']);
                 }
              }
              // NUEVO: Guardar ID de la dirección si existe
              $address1 = get_user_meta($user_id, 'billing_address_1', true);
              if (!empty($address1)){
                // Obtener la dirección creada (la primera por defecto)
                $addresses = $service->getAddresses($customer_id);
                if ($addresses && !empty($addresses->getId())) {
                       update_user_meta($user_id, 'clover_address_id', $addresses->getId());
                   }
              } 
        }
    }

    public static function handleUpdate($user_id)
    {
        if (get_option('_clover_is_importing')) {
            return;
        }

        /*$existing = get_user_meta($user_id, 'clover_customer_id', true);

        if ($existing) {
            return;
        }*/


        $clover_customer_id = get_user_meta($user_id, 'clover_customer_id', true);

        if (!$clover_customer_id) {
            self::handleCreate($user_id);
            return;
        }

        $mapper = new \Src\Customers\Mappers\UserToCloverMapper();
        $customer_data = $mapper->mapForUpdate($user_id);

        $service = self::getCustomerService();
        $service->updateCustomer($clover_customer_id, $customer_data);

        // Actualizar teléfono por separado usando el ID guardado
        $phone = get_user_meta($user_id, 'billing_phone', true);
        $phone_id = get_user_meta($user_id, 'clover_phone_id', true);

         if (!empty($phone)){
             if ($phone_id){
                // Actualizar teléfono existente
                 $service->updateCustomerPhone($clover_customer_id, $phone_id, $phone);
             }else{
                // Agregar nuevo teléfono y guardar el ID
                $phoneResponse = $service->addCustomerPhone($clover_customer_id, $phone);
                 if (isset($phoneResponse['data']['id'])) {
                      update_user_meta($user_id, 'clover_phone_id', $phoneResponse['data']['id']);
                  }
             }
         }

         // NUEVO: Actualizar dirección con ID guardado
          $address1 = get_user_meta($user_id, 'billing_address_1', true);
           $address_id = get_user_meta($user_id, 'clover_address_id', true);
            if (!empty($address1)) {
               if ($address_id) {
                   // Actualizar dirección existente
                   $service->updateCustomerAddress($clover_customer_id, $address_id, $user_id);
               } else {
                  // Crear nueva dirección y guardar el ID
                   $addressResponse = $service->addCustomerAddress($clover_customer_id, $user_id);
                   if (isset($addressResponse['data']['id'])) {
                       update_user_meta($user_id, 'clover_address_id', $addressResponse['data']['id']);
                   }
               }
          }
    }

    private static function getCustomerService()
    {
        $config = require CLOVER_PLUGIN_PATH . 'config/api.php';
        return new \Src\Services\CustomerService($config);
    }
}
