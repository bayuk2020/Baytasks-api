<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | React/Vite frontend access for BayTasks
    |
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => [
        '*',
    ],

    'allowed_origins' => [
        'http://localhost:8080',
        'http://127.0.0.1:8080',

        // Tambahkan IP Vite jika berubah
        'http://10.*.*.*:8080',
        'http://192.168.100.*:8080',
        'http://10.235.190.*:8080',
        'http://10.247.125.250:8080',
        'http://192.168.1.*:8080',
        'http://10.206.61.*:8080',
        'http://10.78.20.*:8080',
        'http://192.*.*.*:8080',
        'http://192.168.1.5:8080/',
        

    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        '*',
    ],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];