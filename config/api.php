<?php

return [
    'base_url' => get_option('clover_api_base_url', 'https://api.clover.com/v3/merchants/'),
    'merchID' => get_option('clover_merchid'),
    'tokenBearer' => get_option('clover_token'),
];

