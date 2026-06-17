# Laravel AI Tasks

[![License](https://img.shields.io/packagist/l/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Latest Stable Version](https://img.shields.io/packagist/v/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Total Downloads](https://img.shields.io/packagist/dt/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)

Оркестратор AI-задач для Laravel. Маршрутизація, черги, аудит-лог, бюджети, вебхуки — поверх [Prism](https://github.com/echolabs/prism) як транспортного шару.

[English documentation](README.md)

## Вимоги

- PHP ^8.2
- Laravel ^11 | ^12
- [echolabs/prism](https://github.com/echolabs/prism) ^0.70

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

use EchoLabs\Prism\ValueObjects\Messages\UserMessage;
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
AI::stream(new SummarizeTask($text), function (string $chunk) {
    echo $chunk;
});

// Перевизначити драйвер на льоту
$response = AI::send(new SummarizeTask($text), drivers: 'anthropic');
```

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

## Artisan команди

| Команда | Опис |
|---|---|
| `ai:make-task Name` | Згенерувати клас таску |
| `ai:request "prompt"` | Ad-hoc запит (sync або queue) |
| `ai:runs` | Список останніх ai_runs |
| `ai:budget {tenant}` | Витрати vs ліміт за поточний місяць |
| `ai:retry` | Список failed-записів для повторного запуску |

## Підтримувані провайдери

Через Prism — додай `api_key` + `model` до `config/ai.php` і зареєструй у `AiManager`:

| Провайдер | Ключ драйвера |
|---|---|
| OpenAI | `openai` |
| Anthropic | `anthropic` |
| Google Gemini | `gemini` |
| Ollama (локально) | `ollama` |
| Mistral | `mistral` |

## Changelog

Дивись [CHANGELOG](CHANGELOG.md).

## Ліцензія

MIT — дивись [LICENSE](LICENSE.md).
