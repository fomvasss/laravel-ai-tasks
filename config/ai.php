<?php

declare(strict_types=1);

use Fomvasss\AiTasks\Support\Pipes\EnsureJson;
use Fomvasss\AiTasks\Support\Pipes\QualityScore;
use Fomvasss\AiTasks\Support\Pipes\SanitizeHtml;

return [

    'default' => env('AI_DEFAULT', 'openai'),

    'default_tenant' => env('AI_DEFAULT_TENANT', 'default'),

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
                'in'  => 0.00,
                'out' => 0.00,
            ],
        ],

        'ollama' => [
            'api_key' => env('OLLAMA_API_KEY', 'ollama'),
            'model'   => env('OLLAMA_MODEL', 'llama3.2'),
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
