<?php
declare(strict_types=1);

return [
    // Active AI provider driver name. 'openai' is the only one that ships in
    // core; register more via AiProviderFactory::extend().
    'provider' => env('AI_PROVIDER', 'openai'),

    // Default model passed to chat()/stream() when no model argument is given.
    'default_model' => env('AI_DEFAULT_MODEL', 'gpt-4o'),

    // Provider-specific config, keyed by driver name.
    'providers' => [
        'openai' => [
            // API key for the OpenAI-compatible endpoint.
            'api_key'   => env('OPENAI_API_KEY', ''),
            // API base URL — swap to use an OpenAI proxy, Ollama, vLLM, Groq, etc.
            'base_url'  => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            // Per-provider default model override.
            'default_model' => env('OPENAI_MODEL', ''),
            // Request timeout in seconds.
            'timeout'   => (int) env('OPENAI_TIMEOUT', 60),
        ],
    ],
];
