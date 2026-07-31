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

    // Database table used to store AI run records.
    'table' => env('AI_TASKS_TABLE', 'ai_runs'),

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
    |               per_char — per 1M input characters, for 'audio' (TTS) modality only —
    |               OpenAI's TTS endpoint returns no usage data, so cost is an approximation
    |               based on input text length; verify the rate against current OpenAI pricing
    */
    'drivers' => [

        'openai' => [
            'model'       => env('OPENAI_MODEL', 'gpt-5.6-luna'),
            'embed_model' => env('OPENAI_EMBED_MODEL', 'text-embedding-3-small'),
            'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-2'),
            'audio_model' => env('OPENAI_AUDIO_MODEL', 'gpt-4o-mini-tts'),
            'price' => [
                'in'       => 1.00,
                'out'      => 6.00,
                'per_char' => env('OPENAI_TTS_PRICE_PER_CHAR', 15.0), // approximate, verify against current pricing
            ],
            // Webhook signature verification (optional)
            'webhook' => [
                'secret'           => env('OPENAI_WEBHOOK_SECRET'),
                'signature_header' => 'X-OpenAI-Signature',
            ],
        ],

        'anthropic' => [
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
            'price' => [
                'in'          => 3.00,
                'out'         => 15.00,
                'cache_write' => 3.75,  // prompt cache write
                'cache_read'  => 0.30,  // prompt cache hit
            ],
        ],

        'gemini' => [
            'model'       => env('GEMINI_MODEL', 'gemini-3.6-flash'),
            'embed_model' => env('GEMINI_EMBED_MODEL', 'gemini-embedding-001'),
            'price' => [
                'in'  => 1.50,
                'out' => 7.50,
            ],
        ],

        'deepseek' => [
            'model' => env('DEEPSEEK_MODEL', 'deepseek-v4-flash'),
            'price' => [
                'in'  => 0.27,
                'out' => 1.10,
            ],
        ],

        'groq' => [
            'model' => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
            'price' => null,
        ],

        'mistral' => [
            'model' => env('MISTRAL_MODEL', 'mistral-small-latest'),
            'price' => null,
        ],

        'xai' => [
            'model' => env('XAI_MODEL', 'grok-4.1-fast'),
            'price' => null,
        ],

        'ollama' => [
            'model' => env('OLLAMA_MODEL', 'llama3.2'),
            'price' => null,
        ],

        'openrouter' => [
            'model' => env('OPENROUTER_MODEL', 'anthropic/claude-sonnet-5'),
            'price' => null,
        ],

        // ElevenLabs — audio/TTS only
        'eleven' => [
            'audio_model' => env('ELEVENLABS_AUDIO_MODEL', 'eleven_v3'),
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
