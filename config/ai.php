<?php

use Fomvasss\AiTasks\Support\Pipes\EnsureJson;
use Fomvasss\AiTasks\Support\Pipes\QualityScore;
use Fomvasss\AiTasks\Support\Pipes\SanitizeHtml;

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
    | Drivers (providers): openai, gemini, anthropic, openai, replicate, ollama, etc.
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
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL','gpt-4.1-mini'),
            'image_model' => env('OPENAI_IMAGE_MODEL','dall-e-3'), //dall-e-2, gpt-image-1
            //'images' => ['allow_response_format' => false],
            'endpoint' => 'https://api.openai.com/v1',
            'mode' => 'chat',
            'price' => ['in' => 0.0, 'out' => 0.0],
            'limits' => ['rpm' => 200, 'tpm' => 1000000],
            
            'webhook' => [
                'secret' => env('OPENAI_WEBHOOK_SECRET'),
                'signature_header' => 'X-OpenAI-Signature',
                // other options
            ],
        ],

        'gemini' => [
            'type' => 'gemini',
            'model' => env('GEMINI_MODEL','gemini-1.5-flash'),
            'imagen_model' => env('GEMINI_IMAGEN_MODEL','imagen-4.0-generate-001'),
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
        'product_description' => ['openai', 'gemini'],
        'chat_assist'         => ['openai'],

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
        'product_description' => ['request' => 'ai:low', 'postprocess' => 'ai:post'],
        'chat_assist'         => [
            'request' => env('AI_QUEUE_CHAT', 'ai:high'),
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Postprocess Pipeline (enable & class list)
    |--------------------------------------------------------------------------
    |
    | Classes are run in PostprocessAiResult (queue 'post').
    | Replace with your own if needed.
    */
    'postprocess' => [
        'enabled' => true,
        'pipes' => [
            EnsureJson::class,    // якщо очікується JSON
            SanitizeHtml::class,  // очистка HTML
            QualityScore::class,  // скоринг            
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

    /*
       |--------------------------------------------------------------------------
       | Prompt/Schema sources (simplified switches)
       |--------------------------------------------------------------------------
       |
       | You can store prompts in files/DB. Here we only toggle the source.
       */    
    'prompts' => [
        // files|database|inline // TODO: files|database
        'driver' => env('AI_PROMPTS_DRIVER', 'inline'),
        
        // для inline (мінімально; або залишай порожнім)
        'inline' => [
            'product_description_v3' => "Language: {{locale}}\nName: {{title}}\nFeatures: {{features | json}}\nGenerate a structured description product in JSON: {\"short\":\"...\",\"html\":\"...\"}",
        ],
        
        // для files
        'path'   => base_path('vendor/fomvasss/laravel-ai-tasks/resources/ai/prompts'), // replace to: resource_path('ai/prompts'),
        'extensions' => ['blade.php', 'md', 'txt', 'prompt'], // order find

        // cache (for files/database)
        'cache' => [
            'enabled' => true,
            'ttl' => 0, // сек
        ],
    ],

    'schemas' => [
        'driver' => 'files', // inline|files
        'path'   => base_path('vendor/fomvasss/laravel-ai-tasks/resources/ai/schemas'), // replace to: resource_path('ai/schemas'),

        'inline' => [
            
            // For exaple
            'product_description_v1' => [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'required' => ['short','html'],
                'properties' => [
                    'short' => ['type'=>'string','minLength'=>2,'maxLength'=>300],
                    'html'  => ['type'=>'string','minLength'=>10],
                ],
                'additionalProperties' => false,
            ],
        ],
    ],
];
