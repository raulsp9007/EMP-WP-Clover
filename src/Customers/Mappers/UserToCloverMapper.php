<?php

namespace Src\Customers\Mappers;

class UserToCloverMapper
{
    public function mapForCreate($user_id)
    {
        $user = get_userdata($user_id);

        return [
            'firstName' => get_user_meta($user_id, 'billing_first_name', true) ?: $user->display_name,
            'lastName'  => get_user_meta($user_id, 'billing_last_name', true) ?: '',
            'emailAddresses' => [
                'elements' => [
                    ['emailAddress' => $user->user_email]
                ]
            ],
             // Agregar dirección inicial si existe
             'addresses' => $this->buildAddressData($user_id)
        ];
    }

    public function mapForUpdate($user_id)
    {
        return [
            'firstName' => get_user_meta($user_id, 'first_name', true) ?: $user->display_name,
            'lastName'  => get_user_meta($user_id, 'last_name', true) ?: '',
            /*'addresses' => [
                [
                    'address1' => get_user_meta($user_id, 'billing_address_1', true) ?: '',
                    'city'     => get_user_meta($user_id, 'billing_city', true) ?: '',
                    'state'    => get_user_meta($user_id, 'billing_state', true) ?: '',
                    'zip'      => get_user_meta($user_id, 'billing_postcode', true) ?: '',
                    'country'  => get_user_meta($user_id, 'billing_country', true) ?: 'US',
                ]
            ],*/
            /* 'phoneNumbers' => [
                'elements' => [
                    [
                        'id' => $existing_phone_id ?? '',  // Si tienes el ID guardado
                        'phoneNumber' => get_user_meta($user_id, 'billing_phone', true) ?: ''
                    ]
                ]*/
            ];
    }

     // Nuevo método helper para construir datos de dirección
      private function buildAddressData($user_id)
      {
          $address1 = get_user_meta($user_id, 'billing_address_1', true);
          //$address2 = get_user_meta($user_id, 'billing_address_2', true);
          $city = get_user_meta($user_id, 'billing_city', true);
          $state = get_user_meta($user_id, 'billing_state', true);
          $zip = get_user_meta($user_id, 'billing_postcode', true);
          $country = get_user_meta($user_id, 'billing_country', true);
     
          // Si no hay datos de dirección, retornar vacío
          if (empty($address1) && empty($city)) {
              return [];
          }
     
          return [
              [
                 'address1' => $address1,
                 // 'address2' => $address2,
                  'city' => $city,
                  'state' => $state,
                  'zip' => $zip,
                  'country' => $country ?: 'US'
              ]
          ];
      }
}
