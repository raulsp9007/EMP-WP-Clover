<?php                                                                                                      
                                                                                                              
   declare(strict_types=1);                                                                                   
                                                                                                             
   namespace Src\Domain;                                                                                      
                                                                                                              
   final class Phone                                                                                          
   {                                                                                                          
       private string $id;                                                                                    
       private string $phoneNumber;                                                                           
                                                                                                              
     public function __construct(                                                                           
           string $id,                                                                                        
           string $phoneNumber                                                                                
       ) {                                                                                                    
           $this->id = $id;                                                                                   
          $this->phoneNumber = $phoneNumber;                                                                 
       }                                                                                                      
                                                                                                              
       public static function fromArray(array $data): self                                                    
       {                                                                                                      
           return new self(                                                                                   
               $data['id'] ?? '',                                                                             
              $data['phoneNumber'] ?? ''                                                                     
           );                                                                                                 
       }                                                                                                      
                                                                                                             
       public function toArray(): array                                                                       
      {                                                                                                      
           return [                                                                                           
              'id' => $this->id,                                                                             
               'phoneNumber' => $this->phoneNumber,                                                           
           ];                                                                                                 
       }                                                                                                      
                                                                                                              
       public function getId(): string { return $this->id; }                                                  
       public function getPhoneNumber(): string { return $this->phoneNumber; }                                
   }