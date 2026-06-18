# Laravel AI Tasks

[![License](https://img.shields.io/packagist/l/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Latest Stable Version](https://img.shields.io/packagist/v/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Total Downloads](https://img.shields.io/packagist/dt/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)

Оркестратор AI-задач для Laravel. Маршрутизація, черги, аудит-лог, бюджети, вебхуки — поверх [Prism](https://prismphp.com) як транспортного шару.

[English documentation](README.md)

## Dashboard

Вбудований веб-інтерфейс за адресою `/ai-tasks` — список runs зі статистикою, фільтрами та деталями кожного запиту (request, response, токени, вартість).

![Dashboard](art/dashboard.gif)

Конфігурується в `config/ai.php`:

```php
'dashboard' => [
    'enabled'    => env('AI_DASHBOARD_ENABLED', true),
    'path'       => env('AI_DASHBOARD_PATH', 'ai-tasks'),
    'middleware' => ['web'],
],
```

## Вимоги

- PHP ^8.2
- Laravel ^11 | ^12 | ^13
- [prism-php/prism](https://github.com/prism-php/prism) ^0.70

## Встановлення

```bash
composer require fomvasss/laravel-ai-tasks
```

```bash
php artisan vendor:publish --tag=ai-config
php artisan vendor:publish --tag=ai-migrations
php artisan migrate
```

Додай ключі до `.env`:

```env
AI_DEFAULT=openai

OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
GEMINI_API_KEY=...
```

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
    'timeout'      => 120,
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

use Prism\Prism\ValueObjects\Messages\UserMessage;
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
            modality:     $this->modality(),
            messages:     [new UserMessage("Стисни текст: {$this->text}")],
            systemPrompt: 'Ти помічник-редактор. Відповідай максимум 3 реченнями.',
            options:      ['temperature' => 0.3],
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

| Провайдер | Stream | Примітка |
|---|---|---|
| `openai` | ✅ | Нативний SSE через `chat/completions` |
| `gemini` | ✅ | Нативний SSE через `streamGenerateContent` |
| `anthropic` | ✅ | Нативний SSE через Anthropic Messages API |
| `deepseek`, `groq`, `mistral`, `xai` | ✅ | Нативний SSE — OpenAI-сумісний endpoint |
| будь-який провайдер з `prism.providers.*.url` | ✅ | Автоматично як OpenAI-сумісний |
| інші | ⚠️ | Prism stream → тихий fallback на `asText()` |

Для провайдерів у рядку ⚠️ callback викликається один раз з повною відповіддю — інтерфейс однаковий, просто без проміжних чанків.

### Довгі відповіді та таймаути

`AI::send()` використовує HTTP-клієнт Prism з хардкодованим таймаутом 30 секунд. Для задач що генерують великі тексти (статті, розповіді, детальні звіти) цей таймаут може спрацювати раніше ніж прийде відповідь.

**Для довгих відповідей використовуй `AI::stream()`** — він йде через нативний HTTP без таких обмежень:

```php
// send() може впасти по таймауту на великих відповідях
$response = AI::send(new WriteStoryTask(), drivers: ['deepseek']); // ❌ може timeout

// stream() без таймауту — безпечний для будь-якого обсягу
$response = AI::stream(new WriteStoryTask(), function (string $chunk) {
    // обробляй чанки в реальному часі, або просто ігноруй
}, drivers: ['deepseek']); // ✅

$response->content; // повний накопичений текст
```

Стосується всіх провайдерів через нативний SSE (`openai`, `anthropic`, `gemini`, `deepseek`, `groq` тощо).

## Маршрутизація драйверів

Маршрути задаються у `config/ai.php`:

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
// config/ai.php
'budgets' => [
    'tenant-abc' => ['monthly_usd' => 50.0],
    'default'    => ['monthly_usd' => 100.0],
],
```

`TenantResolver` визначає `tenant_id` з заголовка `X-Tenant-Id`, авторизованого користувача або конфігу. Щоб замінити — збіндити власний клас:

```php
$this->app->singleton(\Fomvasss\AiTasks\Support\TenantResolver::class, fn() => new MyTenantResolver());
```

## Відстеження витрат

Задай ціни у `config/ai.php` (за 1M токенів у USD):

```php
'anthropic' => [
    'api_key' => env('ANTHROPIC_API_KEY'),
    'model'   => 'claude-sonnet-4-6',
    'price'   => [
        'in'          => 3.00,
        'out'         => 15.00,
        'cache_write' => 3.75,  // запис у кеш промпту
        'cache_read'  => 0.30,  // читання з кешу промпту
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

## Генерація зображень

Встановіть `modality: 'image'` в payload. Наразі підтримується через OpenAI Images API (`gpt-image-1`).

```php
class GenerateImageTask extends AiTask
{
    public function modality(): string { return 'image'; }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: 'image',
            messages: [new UserMessage('Мінімалістичний синій логотип для tech-стартапу')],
            options: [
                'model' => 'gpt-image-1',
                'size'  => '1024x1024',
            ],
        );
    }

    public function postprocess(AiResponse $resp): array|AiResponse
    {
        // $resp->content — URL або base64-рядок залежно від моделі
        return $resp;
    }
}

$r = AI::send(new GenerateImageTask(), drivers: ['openai']);
```

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

Поточна модель з конфігу позначається `✓`. Провайдери з URL у `prism.providers.*.url` (Groq, Mistral, DeepSeek, xAI, Ollama, OpenRouter…) опитуються через OpenAI-сумісний `/v1/models` автоматично.

## Підтримувані провайдери

Будь-який провайдер підтримуваний [Prism](https://prismphp.com) працює автоматично — достатньо додати секцію до `config/ai.php` з `api_key` і `model`. Зміни в коді не потрібні.

Наступні провайдери вже є в `config/ai.php` (достатньо додати `.env` ключ):

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
| будь-який Prism провайдер | — | додати вручну |

### Як працюють credentials

Prism читає credentials провайдерів зі свого конфігу — або з опублікованого `config/prism.php`, або напряму зі змінних `.env`. Поле `api_key` у `config/ai.php` використовується тільки цим пакетом для перевірки "чи сконфігурований драйвер" перед відправкою запиту.

Щоб дізнатись які `.env` змінні потрібні для конкретного провайдера — дивись:

```
vendor/prism-php/prism/config/prism.php
```

**Додавання нового провайдера** (наприклад DeepSeek):

```bash
# 1. Додай в .env
DEEPSEEK_API_KEY=sk-...

# 2. Додай в config/ai.php — більше нічого не потрібно
```

```php
'deepseek' => [
    'api_key' => env('DEEPSEEK_API_KEY'),
    'model'   => 'deepseek-chat',
    'price'   => ['in' => 0.14, 'out' => 0.28],
],
```

Провайдери що потребують кастомного URL (Ollama, self-hosted Mistral, OpenRouter тощо) мають відповідну змінну `*_URL` — видно в тому ж файлі Prism.

## Changelog

Дивись [CHANGELOG](CHANGELOG.md).

## Ліцензія

MIT — дивись [LICENSE](LICENSE.md).
