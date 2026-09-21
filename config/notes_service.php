<?php

return [
    // Config-backed values also work after Laravel config:cache.
    'username' => env('API_USERNAME', env('APPSETTING_API_USERNAME')),
    'password' => env('API_PASSWORD', env('APPSETTING_API_PASSWORD')),
];
