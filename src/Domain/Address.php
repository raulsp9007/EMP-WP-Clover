<?php


declare(strict_types=1);

namespace Src\Domain;

final class Address
{
    private string $id;
    private string $address1;
    private string $address2;
    private string $address3;
    private string $city;
    private string $country;
    private string $state;
    private string $zip;

    public function __construct(
        string $id,
        string $address1,
        string $address2,
        string $address3,
        string $city,
        string $country,
        string $state,
        string $zip
    ) {
        $this->id = $id;
        $this->address1 = $address1;
        $this->address2 = $address2;
        $this->address3 = $address3;
        $this->city = $city;
        $this->country = $country;
        $this->state = $state;
        $this->zip = $zip;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'] ?? '',
            $data['address1'] ?? '',
            $data['address2'] ?? '',
            $data['address3'] ?? '',
            $data['city'] ?? '',
            $data['country'] ?? '',
            $data['state'] ?? '',
            $data['zip'] ?? ''
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'address3' => $this->address3,
            'city' => $this->city,
            'country' => $this->country,
            'state' => $this->state,
            'zip' => $this->zip,
        ];
    }

    public function getId(): string { return $this->id; }
    public function getAddress1(): string { return $this->address1; }
    public function getAddress2(): string { return $this->address2; }
    public function getAddress3(): string { return $this->address3; }
    public function getCity(): string { return $this->city; }
    public function getCountry(): string { return $this->country; }
    public function getState(): string { return  $this->get_state_code_from_name($this->state); }
    public function getZip(): string { return $this->zip; }

    //código del state
    function get_state_code_from_name($state_name) {
           $states = array(
               'Alabama' => 'AL', 'Alaska' => 'AK', 'Arizona' => 'AZ', 'Arkansas' => 'AR', 'California' => 'CA',
               'Colorado' => 'CO', 'Connecticut' => 'CT', 'Delaware' => 'DE', 'Florida' => 'FL', 'Georgia' => 'GA',
              'Hawaii' => 'HI', 'Idaho' => 'ID', 'Illinois' => 'IL', 'Indiana' => 'IN', 'Iowa' => 'IA',
               'Kansas' => 'KS', 'Kentucky' => 'KY', 'Louisiana' => 'LA', 'Maine' => 'ME', 'Maryland' => 'MD',
               'Massachusetts' => 'MA', 'Michigan' => 'MI', 'Minnesota' => 'MN', 'Mississippi' => 'MS', 'Missouri' =>
        'MO',
               'Montana' => 'MT', 'Nebraska' => 'NE', 'Nevada' => 'NV', 'New Hampshire' => 'NH', 'New Jersey' => 'NJ'
        ,
               'New Mexico' => 'NM', 'New York' => 'NY', 'North Carolina' => 'NC', 'North Dakota' => 'ND', 'Ohio' =>
        'OH',
              'Oklahoma' => 'OK', 'Oregon' => 'OR', 'Pennsylvania' => 'PA', 'Rhode Island' => 'RI', 'South Carolina'
        => 'SC',
              'South Dakota' => 'SD', 'Tennessee' => 'TN', 'Texas' => 'TX', 'Utah' => 'UT', 'Vermont' => 'VT',
              'Virginia' => 'VA', 'Washington' => 'WA', 'West Virginia' => 'WV', 'Wisconsin' => 'WI', 'Wyoming' =>
        'WY'
          );
     
          return isset($states[$state_name]) ? $states[$state_name] : $state_name;
      }
}
