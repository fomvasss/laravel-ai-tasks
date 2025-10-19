<?php

return [

    /*
     |--------------------------------------------------------------------------
     | Default driver & tenant
     |--------------------------------------------------------------------------
     |
     | 'default' — fallback driver when routing has no match.
     | 'default_tenant' — identifier used if you don't run multi-tenant.
     */
    'default' => env('AI_DEFAULT', 'openai'),

    'default_tenant' => env('AI_DEFAULT_TENANT', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Queue names
    |--------------------------------------------------------------------------
    |
    | Global queue names. A task can override via QueueableAi::viaQueues()
    | or ->onQueue().
    */
    'queues' => [
        'default' => env('AI_QUEUE_DEFAULT', 'ai:default'),  // most API calls
        'high'    => env('AI_QUEUE_HIGH',    'ai:high'),     // chat/stream/interactive
        'low'     => env('AI_QUEUE_LOW',     'ai:low'),      // batch/long-running
        'post'    => env('AI_QUEUE_POST',    'ai:post'),     // post-processing (CPU/IO)
        'webhook' => env('AI_QUEUE_WEBHOOK', 'ai:webhook'),  // provider webhooks
    ],

    /*
    |--------------------------------------------------------------------------
    | Drivers (providers)
    |--------------------------------------------------------------------------
    |
    | Key = driver name. Must match AiManager factory method:
    |   'openai' -> createOpenaiDriver()
    |   'gemini' -> createGeminiDriver()
    |
    | Fields:
    | - type, model, api_key, endpoint, mode (text|chat|image|vision|embed)
    | - price: approximate pricing to track costs (optional)
    | - limits: rpm/tpm to enforce rate limiting
    */
    'drivers' => [

        'openai' => [
            'type' => 'openai',
            'model' => env('OPENAI_MODEL','gpt-4.1-mini'),
            'api_key' => env('OPENAI_API_KEY'),
            'endpoint' => 'https://api.openai.com/v1',
            'mode' => 'chat',
            'price' => ['in' => 0.0, 'out' => 0.0],
            'limits' => ['rpm' => 200, 'tpm' => 1000000],
        ],

        'gemini' => [
            'type' => 'gemini',
            'model' => env('GEMINI_MODEL','gemini-1.5-flash'),
            'api_key' => env('GEMINI_API_KEY'),
            'endpoint' => 'https://generativelanguage.googleapis.com',
            'mode' => 'chat',
            'price' => ['in' => 0.0, 'out' => 0.0],
            'limits' => ['rpm' => 240],
        ],
        
        // Stub/local driver (optional)
        'null' => [
            'type'   => 'null', // requires a NullDriver if you enable it
            'mode'   => 'text',
            'price'  => ['in' => 0.0, 'out' => 0.0],
            'limits' => ['rpm' => 10_000],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routing: task name -> list of drivers (priority + fallback)
    |--------------------------------------------------------------------------
    |
    | Key — unique task name (AiTask::name()).
    | Value — ordered list of driver names to try.
    */
    'routing' => [
        'product.description' => ['gemini','openai'],
        'chat.assist'         => ['openai'],
        // 'image.product'     => ['stability', 'openai'],
        // default fallback is 'default' above
    ],

    /*
     |--------------------------------------------------------------------------
     | Default queues per task/stage
     |--------------------------------------------------------------------------
     |
     | Used when a task does not provide its own viaQueues().
     | Stages: request, postprocess, webhook.
     */
    'task_queues' => [
        'product.description' => ['request' => 'ai:low', 'postprocess' => 'ai:post'],
        'chat.assist'         => [
            'request' => env('AI_QUEUE_CHAT', 'ai:high'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Budgets (per tenant)
    |--------------------------------------------------------------------------
    |
    | Example: 'tenant-1' => ['monthly_usd' => 100].
    | Enforced by a guard before calling providers (if you plug it in).
    */
    'budgets' => [
        // 'tenant-id' => ['monthly_usd' => 100]
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Middleware for webhook routes. Routes are defined in ServiceProvider::boot().
    */
    'webhook_middleware' => ['api'],
];
