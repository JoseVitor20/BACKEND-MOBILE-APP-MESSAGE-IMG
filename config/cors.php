<?php

return [

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'images/*',
        'storage/*',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5174',
        'http://127.0.0.1:5173',
        'http://localhost:5173',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://10.0.0.104:8000', // Expo Dev Client
        'exp://10.0.0.104:8000',  // Expo
        'http://localhost:8081',  // Expo Web ✅ sem barra
    ],


    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];