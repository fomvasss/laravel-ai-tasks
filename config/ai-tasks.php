<?php

declare(strict_types=1);

use Fomvasss\AiTasks\Support\Pipes\EnsureJson;
use Fomvasss\AiTasks\Support\Pipes\QualityScore;
use Fomvasss\AiTasks\Support\Pipes\SanitizeHtml;

return [

    'default' => env('AI_DEFAULT', 'openai'),

    'default_tenant' => env('AI_DEFAULT_TENANT', 'default'),

    'store_request' => env('AI_STORE_REQUEST', false),

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */
    'dashboard' => [
        'enabled'    => env('AI_DASHBOARD_ENABLED', true),
        'path'       => env('AI_DASHBOARD_PATH', 'ai-tasks'),
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    */
    'queues' => [
        'default' => env('AI_QUEUE', 'ai'),
        'post'    => env('AI_QUEUE_POST', 'ai-post'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | Key = provider name (must match laravel/ai provider key in config/ai.php).
    | Credentials go in config/ai.php (laravel/ai) — not here.
    | price: per 1M tokens in USD (null = cost not tracked).
    */
    'drivers' => [

        'openai' => [
            'model'       => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'embed_model' => env('OPENAI_EMBED_MODEL', 'text-embedding-3-small'),
            'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
            'audio_model' => env('OPENAI_AUDIO_MODEL', 'gpt-4o-audio-preview'),
            'price' => [
                'in'  => 0.15,
                'out' => 0.60,
            ],
            'webhook' => [
                'secret'           => env('OPENAI_WEBHOOK_SECRET'),
                'signature_header' => 'X-OpenAI-Signature',
            ],
        ],

        'anthropic' => [
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
            'price' => [
                'in'          => 3.00,
                'out'         => 15.00,
                'cache_write' => 3.75,
                'cache_read'  => 0.30,
            ],
        ],

        'gemini' => [
            'model'       => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'embed_model' => env('GEMINI_EMBED_MODEL', 'gemini-embedding-001'),
            'price' => [
                'in'  => 0.15,
                'out' => 0.60,
            ],
        ],

        'ollama' => [
            'model' => env('OLLAMA_MODEL', 'llama3.2'),
            'price' => null,
        ],

        'deepseek' => [
            'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'price' => [
                'in'  => 0.27,
                'out' => 1.10,
            ],
        ],

        'groq' => [
            'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
            'price' => null,
        ],

        'mistral' => [
            'model' => env('MISTRAL_MODEL', 'mistral-small-latest'),
            'price' => null,
        ],

        'xai' => [
            'model' => env('XAI_MODEL', 'grok-3-mini'),
            'price' => null,
        ],

        'eleven' => [
            'model'       => env('ELEVENLABS_MODEL', 'eleven_multilingual_v2'),
            'audio_model' => env('ELEVENLABS_AUDIO_MODEL', 'eleven_multilingual_v2'),
            'price'       => null,
        ],

        'null' => [
            'model' => 'null',
            'price' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routing: task name → driver list (priority + fallback)
    |--------------------------------------------------------------------------
    */
    'routing' => [
        // 'summarize' => ['openai', 'gemini'],
        // 'chat'      => ['anthropic'],
        // 'transcribe' => ['openai'],
        // 'tts'        => ['openai', 'eleven'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Postprocess Pipeline
    |--------------------------------------------------------------------------
    */
    'postprocess' => [
        'enabled' => false,
        'pipes'   => [
            // EnsureJson::class,
            // SanitizeHtml::class,
            // QualityScore::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Budgets (per tenant)
    |--------------------------------------------------------------------------
    |
    | monthly_usd — місячний ліміт витрат у USD.
    | Якщо не задано — бюджет не контролюється.
    */
    'budgets' => [
        // 'default'   => ['monthly_usd' => 100],
        // 'tenant-id' => ['monthly_usd' => 50],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    */
    'webhook_middleware' => ['api'],

];
