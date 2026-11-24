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

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        '*',
    ],

    // pdf.js membaca header ini untuk memuat PDF sepotong-sepotong. Tanpa
    // dibuka di sini, browser menyembunyikannya dari skrip lintas domain dan
    // pdf.js terpaksa mengunduh seluruh berkas dulu sebelum halaman pertama
    // bisa digambar.
    'exposed_headers' => [
        'Accept-Ranges',
        'Content-Range',
        'Content-Length',
        'Content-Disposition',
    ],

    'max_age' => 0,

    'supports_credentials' => true,

];
