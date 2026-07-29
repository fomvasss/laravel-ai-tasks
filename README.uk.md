# Laravel AI Tasks

[![License](https://img.shields.io/packagist/l/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Latest Stable Version](https://img.shields.io/packagist/v/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Total Downloads](https://img.shields.io/packagist/dt/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)

Оркестратор AI-задач для Laravel. Маршрутизація, черги, аудит-лог, бюджети, вебхуки — поверх [laravel/ai](https://laravel.com/docs/ai-sdk) як транспортного шару.

[English documentation](README.md)

## Dashboard

Вбудований веб-інтерфейс за адресою `/ai-tasks` — список runs зі статистикою, фільтрами та деталями кожного запиту (request, response, токени, вартість).

![Dashboard](art/dashboard.gif)

Конфігурується в `config/ai-tasks.php`:

```php
'dashboard' => [
    'enabled'       => env('AI_DASHBOARD_ENABLED', true),
    'path'          => env('AI_DASHBOARD_PATH', '/ai-tasks'),
    'middleware'    => ['web'],
    'poll_interval' => env('AI_DASHBOARD_POLL', 3),        // секунди; 0 = вимкнути
    'theme'         => env('AI_DASHBOARD_THEME', 'system'), // light|dark|system
    'per_page'      => env('AI_DASHBOARD_PER_PAGE', 50),
],
```

## Вимоги

- PHP ^8.3
- Laravel ^12 | ^13
- [laravel/ai](https://laravel.com/docs/ai-sdk) ^0.10

## Встановлення

```bash
composer require fomvasss/laravel-ai-tasks
```

Публікуємо конфіги та запускаємо міграції:

```bash
# laravel/ai — конфіг провайдерів (API-ключі)
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider" --tag=ai-config

# цей пакет — маршрутизація, бюджети, черги
php artisan vendor:publish --tag=ai-tasks-config

php artisan vendor:publish --tag=ai-migrations
php artisan migrate
```

За замовчуванням запуски зберігаються в таблиці `ai_runs`. Щоб використати іншу назву таблиці, задай її перед запуском міграції — через `.env`:

```env
AI_TASKS_TABLE=my_ai_runs
```

або в `config/ai-tasks.php`:

```php
'table' => 'my_ai_runs',
```

Додай ключі до `.env` — credentials читає `laravel/ai`:

```env
AI_DEFAULT=openai

OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
GEMINI_API_KEY=...
DEEPSEEK_API_KEY=sk-...
GROQ_API_KEY=gsk_...
```

### Два конфіг-файли

| Файл | Призначення |
|---|---|
| `config/ai.php` | laravel/ai — API-ключі, URL провайдерів |
| `config/ai-tasks.php` | цей пакет — моделі, ціни, маршрутизація, бюджети |

## Horizon / Черги

За замовчуванням використовуються дві черги:

```env
AI_QUEUE=ai
AI_QUEUE_POST=ai-post
```

Приклад конфігурації Horizon:

```php
'supervisor-ai' => [
    'connection'   => 'redis',
    'queue'        => ['ai'],
    'balance'      => 'auto',
    'minProcesses' => 2,
    'maxProcesses' => 20,
    'tries'        => 3,
    'timeout'      => 300,
],
'supervisor-ai-post' => [
    'connection'   => 'redis',
    'queue'        => ['ai-post'],
    'balance'      => 'simple',
    'minProcesses' => 1,
    'maxProcesses' => 8,
    'tries'        => 3,
    'timeout'      => 60,
],
```

## Створення таску

```bash
php artisan ai:make-task SummarizeTask
php artisan ai:make-task Orders/AnalyzeTask --queued
```

```php
<?php

declare(strict_types=1);

namespace App\Ai\Tasks;

use Laravel\Ai\Messages\UserMessage;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Tasks\AiTask;

class SummarizeTask extends AiTask
{
    public function __construct(
        private readonly string $text,
    ) {}

    public function modality(): string
    {
        return 'text';
    }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: $this->modality(),
            messages: [new UserMessage("Стисни текст: {$this->text}")],
            systemPrompt: 'Ти помічник-редактор. Відповідай максимум 3 реченнями.',
            options: ['temperature' => 0.3],
        );
    }

    public function postprocess(AiResponse $response): AiResponse|array
    {
        // зберегти в БД, відправити подію тощо
        return $response;
    }
}
```

## Запуск задач

```php
use Fomvasss\AiTasks\Facades\AI;

// Синхронно
$response = AI::send(new SummarizeTask($text));
echo $response->content;

// Асинхронно (черга)
$runId = AI::queue(new SummarizeTask($text));

// Стрімінг
$response = AI::stream(new SummarizeTask($text), function (string $chunk) {
    echo $chunk;
});
// $response->content — повний накопичений текст
// $response->usage  — токени + вартість (як у AI::send)

// Перевизначити драйвер на льоту
$response = AI::send(new SummarizeTask($text), drivers: 'anthropic');
```

## Стрімінг

`AI::stream()` передає текст відповіді чанками через callback — зручно для real-time UI (SSE, WebSockets).

```php
$response = AI::stream(
    new SummarizeTask($text),
    function (string $chunk) {
        echo $chunk;           // або: event('stream', $chunk)
    },
    drivers: ['openai'],       // опціональний override драйвера
);

// Після завершення стріму:
$response->content;            // повний накопичений текст
$response->usage;              // токени + вартість
```

### Підтримка провайдерів

Всі провайдери `laravel/ai` підтримують streaming — OpenAI, Anthropic, Gemini, DeepSeek, Groq, Mistral, xAI, Ollama та будь-який OpenAI-сумісний endpoint.

### Довгі відповіді

`AI::send()` має дефолтний таймаут 60 секунд. Для великих відповідей (статті, розповіді) використовуй `AI::stream()` — у нього немає таймауту за замовчуванням:

```php
$response = AI::stream(new WriteStoryTask(), function (string $chunk) {
    // обробляй чанки або ігноруй
}, drivers: ['deepseek']);

$response->content; // повний накопичений текст
```

## Маршрутизація драйверів

Маршрути задаються у `config/ai-tasks.php`:

```php
'routing' => [
    'summarize'      => ['openai', 'anthropic'], // основний + fallback
    'orders_analyze' => ['gemini'],
],
```

Або безпосередньо на інстансі таску:

```php
AI::send((new SummarizeTask($text))->viaDrivers('gemini'));
```

## Multi-tenant бюджет

```php
// config/ai-tasks.php
'budgets' => [
    'tenant-abc' => ['monthly_usd' => 50.0],
    'default'    => ['monthly_usd' => 100.0],
],
```

`TenantResolver` визначає `tenant_id` з заголовка `X-Tenant-Id`, авторизованого користувача або конфігу. Щоб замінити — збіндити власний клас:

```php
$this->app->scoped(\Fomvasss\AiTasks\Support\TenantResolver::class, fn() => new MyTenantResolver());
```

`Fomvasss\AiTasks\Exceptions\BudgetExceededException` кидається, коли місячна витрата тенанта перевищує `monthly_usd` — перевірка йде і pre-flight (до звернення до провайдера, за вже витраченим), і post-call (після відповіді, за реальною ціною виклику) на `send()`, `stream()` і в чергового job. Якщо виняток стався post-call — виклик уже відбувся й оплачений, тож run все одно зберігається як `ok` з реальним `cost`, інакше ця витрата зникла б з майбутніх підрахунків бюджету.

## Відстеження витрат

Задай ціни у `config/ai-tasks.php` (за 1M токенів у USD):

```php
'anthropic' => [
    'model' => 'claude-sonnet-4-6',
    'price' => [
        'in'          => 3.00,
        'out'         => 15.00,
        'cache_write' => 3.75,
        'cache_read'  => 0.30,
    ],
],
```

Вартість розраховується після кожного запиту і зберігається в `ai_runs.cost`. Якщо `price` не задано — `cost` буде `null`, але кількість токенів завжди записується.

Аналітика витрат по тенанту через SQL:

```php
AiRun::where('tenant_id', $tenantId)
    ->where('status', 'ok')
    ->sum('cost'); // швидкий SQL-запит, проіндексована колонка
```

## Кешування промптів (Anthropic)

```php
return new AiPayload(
    modality:     'text',
    messages:     [new UserMessage($prompt)],
    systemPrompt: $longSystemPrompt,
    options:      ['cache' => true], // кешує systemPrompt на стороні Anthropic
);
```

## Структурований вивід (Schema)

Реалізуйте `AiTask::schema(): ?\Closure`, щоб оголосити JSON Schema для відповіді. На відміну від `jsonMode`, схему валідує сам провайдер (нативний structured output на Anthropic, OpenAI та інших через `StructuredAnonymousAgent` пакету `laravel/ai`) — модель фізично не може повернути іншу форму, ніж ви попросили.

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;

class ClassifyContentTask extends AiTask
{
    // ...

    public function schema(): ?\Closure
    {
        return fn (JsonSchema $schema): array => [
            'category'   => $schema->string()->enum(['tech', 'science', 'politics', 'sport', 'culture', 'business', 'other']),
            'confidence' => $schema->number(),
        ];
    }
}
```

`AiResponse::$structured` — це вже задекодований масив за вашою схемою, ручний `json_decode()` чи прибирання markdown-обгорток у `postprocess()` більше не потрібні:

```php
public function postprocess(AiResponse $resp): array|AiResponse
{
    return [
        'ok'         => $resp->ok,
        'category'   => $resp->structured['category']   ?? null,
        'confidence' => $resp->structured['confidence']  ?? null,
    ];
}
```

`schema()` має пріоритет над `jsonMode`, якщо задано обидва. Працює з `send()` і `queue()` (замикання автоматично обгортається в `Laravel\SerializableClosure\SerializableClosure`, тому переживає серіалізацію job-у), але не застосовується до `stream()`.

### Провайдер-специфічні опції

Для schema-задач можна передати провайдер-специфічні поля запиту через `AiPayload::$options['provider_options']` — масив, ключований назвою драйвера. Дійде лише до відповідного драйвера; на запити інших провайдерів не впливає.

```php
return new AiPayload(
    modality: 'text',
    messages: [new UserMessage($this->text)],
    systemPrompt: $this->instructions,
    options: [
        'temperature' => 0.3,
        'provider_options' => [
            'deepseek' => ['thinking' => ['type' => 'disabled']], // тільки для DeepSeek, інші драйвери ігнорують
        ],
    ],
);
```

Реалізовано через контракт `HasProviderOptions` пакету `laravel/ai` — `StructuredToolChoiceAgent::providerOptions(Lab|string $provider)` повертає масив, заданий для резолвленого драйвера, або `[]`, якщо для нього нічого не налаштовано. Корисно для нативних параметрів провайдера, які пакет не обгортає явно (DeepSeek `thinking`, Anthropic extended-thinking бюджети, Gemini `thinkingConfig` тощо). Працює лише коли використовується `schema()` (`StructuredToolChoiceAgent`); на `jsonMode` чи звичайні text-задачі не впливає.

## Вибір тула (Tool Choice)

Реалізуйте `AiTask::toolChoice()`, щоб примусити модель викликати (чи навпаки — не викликати) конкретний тул, на додачу до `AiTask::tools()`. Працює через `ToolChoice` пакету `laravel/ai` (Gemini, OpenAI, Anthropic).

```php
use Laravel\Ai\ToolChoice;

public function toolChoice(): ToolChoice|string|array|null
{
    return ToolChoice::required;      // модель має викликати якийсь тул
    // return ToolChoice::none;       // модель не повинна викликати жоден тул
    // return ToolChoice::tool('current_date'); // модель має викликати саме цей тул
    // return 'required';             // рядкові режими теж приймаються
}
```

Примусовий вибір автоматично знімається після першого кроку, тому за примусовим tool-викликом все одно йде звичайна текстова відповідь із результатом тула. `toolChoice()` за замовчуванням `null` (дефолт провайдера, зазвичай `auto`) і не має ефекту без `tools()`.

## JSON-режим

Виставте `jsonMode: true` в `AiPayload`, щоб модель завжди повертала валідний JSON — без markdown-обгорток і тексту поза об'єктом. Для нових задач надавайте перевагу `schema()` вище; `jsonMode` лишається для випадків, де сувора форма не потрібна, або для стрімінгу.

```php
return new AiPayload(
    modality: 'text',
    messages: [new UserMessage($this->text)],
    systemPrompt: 'Класифікуй текст. Відповідай у форматі {"category": "...", "confidence": 0.0-1.0}.',
    options: ['temperature' => 0.0],
    jsonMode: true,
);
```

Пакет автоматично перекладає `jsonMode: true` у правильний параметр для кожного провайдера:

| Провайдер | Механізм |
|---|---|
| OpenAI | `text.format: {type: json_object}` (Responses API) |
| xAI | `text.format: {type: json_object}` (Responses API) |
| Gemini | `generationConfig.response_mime_type: application/json` |
| DeepSeek, Groq, Mistral, OpenRouter, OpenAI-сумісний | `response_format: {type: json_object}` (Chat Completions) |
| Anthropic | нативного JSON-режиму немає — покладайся на інструкції в системному промпті |

> **Порада:** Завжди описуй очікувану JSON-структуру в `systemPrompt`. `jsonMode` гарантує синтаксичну валідність JSON; форма об'єкта залишається на відповідальності промпту.

## Кастомні credentials на рівні запиту (providerOverride)

`AiPayload::providerOverride` дозволяє передати власні API-credentials для одного запиту — без зміни системного конфігу або `.env`. Корисно коли застосунок керує per-tenant або per-user ключами.

```php
return new AiPayload(
    modality: 'text',
    messages: [new UserMessage($this->prompt)],
    systemPrompt: $this->instructions,
    providerOverride: [
        'driver' => 'deepseek',      // будь-який драйвер laravel/ai
        'key'    => $this->apiKey,   // ключ від користувача
        'model'  => 'deepseek-v4-flash', // опціонально; перекриває дефолт драйвера
        // 'url'          => '...',  // опціонально; кастомний base URL
        // 'organization' => '...',  // опціонально; OpenAI org
    ],
);
```

Як це працює:

- Тимчасова конфігурація провайдера реєструється під детермінованим аліасом (`custom_<hash>`) від `driver + key`. Ті самі credentials завжди дають той самий аліас, тому instance-кеш `laravel/ai` перевикористовується в межах одного процесу (безпечно з Horizon).
- Читабельне ім'я `driver` (напр. `deepseek`) записується в `ai_runs` — не внутрішній аліас.
- Якщо `key` порожній або `providerOverride` рівний `null` — задача переходить на системного провайдера.
- Якщо системного драйвера немає в конфізі, але `providerOverride` містить ключ — `isConfigured()` оминається і запит виконується з кастомними credentials.

**Поля `providerOverride`:**

| Поле | Тип | Обов'язкове | Опис |
|---|---|---|---|
| `driver` | `string` | так | Назва провайдера (`openai`, `deepseek`, `anthropic`, …). Для self-hosted/сторонніх endpoint-ів (LM Studio, vLLM, Together, …) використовуйте `openai-compatible` замість перевикористання `openai` |
| `key` | `string` | так | API-ключ |
| `model` | `string` | ні | Назва моделі (fallback: `options['model']`, потім дефолт драйвера) |
| `url` | `string` | ні | Кастомний base URL (обов'язковий для `openai-compatible`) |
| `organization` | `string` | ні | OpenAI organization ID |

## Задачі в черзі

Реалізуй `ShouldQueueAi` і визнач `serializeForQueue()`:

```php
use Fomvasss\AiTasks\Contracts\ShouldQueueAi;

class AnalyzeTask extends AiTask implements ShouldQueueAi
{
    public function __construct(private readonly int $productId) {}

    public function serializeForQueue(): array
    {
        return [$this->productId];
    }

    public function viaQueues(): array
    {
        return ['request' => 'ai', 'post' => 'ai-post'];
    }
}
```

> **Увага:** `serializeForQueue()` повинен повертати тільки скалярні значення (рядки, числа, масиви скалярів). Масив JSON-кодується і зберігається в Redis; на стороні воркера він передається назад у конструктор через `new static(...$args)`. Не передавай Eloquent-моделі — вони серіалізуються у звичайний масив, і конструктор отримає `array` замість екземпляра моделі. Передавай ID і завантажуй модель всередині `toPayload()`.
>
> `serializeForQueue()` також керує ідемпотентністю: якщо він повертає `[]` (за замовчуванням), дедублікація для `AI::queue()` не застосовується. Будь-яка задача з параметрами конструктора, що впливають на промпт, повинна реалізовувати `serializeForQueue()` — `AI::queue()` кине `LogicException` при диспетчеризації, якщо виявить параметри конструктора, але `serializeForQueue()` повертає `[]`.

### Відкладений запуск (delay)

Передай `delay` у `AI::queue()` щоб відкласти виконання:

```php
AI::queue(new SummarizeTask($text), delay: 300);                 // 5 хвилин (секунди)
AI::queue(new SummarizeTask($text), delay: now()->addHours(2));  // Carbon
AI::queue(new SummarizeTask($text), delay: new \DateInterval('PT10M'));
```

### Перевірка перед виконанням — `shouldRun()`

Перевизнач `shouldRun()` у будь-якому таску, щоб зробити останню перевірку всередині job-а, **до** виклику API. Якщо метод повертає `false` — run отримує статус `skipped`, токени не витрачаються:

```php
class AnalyzeProductTask extends AiTask
{
    public function __construct(private readonly int $productId) {}

    public function shouldRun(): bool
    {
        // перевіряємо актуальний стан в момент виконання
        return Product::find($this->productId)?->needs_analysis ?? false;
    }
}
```

Корисно коли задача в черзі може втратити актуальність до того, як воркер її підхопить (запис видалено, статус змінився, результат вже є).

### Ідемпотентність

Кожен run захищений від дублів через унікальний `idempotency_key` в `ai_runs`. Ключ — хеш від `[tenantId, taskName, modality, serializeForQueue()]`.

**Дедублікація активна тільки коли `serializeForQueue()` повертає непорожній масив.** Якщо він повертає `[]` (за замовчуванням), `idempotencyKey()` повертає `null` і дедублікація не застосовується — кілька runs з однаковою задачею можуть співіснувати. Тобто: для будь-якої задачі зі змінними вхідними даними реалізація `serializeForQueue()` обов'язкова як для відновлення з черги, так і для коректної роботи idempotency.

**Поведінка при колізії (коли ненульовий ключ вже є в `ai_runs`):**
- `AI::queue()` — повертає наявний `run_id`; дублікат job-а не диспатчиться.
- `AI::send()` — завжди робить свіжий API-виклик; `idempotency_key` для sync-runs не зберігається.

Перевизнач `idempotencyKey()` для власної логіки дедублікації:

```php
class ChatTask extends AiTask
{
    public function __construct(
        private readonly string $question,
        private readonly string $messageId, // унікальний ID повідомлення з чат-системи
        private readonly array  $history = [],
    ) {}

    public function serializeForQueue(): array
    {
        return [$this->question, $this->messageId, $this->history];
    }
    // idempotencyKey() за замовчуванням — включає messageId, кожен turn унікальний
}
```

Для чат/асистент-інтеграцій, де одне й те саме питання може задаватись кілька разів: поки в `serializeForQueue()` є `messageId` або повна історія чату — кожен turn дає унікальний ключ, і idempotency захищає тільки від технічних дублів (double-send, retry черги).

## Інструменти та MCP

Перевизнач `tools()` у будь-якому таску, щоб передати інструменти в `AnonymousAgent`. Інструменти автоматично передаються при `send()`, `stream()` і `queue()`.

Підтримуються три підходи:

- **Локальні інструменти** — PHP-класи, що реалізують `Laravel\Ai\Contracts\Tool`
- **Нативний MCP** (рекомендовано) — встанови [`laravel/mcp`](https://github.com/laravel/mcp) і використовуй `Client::web()` або `Client::local()` для HTTP та stdio-серверів; `laravel/ai` обгортає tool primitives автоматично, supergateway-проксі не потрібен
- **HttpMcpClient** — fallback без залежностей для Streamable HTTP серверів, якщо `laravel/mcp` не встановлений

**→ [Повна документація: docs/mcp.md](docs/mcp.md)**

## Таймаут завдань черги

Перевизнач `jobTimeout()` для контролю максимального часу виконання job-а в черзі:

```php
class HeavyTask extends AiTask
{
    public function jobTimeout(): int { return 600; } // секунди, дефолт 300
}
```

Значення передається в `ProcessAiPayload` при диспетчеризації. Supervisor Horizon повинен мати `timeout` не менший за максимальний `jobTimeout()` у ваших задачах.

## Laravel Octane

Додаткова конфігурація не потрібна. Пакет підтримує Octane автоматично:

- `TenantResolver` збіндений як `scoped` — новий інстанс на кожен запит/job
- Кеш драйверів `AiManager` скидається при кожній події `RequestReceived` і `TaskReceived`

Якщо ти надаєш власний `TenantResolver` зі станом — `scoped` гарантує коректне скидання між запитами.

## Тестування

`AI::fake()` замінює реальний AI-менеджер підробленим — без HTTP-запитів. Записує всі виклики і надає assertion-хелпери.

```php
use Fomvasss\AiTasks\Facades\AI;

// За замовчуванням: всі таски вертають "fake ai response"
$fake = AI::fake();

// Фіксована відповідь для всіх тасків
$fake = AI::fake('Короткий підсумок.');

// Відповіді по імені таску
$fake = AI::fake([
    'summarize' => 'Це підсумок.',
    'translate'  => 'This is a translation.',
    '*'          => 'Відповідь за замовчуванням.',  // catch-all
]);
```

### Перевірки

```php
$fake->assertSent(SummarizeTask::class);

$fake->assertSent(SummarizeTask::class, function (AiTask $task, string $method) {
    return $task->name() === 'summarize' && $method === 'send';
});

$fake->assertNotSent(TranslateTask::class);

$fake->assertQueued(SummarizeTask::class);

$fake->assertSentCount(3);   // всі виклики: send + stream + queue

$fake->assertNothingSent();
```

`AI::stream()` з fake все одно викликає `$onChunk` один раз з повною відповіддю — стрімінгова логіка також тестується.

## Події

| Подія | Коли |
|---|---|
| `AiTaskQueued` | Таск відправлено в чергу |
| `AiTaskStarted` | Починається виклик API |
| `AiTaskCompleted` | Постобробка завершена, відповідь готова |
| `AiTaskFailed` | Всі драйвери впали |
| `AiRunFinished` | Низький рівень: один виклик драйвера успішний |
| `AiRunFailed` | Низький рівень: один виклик драйвера впав |

```php
Event::listen(AiTaskCompleted::class, function (AiTaskCompleted $event) {
    // $event->task, $event->response, $event->run
});
```

## Модальності

Підтримуються п'ять модальностей: `text` · `image` · `embed` · `audio` · `transcription`.

Визнач `modality()` і `toPayload()` відповідно. Для генерації зображень, embeddings, TTS і транскрипції:

**→ [docs/modalities.md](docs/modalities.md)**

## Artisan команди

| Команда | Опис |
|---|---|
| `ai:make-task Name` | Згенерувати клас таску |
| `ai:models [driver]` | Список доступних моделей від провайдера |
| `ai:request "prompt"` | Ad-hoc запит (sync або queue) |
| `ai:runs` | Список останніх ai_runs |
| `ai:budget {tenant}` | Витрати vs ліміт за поточний місяць |
| `ai:retry` | Список failed-записів для повторного запуску |

### ai:models

```bash
# всі сконфігуровані драйвери
php artisan ai:models

# конкретний драйвер
php artisan ai:models gemini

# фільтр по назві моделі
php artisan ai:models openai --filter=gpt-4

# детально: ліміти токенів, дата релізу, можливості
php artisan ai:models anthropic --detail
```

Поточна модель з конфігу позначається `✓`. Провайдери з URL у `config/ai.php` (Groq, Mistral, DeepSeek, xAI, Ollama…) опитуються через OpenAI-сумісний `/v1/models` автоматично.

## Підтримувані провайдери

Будь-який провайдер підтримуваний [laravel/ai](https://laravel.com/docs/ai-sdk) працює автоматично — достатньо додати секцію до `config/ai.php` (ключ) і `config/ai-tasks.php` (модель, ціна). Зміни в коді не потрібні.

Наступні провайдери вже прописані в `config/ai-tasks.php` (достатньо додати `.env` ключ):

| Провайдер | Ключ драйвера | В конфігу |
|---|---|---|
| OpenAI | `openai` | ✅ |
| Anthropic | `anthropic` | ✅ |
| Google Gemini | `gemini` | ✅ |
| DeepSeek | `deepseek` | ✅ |
| Groq | `groq` | ✅ |
| Mistral | `mistral` | ✅ |
| xAI (Grok) | `xai` | ✅ |
| Ollama (локально) | `ollama` | ✅ |
| VoyageAI | `voyageai` | додати вручну |
| AWS Bedrock | `bedrock` | додати вручну |
| OpenRouter | `openrouter` | додати вручну |
| Perplexity | `perplexity` | додати вручну |
| ElevenLabs | `eleven` | ✅ (audio/tts) |
| будь-який laravel/ai провайдер | — | додати вручну |

### Як працюють credentials

`laravel/ai` читає API-ключі з `config/ai.php` (публікується через `vendor:publish --provider="Laravel\Ai\AiServiceProvider"`). `api_key` **не** зберігається в `config/ai-tasks.php` — там лише назви моделей, ціни і маршрутизація.

Щоб дізнатися, які `.env` змінні потрібні кожному провайдеру, перегляньте:

```
vendor/laravel/ai/config/ai.php
```

**Додавання нового провайдера** (наприклад Mistral):

```php
// config/ai.php (laravel/ai)
'mistral' => [
    'key' => env('MISTRAL_API_KEY'),
    'url' => 'https://api.mistral.ai/v1',
],

// config/ai-tasks.php (цей пакет)
'mistral' => [
    'model' => 'mistral-large-latest',
    'price' => ['in' => 2.00, 'out' => 6.00],
],
```

## Changelog

Дивись [CHANGELOG](CHANGELOG.md).

## Ліцензія

MIT — дивись [LICENSE](LICENSE.md).

## Підтримка

Якщо цей пакет є корисним для вас, розгляньте можливість підтримки його розробки:

[![Monobank](https://img.shields.io/badge/Donate-Monobank-black)](https://send.monobank.ua/jar/5xsqtHvVrY)
[![Ko-Fi](https://img.shields.io/badge/Donate-Ko--fi-FF5E5B?logo=ko-fi&logoColor=white)](https://ko-fi.com/fomvasss)
[![USDT TRC20](https://img.shields.io/badge/Donate-USDT%20TRC20-26A17B?logo=tether&logoColor=white)](https://link.trustwallet.com/send?coin=195&address=THLgp6DxiAtbNHvgnKV56vk1L38UuUagKf&token_id=TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t)

> Адреса USDT TRC20: `THLgp6DxiAtbNHvgnKV56vk1L38UuUagKf`
