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
    | Key = driver name, must match AiManager::create{Name}Driver().
    | price: per 1M tokens in USD (optional — без price cost буде null).
    */
    'drivers' => [

        'openai' => [
            'api_key'     => env('OPENAI_API_KEY'),
            'model'       => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'embed_model' => env('OPENAI_EMBED_MODEL', 'text-embedding-3-small'),
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
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model'   => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
            'price' => [
                'in'          => 3.00,
                'out'         => 15.00,
                'cache_write' => 3.75,
                'cache_read'  => 0.30,
            ],
        ],

        'gemini' => [
            'api_key'     => env('GEMINI_API_KEY'),
            'model'       => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'embed_model' => env('GEMINI_EMBED_MODEL', 'gemini-embedding-001'),
            'price' => [
                'in'  => 0.15,  // per 1M tokens, prompts ≤200K; verify at ai.google.dev/pricing
                'out' => 0.60,
            ],
        ],

        'ollama' => [
            'api_key' => env('OLLAMA_API_KEY', 'ollama'),
            'model'   => env('OLLAMA_MODEL', 'llama3.2'),
            'price'   => null,
        ],

        'deepseek' => [
            'api_key' => env('DEEPSEEK_API_KEY'),
            'model'   => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'price' => [
                'in'  => 0.27,
                'out' => 1.10,
            ],
        ],

        'groq' => [
            'api_key' => env('GROQ_API_KEY'),
            'model'   => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
            'price'   => null,
        ],

        'mistral' => [
            'api_key' => env('MISTRAL_API_KEY'),
            'model'   => env('MISTRAL_MODEL', 'mistral-small-latest'),
            'price'   => null,
        ],

        'xai' => [
            'api_key' => env('XAI_API_KEY'),
            'model'   => env('XAI_MODEL', 'grok-3-mini'),
            'price'   => null,
        ],

        'null' => [
            'api_key' => 'null-driver',
            'model'   => 'null',
            'price'   => null,
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
