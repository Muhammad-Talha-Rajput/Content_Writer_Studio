<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://127.0.0.1:5174'),
    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_MODEL', 'qwen2.5:7b'),
    ],
];
