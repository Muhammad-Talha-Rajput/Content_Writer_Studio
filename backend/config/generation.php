<?php
return [
    'keys' => ['openrouter' => env('OPENROUTER_API_KEY'), 'gemini' => env('GEMINI_API_KEY'), 'huggingface' => env('HUGGINGFACE_API_KEY')],
    'prompt_version' => 'gd-studio-1',
    'limits' => ['user_daily_runs' => 20, 'organization_daily_runs' => 100, 'active_runs' => 5,
        'duration_seconds' => 600, 'max_tokens' => 60000, 'max_output_tokens' => 8000, 'context_characters' => 20000,
        'revision_passes' => 2, 'search_results' => 3],
];
