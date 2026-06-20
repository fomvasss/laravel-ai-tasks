<?php

declare(strict_types=1);

use Fomvasss\AiTasks\Support\Pipes\EnsureJson;
use Fomvasss\AiTasks\Support\Pipes\QualityScore;
use Fomvasss\AiTasks\Support\Pipes\SanitizeHtml;

return [

    // Default driver used when no routing rule matches and no driver is passed explicitly.
    'default' => env('AI_DEFAULT', 'openai'),

    // Tenant ID used when the request cannot be resolved to a specific tenant.
    'default_tenant' => env('AI_DEFAULT_TENANT', 'default'),

    // Whether to persist messages and system prompt in ai_runs.request.
    // Disable in production if prompts contain sensitive data.
    'store_request' => env('AI_STORE_REQUEST', false),

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | Built-in web UI at /{path} showing ai_runs with filters and stats.
    |
    | middleware — add 'auth' or any Gate/Role middleware to restrict access.
    |   Example: ['web', 'auth']
    |   Example: ['web', 'auth', 'role:admin']  // spatie/laravel-permission
    */
    'dashboard' => [
        'enabled'    => env('AI_DASHBOARD_ENABLED', true),
        'path'       => env('AI_DASHBOARD_PATH', 'ai-tasks'),
        'middleware' => ['web'],
        // Auto-refresh interval in seconds. Set to 0 to disable polling.
        'poll_interval' => env('AI_DASHBOARD_POLL', 3),
        // Default theme: 'light' | 'dark' | 'system' (follows OS preference).
        // User's manual toggle is always saved to localStorage and takes priority.
        'theme'    => env('AI_DASHBOARD_THEME', 'system'),
        'per_page' => env('AI_DASHBOARD_PER_PAGE', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    |
    | Two separate queues allow independent scaling:
    |   default — AI API requests (slow, IO-bound, needs higher timeout)
    |   post    — postprocess jobs after response arrives (fast, CPU-bound)
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
    | Key must match a provider name in config/ai.php (laravel/ai).
    | API credentials go in config/ai.php — not here.
    |
    | model       — default model for text/chat requests
    | embed_model — model for 'embed' modality
    | image_model — model for 'image' modality
    | audio_model — model for 'audio' (TTS) modality
    | price       — per 1M tokens in USD; null = cost not tracked
    |               anthropic supports: in, out, cache_write, cache_read
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
            // Webhook signature verification (optional)
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
                'cache_write' => 3.75,  // prompt cache write
                'cache_read'  => 0.30,  // prompt cache hit
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

        'ollama' => [
            'model' => env('OLLAMA_MODEL', 'llama3.2'),
            'price' => null,
        ],

        // ElevenLabs — audio/TTS only
        'eleven' => [
            'audio_model' => env('ELEVENLABS_AUDIO_MODEL', 'eleven_multilingual_v2'),
            'price'       => null,
        ],

        // Null driver — returns empty response, useful for testing/local dev
        'null' => [
            'model' => 'null',
            'price' => null,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    |
    | Maps task name → ordered list of drivers (first available is used,
    | next is tried on failure — fallback chain).
    |
    | Task name comes from AiTask::name() (defaults to snake_case class name).
    */
    'routing' => [
        // 'summarize'  => ['openai', 'gemini'],
        // 'chat'       => ['anthropic'],
        // 'transcribe' => ['openai'],
        // 'tts'        => ['openai', 'eleven'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Postprocess Pipeline
    |--------------------------------------------------------------------------
    |
    | Global pipes applied to every AiResponse before AiTaskCompleted fires.
    | Each pipe receives AiResponse and must return AiResponse.
    | Task-level postprocess() runs before these pipes.
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
    | Budgets
    |--------------------------------------------------------------------------
    |
    | Per-tenant monthly spend limit in USD.
    | BudgetExceededException is thrown before and after each request.
    | Tenant ID is resolved via TenantResolver (X-Tenant-Id header by default).
    */
    'budgets' => [
        // 'default'   => ['monthly_usd' => 100],
        // 'tenant-id' => ['monthly_usd' => 50],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Middleware
    |--------------------------------------------------------------------------
    |
    | Applied to POST /ai-tasks/webhook/{driver} routes.
    */
    'webhook_middleware' => ['api'],

];
