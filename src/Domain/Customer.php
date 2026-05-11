<?php

declare(strict_types=1);

namespace Src\Domain;

final class Customer
{
    private string $id;
    private string $firstName;
    private string $lastName;
    private string $email;
   //private string $phone;

     /** @var Phone[] */
    private array $phones;

    /** @var Address[] */
    private array $addresses;

    public function __construct(
        string $id,
        string $firstName,
        string $lastName,
        string $email,
        array $phones  = [],
        array $addresses = []
    ) {
        $this->id = $id;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->email = $email;
        $this->phones = $phones;
        $this->addresses = $addresses;
    }

    public static function fromArray(array $data): self
    {   
        $phones  = [];
        $addresses = [];

         if (!empty($data['phoneNumbers']['elements'])) {
                  foreach ($data['phoneNumbers']['elements'] as $phoneData) {
                      $phones[] = Phone::fromArray($phoneData);
                  }
              }

        if (!empty($data['addresses']['elements'])) {
            foreach ($data['addresses']['elements'] as $addressData) {
                $addresses[] = Address::fromArray($addressData);
            }
        }

        return new self(
            $data['id'] ?? '',
            $data['firstName'] ?? '',
            $data['lastName'] ?? '',
            $data['emailAddresses']['elements'][0]['emailAddress'] ?? '',
            $phones,
            $addresses
        );
    }

    public function getId(): string { return $this->id; }
    public function getFirstName(): string { return $this->firstName; }
    public function getLastName(): string { return $this->lastName; }
    public function getEmail(): string { return $this->email; }
   
     /** @return Phone[] */
    public function getPhones(): array
    {
       return $this->phones;
    }

    /** @return Address[] */
    public function getAddresses(): array
    {
        return $this->addresses;
    }

     public function getPrimaryPhone(): ?Phone
    {
        return $this->phones[0] ?? null;
    }

    public function getPrimaryAddress(): ?Address
    {
        return $this->addresses[0] ?? null;
    }

    public function hasPhone(): bool
     {
            return !empty($this->phones);
     }

    public function hasEmail(): bool
     {
            return !empty($this->email);
     }
}
