<?php

namespace Src\Services;

use Src\Domain\Address;
use Src\Domain\Customer;
use Src\Domain\Phone;

class CustomerService extends BaseService
{
    public function getCustomers(array $params = []): array
     {
        clover_log('GET ALL customers');
        return  $this->get('/customers', $params);
     }
      
       // Obtener cliente específico
       public function getCustomer(string $customerId, array $params = []): array
       {
        clover_log('GET One customer');
         return $this->get("/customers/{$customerId}",$params);
      }

      public function getCustomerPhone(string $customerId): string
       {
        clover_log('GET Phone customer');
        $response = $this->getCustomer($customerId,['expand'=>'phoneNumbers']);
        $data = $response["data"];

        try {
        if (!empty($data["phoneNumbers"]["elements"][0]['phoneNumber'])) {
            return $data["phoneNumbers"]["elements"][0]['phoneNumber'];
        }
        } catch (\Exception $e) {
            clover_log('Error al obtener email: ' . $e->getMessage());
        }
        return '';

      }

        public function getCustomerEmail(string $customerId): string
       {
        clover_log('GET Email customer');
        $response = $this->getCustomer($customerId,['expand'=>'emailAddresses']);
        $data = $response["data"] ;
         try {
        if (!empty($data["emailAddresses"]["elements"][0]['emailAddress'])) {
            return $data["emailAddresses"]["elements"][0]['emailAddress'];
        }
        } catch (\Exception $e) {
            clover_log('Error al obtener email: ' . $e->getMessage());
        }
        return '';
      }

      public function getAddresses(string $customerId): Address{
        clover_log('GET Address customer');
        $response = $this->getCustomer($customerId,['expand'=>'addresses']);

       $elements = $response["data"]["addresses"]["elements"] ?? [];

    if (empty($elements)) {
        // Retornar un objeto Address vacío si no hay datos
        return new Address('', '', '', '', '', '', '', '');
    }

    // Crear Address desde el primer elemento disponible
    return Address::fromArray($elements[0]);
     } 
    

     public function getAllCustomers(): array
     {
            $response = $this->get("/customers", [
                'expand' => 'addresses,emailAddresses,phoneNumbers',
                'limit'  => 1000
            ]);
            $customers = [];

            if (!empty($response['data']['elements'])) {
                foreach ($response['data']['elements'] as $customerData) {
                    $customers[] = Customer::fromArray($customerData);
                }
            }
            return $customers;
    }

      public function createCustomer(array $data): array                                                  
      {                                                                                                   
        clover_log('CREATE customer: ' . ($data['email'] ?? $data['firstName'] ?? 'unknown'));           
        return $this->post('/customers', $data);                                                        
      }

    public function getCustomersWithEmail(): array
        {
            $customers = $this->getAllCustomers(); // ya devuelve Customer[]
            clover_log('/customers   '.count($customers));

            return array_filter(
                $customers,
                fn(Customer $c) => $c->hasEmail() //&&$c->hasPhone() 
            );
        }

     public function updateCustomer(string $customerId, array $data): array                              
       {   
           clover_log('[Clover] UPDATE customer: '); 
           clover_log(print_r($data,true)); 

           clover_log('[Clover] UPDATE customer: ' . $customerId);                                        
           return $this->post("/customers/{$customerId}", $data);                                         
       }

                                                                                                
     
       public function getCustomerByEmail(string $email): array                                           
       {                                                                                                 
          clover_log('[Clover] GET customer by email: ' . $email);                                        
          $response = $this->get('/customers', [                                                                      
	         'email' => $email,                                                                          
             'limit' => 1                                                                                
           ]);                                                                                             
                                                                                                         
          if (!empty($response['data']['elements'])) {                                                    
              return $response['data']['elements'][0];                                                    
          }                                                                                              
                                                                                                          
           return [];                                                                                    
       }
        public function getPhones(string $customerId): array
       {
           clover_log('GET Phones customer');
           $response = $this->getCustomer($customerId, ['expand' => 'phoneNumbers']);
           $elements = $response["data"]["phoneNumbers"]["elements"] ?? [];
      
           $phones = [];
          foreach ($elements as $phoneData) {
               $phones[] = \Src\Domain\Phone::fromArray($phoneData);
          }
     
          return $phones;
      }

       public function addCustomerPhone(string $customerId, string $phoneNumber): array
       {
           clover_log('[Clover] ADD customer phone: ' . $phoneNumber);
      
           $data = [
               'phoneNumber' => $phoneNumber
           ];
      
          return $this->post("/customers/{$customerId}/phone_numbers", $data);
      }
     
      public function updateCustomerPhone(string $customerId, string $phoneId, string $phoneNumber): array
      {
          clover_log('[Clover] UPDATE customer phone: ' . $phoneNumber);
     
          $data = [
              'phoneNumber' => $phoneNumber
          ];
     
          return $this->post("/customers/{$customerId}/phone_numbers/{$phoneId}", $data);
      }
     
      public function getCustomerPhoneId(string $customerId): string
      {
          $response = $this->getCustomer($customerId, ['expand' => 'phoneNumbers']);
          $elements = $response["data"]["phoneNumbers"]["elements"] ?? [];
     
          if (!empty($elements[0]['id'])) {
              return $elements[0]['id'];
          }
     
          return '';
      }

       /**
        * Agregar una nueva dirección al cliente
        */
       public function addCustomerAddress(string $customerId, int $user_id): array
       {
          clover_log('[Clover] ADD customer address for user: ' . $user_id);
     
          $data = [
              'address1' => get_user_meta($user_id, 'billing_address_1', true),
             //'address2' => get_user_meta($user_id, 'billing_address_2', true),
             'city' => get_user_meta($user_id, 'billing_city', true),
              'state' => get_user_meta($user_id, 'billing_state', true),
              'zip' => get_user_meta($user_id, 'billing_postcode', true),
             'country' => get_user_meta($user_id, 'billing_country', true) ?: 'US'
          ];
     
          // Filtrar valores vacíos
          $data = array_filter($data, function($value) {
              return !empty($value);
          });
     
          return $this->post("/customers/{$customerId}/addresses", $data);
      }

       /**
       * Actualizar una dirección existente del cliente
       */
      public function updateCustomerAddress(string $customerId, string $addressId, int $user_id): array
      {
         clover_log('[Clover] UPDATE customer address: ' . $addressId);
     
         $data = [
              'address1' => get_user_meta($user_id, 'billing_address_1', true),
             // 'address2' => get_user_meta($user_id, 'billing_address_2', true),
              'city' => get_user_meta($user_id, 'billing_city', true),
              'state' => get_user_meta($user_id, 'billing_state', true),
              'zip' => get_user_meta($user_id, 'billing_postcode', true),
              'country' => get_user_meta($user_id, 'billing_country', true) ?: 'US'
          ];
     
          // Filtrar valores vacíos
          $data = array_filter($data, function($value) {
              return !empty($value);
          });
     
          return $this->post("/customers/{$customerId}/addresses/{$addressId}", $data);
      }
     
      /**
       * Obtener el ID de la primera dirección del cliente
       */
      public function getCustomerAddressId(string $customerId): string
      {
          $response = $this->getCustomer($customerId, ['expand' => 'addresses']);
          $elements = $response["data"]["addresses"]["elements"] ?? [];
     
          if (!empty($elements[0]['id'])) {
              return $elements[0]['id'];
          }
     
          return '';
     }

  }