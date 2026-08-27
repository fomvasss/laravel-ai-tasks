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

> **Безпека:** дефолтний `middleware => ['web']` лишає дашборд відкритим для будь-кого, хто має доступ до URL — включно зі збереженими промптами і відповідями. У production додай свій auth middleware: `['web', 'auth']`, або напр. `['web', 'auth', 'role:admin']` зі spatie/laravel-permission.

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

За замовчуванням використовуються дві черги, розділені за навантаженням, щоб сплеск повільних викликів провайдера не блокував швидку постобробку позаду себе:

- `ai` — `ProcessAiPayload`, власне виклик провайдера. Повільний (секунди), тому потрібно більше процесів і довгий `timeout`
- `ai-post` — `PostprocessAiResult`, виконує `postprocess()`/`isAcceptable()` і диспетчить ретраї/подію завершення. Швидкий і легкий, тому вистачає пари процесів і короткого `timeout`

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

use App\Models\Article;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Messages\UserMessage;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Tasks\AiTask;
use Fomvasss\AiTasks\Contracts\ShouldQueueAi;
use Fomvasss\AiTasks\Traits\SerializesModelsAi;

class SummarizeTask extends AiTask implements ShouldQueueAi
{
    use SerializesModelsAi;

    public function __construct(
        private readonly Article $article,
    ) {}

    public function modality(): string
    {
        return 'text';
    }

    public function toPayload(): AiPayload
    {
        return new AiPayload(
            modality: $this->modality(),
            messages: [new UserMessage("Стисни текст: {$this->article->body}")],
            systemPrompt: 'Ти помічник-редактор. Відповідай максимум 3 реченнями.',
            options: ['temperature' => 0.3],
        );
    }

    public function schema(): ?\Closure
    {
        return fn (JsonSchema $schema): array => [
            'summary' => $schema->string(),
        ];
    }

    public function postprocess(AiResponse $response): array
    {
        // приводимо сиру відповідь до свого формату — виконується на кожній спробі,
        // включно з тими, що потім відхилить isAcceptable(), тому без побічних ефектів
        return ['summary' => $response->structured['summary'] ?? ''];
    }

    public function onCompleted(AiResponse|array $result, bool $attemptsExhausted): void
    {
        // викликається рівно один раз, тільки для фінального результату — сюди побічні ефекти
        $this->article->update(['summary' => $result['summary'] ?? null]);
    }
}
```

`private readonly Article $article` — звичайна Eloquent-модель, не id — працює завдяки `use SerializesModelsAi;` (його додає сам `ai:make-task`): бере на себе `serializeForQueue()`/`fromQueueArgs()`, відновлюючи свіжий `$article` на воркері для кожного queue-запуску. `schema()` гарантує, що провайдер відповість точно `{"summary": "..."}`, розпарсене в `AiResponse::$structured` — див. [Структурований вивід](#структурований-вивід-schema) нижче. `postprocess()` формує відповідь; `onCompleted()` діє на фінальний результат. Детальніше — [Хук `onCompleted()`](#хук-oncompleted) нижче (гарантія одного виклику, після вирішення ретраїв, ізоляція від пайплайна при винятку).

## Запуск задач

```php
use Fomvasss\AiTasks\Facades\AI;

// Синхронно
$response = AI::send(new SummarizeTask($article));
echo $response->content;

// Асинхронно (черга)
$runId = AI::queue(new SummarizeTask($article));

// Стрімінг
$response = AI::stream(new SummarizeTask($article), function (string $chunk) {
    echo $chunk;
});
// $response->content — повний накопичений текст
// $response->usage  — токени + вартість (як у AI::send)

// Перевизначити драйвер на льоту
$response = AI::send(new SummarizeTask($article), drivers: 'anthropic');
```

### Швидкі промпти

Для одноразового виклику, під який не варто заводити окремий клас `AiTask`, є `AI::prompt()`. Він так само
йде через `send()` — routing, перевірка бюджету і трекінг `AiRun` працюють як завжди:

```php
$response = AI::prompt('Як справи?');
echo $response->content;

// Із system prompt, конкретним драйвером і власною назвою для дашборду/routing
$response = AI::prompt(
    prompt: 'Summarize this in one sentence: ...',
    system: 'You are a terse assistant.',
    drivers: 'anthropic',
    name: 'quick_summary',
);
```

Без `name` усі виклики групуються в дашборді під назвою `prompt`. Якщо задача перевикористовується,
ставиться в чергу, або потребує `postprocess()`/`schema()`/`tools()` — пиши окремий `AiTask`.

## Стрімінг

`AI::stream()` передає текст відповіді чанками через callback — зручно для real-time UI (SSE, WebSockets).

```php
$response = AI::stream(
    new SummarizeTask($article),
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
AI::send((new SummarizeTask($article))->viaDrivers('gemini'));
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

> **Безпека:** дефолтний резолвер довіряє клієнтському заголовку `X-Tenant-Id` — будь-який клієнт може списувати бюджет іншого тенанта (або обійти власний ліміт), просто підставивши заголовок. Якщо бюджети важливі і заголовок не виставляється виключно довіреною інфраструктурою — збіндь резолвер, що визначає tenant з авторизованого користувача, а не з заголовка.

Власний `TenantResolver` має доступ лише до поточного запиту/auth-стану — нічого специфічного для конкретного таску. Якщо таск і так уже знає свій tenant (напр. тримає Eloquent-модель з `organization_id`) — перевизнач `tenantId()` прямо на таску, без жодного біндингу в service provider, і він матиме пріоритет над `TenantResolver`:

```php
protected function tenantId(): ?string
{
    return $this->order->organization_id; // null — фолбек на TenantResolver, як і раніше
}
```

У парі з `subjectType()`/`subjectId()` можна позначити run конкретним записом, якого він стосується (напр. `'order'` / `$this->order->id`) — незалежно від `tenantId()`, суто для фільтрації `ai_runs` по subject, а не лише по tenant/task:

```php
protected function subjectType(): ?string { return 'order'; }
protected function subjectId(): ?string { return $this->order->id; }
```

`Fomvasss\AiTasks\Exceptions\BudgetExceededException` кидається, коли місячна витрата тенанта перевищує `monthly_usd` — перевірка йде і pre-flight (до звернення до провайдера, за вже витраченим), і post-call (після відповіді, за реальною ціною виклику) на `send()`, `stream()` і в чергового job. Якщо виняток стався post-call — виклик уже відбувся й оплачений: run записується як `error`, але зберігає реальний `cost` і токени, а підрахунок витрат враховує кожен run із записаним cost незалежно від статусу — тож нічого не зникає з майбутніх перевірок бюджету.

> **Примітка:** перевірки бюджету — орієнтовні, не жорстка гарантія: паралельні jobs проходять pre-flight перевірку проти одного й того самого попереднього spend, тож кілька одночасних запитів разом можуть перевищити ліміт на суму своїх вартостей. Сприймай `monthly_usd` як м'який ліміт з дрейфом максимум у кілька запитів, а не точну білінгову стелю.

## Відстеження витрат

Задай ціни у `config/ai-tasks.php` (за 1M токенів у USD):

```php
'anthropic' => [
    'model' => 'claude-sonnet-5',
    'price' => [
        'in'          => 3.00,
        'out'         => 15.00,
        'cache_write' => 3.75,
        'cache_read'  => 0.30,
    ],
],
```

Вартість розраховується після кожного запиту і зберігається в `ai_runs.cost`. Якщо `price` не задано — `cost` буде `null`, але кількість токенів завжди записується.

`tokens_in` завжди означає **лише вхідні токени за повною ціною** — кешовані йдуть окремо в `cache_read_tokens`/`cache_write_tokens` і ніколи не входять сюди, незалежно від драйвера. Провайдери в цьому розходяться (Anthropic і Bedrock Converse не включають кеш у свій лічильник вхідних, а OpenAI, Gemini, DeepSeek, Groq і OpenAI-сумісні API — включають), як і gateway'ї в `laravel/ai`, тому різниця нормалізується тут. Перекрити для конкретного драйвера, якщо ваша версія `laravel/ai` поводиться інакше:

```php
'deepseek' => [
    'model' => 'deepseek-v4-flash',
    // laravel/ai < 0.11 віддавав prompt-токени DeepSeek разом з кешованими
    'cache_inclusive_prompt_tokens' => true,
    'price' => ['in' => 0.22, 'out' => 0.66, 'cache_read' => 0.007],
],
```

> `mistral` — відома прогалина: `laravel/ai` взагалі не читає його `cached_tokens`, тож кеш там рахується за повною ціною вхідних токенів.

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

Реалізуйте `AiTask::schema(): ?\Closure`, щоб оголосити JSON Schema для відповіді — `SummarizeTask` у [Створенні таску](#створення-таску) вже так робить (`'summary' => $schema->string()`). На відміну від `jsonMode`, схему валідує сам провайдер (нативний structured output на Anthropic, OpenAI та інших через `StructuredAnonymousAgent` пакету `laravel/ai`) — модель фізично не може повернути іншу форму, ніж ви попросили. `AiResponse::$structured` — це вже задекодований масив за вашою схемою, ручний `json_decode()` чи прибирання markdown-обгорток у `postprocess()` більше не потрібні.

`schema()` має пріоритет над `jsonMode`, якщо задано обидва. Працює з `send()` і `queue()` (замикання автоматично обгортається в `Laravel\SerializableClosure\SerializableClosure`, тому переживає серіалізацію job-у), але не застосовується до `stream()`.

### Типи полів і вкладеність

`JsonSchema` підтримує звичні типи полів, а також вкладені об'єкти й опційні поля — реалістична схема (наприклад, відповідь чат-асистента, яка іноді фіксує контактні дані) поєднує їх:

```php
public function schema(): ?\Closure
{
    return fn (JsonSchema $schema): array => [
        'action'     => $schema->string()->enum(['reply', 'escalate_to_human']),
        'confidence' => $schema->number(),
        'urgent'     => $schema->boolean(),
        'contact'    => $schema->object([
            'name'  => $schema->string(),
            'email' => $schema->string(),
        ])->nullable(), // весь об'єкт, або null, якщо звітувати нічого
    ];
}
```

Структурований вивід завжди — top-level **об'єкт**: таск, чий природний результат — список (напр. видобуті ключові слова), має обгорнути його в ключ, а потім розгорнути в `postprocess()`:

```php
public function schema(): ?\Closure
{
    return fn (JsonSchema $schema): array => [
        'keywords' => $schema->array()->items($schema->string()),
    ];
}

public function postprocess(AiResponse $resp): array
{
    return ['keywords' => $resp->structured['keywords'] ?? []];
}
```

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

## Метадані відповіді

`AiResponse` несе ще пару полів окрім `content`/`structured`, обидва переживають round-trip через чергу (зберігаються в `ai_runs.response`, відновлюються для `postprocess()`):

- `AiResponse::$toolCalls` — які тули модель реально викликала для цієї відповіді (`array<int, array{id: string, name: string, arguments: array, ...}>`, по одному запису на `Laravel\Ai\Responses\Data\ToolCall::toArray()`). Порожній, якщо модель жодного тула не викликала.
- `AiResponse::$finishReason` — причина зупинки останнього кроку генерації від `laravel/ai` (`stop`, `length`, `tool_calls`, `content_filter`, `error`, `unknown`). Корисно в `isAcceptable()`, щоб відрізнити дійсно обірвану відповідь (`length`) від інших причин відхилення замість вгадування з порожнього `content`.

`AiResponse::$raw` існує, але наразі завжди порожній — його джерело (`providerContentBlocks`) `laravel/ai` губить ще до того, як воно долітає до `StructuredAgentResponse`, тож для `schema()`-задач воно не заповнюється.

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

## Підтвердження tool-виклику (Tool Approval)

Для тула з `AiTask::tools()`, що робить незворотну чи дорогу дію (оформити замовлення, видалити файл, переказати кошти) — реалізуйте нативний `laravel/ai`'s `Contracts\Approvable` (повний контракт — у документації `laravel/ai`: `requireApproval()`/`withoutApproval()`/`shouldRequestApproval()`). Пакет не додає власного протоколу підтвердження — лише прокидає те, що вже робить `laravel/ai`:

```php
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;

class CreateOrderTool implements Tool, Approvable
{
    use InteractsWithApprovals;

    protected function needsApproval(Request $request): Approval|bool
    {
        return Approval::required('Оформлення реального замовлення потребує підтвердження клієнта.');
    }
}
```

Коли модель намагається викликати `Approvable`-тул, що потребує підтвердження, — виконання зупиняється замість виклику тула: `AiResponse::$pendingApprovals` заповнюється (`id`/`tool`/`arguments`/`reason` на кожен pending-виклик), тул **не** виконується.

Щоб продовжити — задиспатчити той самий таск повторно, задавши `AiPayload::$decisions` замість нового текстового промпту:

```php
public function toPayload(): AiPayload
{
    return new AiPayload(
        modality: 'text',
        messages: $this->history(), // має включати призупинений хід асистента з tool-викликом
        decisions: $this->decisions, // напр. ['call_abc123' => true] — null на першому, пропонуючому виклику
    );
}
```

`decisions` приймає інстанс `Laravel\Ai\Approvals\Decisions` або звичайний масив `['tool_call_id' => true|false|Decision::approve()|Decision::reject('причина')]`.

**Свідомо не побудовано на `RemembersConversations`/`ConversationStore` пакету `laravel/ai`** — пакет вважає, що джерело правди для історії розмови вже є у вашій доменній моделі (чат, лог повідомлень тощо), і `AiPayload::$messages` завжди будується з неї. Друга, окрема таблиця розмов від `laravel/ai` дублювала б це. Це означає, що коректний резюм — ваша відповідальність: білдер історії таска має відтворити призупинений хід асистента як повідомлення зі справжнім tool-викликом, а не лише текстовий підсумок, який бачив клієнт — точну форму дивіться в документації `laravel/ai` про `Approvable`/`PendingApproval`.

**Відтворюйте призупинений хід із `AiResponse::$toolCalls`, не з `$pendingApprovals`.** `$pendingApprovals` — урізаний вигляд для відображення (лише `id`/`tool`/`arguments`/`reason`). `$toolCalls` несе повну форму, яка справді потрібна для реплею — `result_id`, а для reasoning-моделей у провайдерів (напр. OpenAI Responses API з reasoning-моделлю) — ще й `reasoning_id`/`reasoning_summary`/`reasoning_encrypted_content`. Відтворюйте tool-виклик через `Laravel\Ai\Responses\Data\ToolCall::fromArray($entry)`, а не вручну обраними полями — реплей без `result_id` відхиляється одразу (`400: input[N].call_id: expected a string, but got null`):

```php
use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Responses\Data\ToolCall;

// $pendingToolCall — відповідний запис з AiResponse::$toolCalls пропонуючого виклику
$messages[] = new AssistantMessage('', new Collection([
    ToolCall::fromArray($pendingToolCall),
]));
```

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

Реалізуй `ShouldQueueAi` для вибору черги/конекшну; `use SerializesModelsAi;` бере на себе `serializeForQueue()`/`fromQueueArgs()` автоматично:

```php
use Fomvasss\AiTasks\Contracts\ShouldQueueAi;
use Fomvasss\AiTasks\Traits\SerializesModelsAi;

class AnalyzeTask extends AiTask implements ShouldQueueAi
{
    use SerializesModelsAi;

    public function __construct(private readonly int $productId) {}

    public function viaQueues(): array
    {
        return ['request' => 'ai', 'post' => 'ai-post'];
    }
}
```

Стадія `request` — це API-виклик, `post` — job постобробки. Переконайся, що Horizon-supervisors реально споживають кожну чергу, яку ти тут повертаєш — job, відправлений у чергу, яку ніхто не слухає, висітиме там вічно.

> **Примітка при оновленні:** до v3.23.0 стадія `post` ігнорувалась і постобробка завжди йшла в `config('ai-tasks.queues.post')`. Якщо твої задачі вже декларують власну `post`-чергу — після оновлення вона запрацює; спершу додай її в конфіг воркерів.

> **Увага:** `serializeForQueue()` повинен повертати тільки скалярні значення (рядки, числа, масиви скалярів) — цей масив передається назад у конструктор на воркері через `new static(...$args)`. `SummarizeTask` у [Створенні таску](#створення-таску) показує простіший шлях — `use SerializesModelsAi;` дозволяє передати в конструктор Eloquent-модель напряму (promoted-властивість), а не id, який довелось би вручну довантажувати в `toPayload()`. `serializeForQueue()` також керує ідемпотентністю — див. [Ідемпотентність](#ідемпотентність).

### Відкладений запуск (delay)

Передай `delay` у `AI::queue()` щоб відкласти виконання:

```php
AI::queue(new SummarizeTask($article), delay: 300);                 // 5 хвилин (секунди)
AI::queue(new SummarizeTask($article), delay: now()->addHours(2));  // Carbon
AI::queue(new SummarizeTask($article), delay: new \DateInterval('PT10M'));
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

**Дедублікація активна тільки коли `serializeForQueue()` повертає непорожній масив.** Якщо він повертає `[]` (за замовчуванням), `idempotencyKey()` повертає `null` і дедублікація не застосовується — кілька runs з однаковою задачею можуть співіснувати. Тобто: для будь-якої задачі зі змінними вхідними даними реалізація `serializeForQueue()` обов'язкова як для відновлення з черги, так і для коректної роботи idempotency. `AI::queue()` перевіряє це при диспетчеризації — кидає `LogicException` для таска з параметрами конструктора, чий `serializeForQueue()` повертає `[]`.

**Поведінка при колізії (коли ненульовий ключ вже є в `ai_runs`):**
- `AI::queue()` — повертає наявний `run_id`; дублікат job-а не диспатчиться.
- `AI::send()` — завжди робить свіжий API-виклик; `idempotency_key` для sync-runs не зберігається.

Для власної логіки дедублікації важливо, що включає `serializeForQueue()` — дефолтний `idempotencyKey()` просто хешує це як є, перевизначати нічого не треба:

```php
class ChatTask extends AiTask
{
    use SerializesModelsAi;

    public function __construct(
        private readonly string $question,
        private readonly string $messageId, // унікальний ID повідомлення з чат-системи
        private readonly array  $history = [],
    ) {}
}
```

Для чат/асистент-інтеграцій, де одне й те саме питання може задаватись кілька разів: поки `messageId` або повна історія чату — параметр конструктора — кожен turn дає унікальний ключ, і idempotency захищає тільки від технічних дублів (double-send, retry черги).

**Вікно дедублікації.** За замовчуванням унікальний ключ ніколи не спливає — та сама задача з тими самими аргументами не буде диспатчнута вдруге ніколи. Перевизнач `idempotencyWindow()`, щоб обмежити дедублікацію періодом; повернутий рядок стає частиною ключа, тож задача зможе виконатись знову, щойно вікно зміниться:

```php
public function idempotencyWindow(): ?string
{
    return now()->format('Y-m-d'); // максимум один запуск на день для тих самих аргументів
}
```

Задачі з дефолтним `null` не зачіпаються — їхні наявні ключі стабільні між оновленнями.

### Повторна спроба при непридатному результаті

Провайдер може відповісти "успішно" (`ok: true`, без винятку), але результат все одно непридатний — найчастіше reasoning-модель (DeepSeek, Gemini thinking тощо) витрачає весь token-бюджет на внутрішнє reasoning і повертає порожній/пробільний content. Реалізуй `maxRetries()` і `isAcceptable()`, щоб автоматично повторити спробу перш ніж здатись:

```php
class ChatReplyTask extends AiTask implements ShouldQueueAi
{
    // ...

    public function maxRetries(): int
    {
        return 1;
    }

    public function isAcceptable(AiResponse|array $result): bool
    {
        return !empty($result['ok']) && trim(strip_tags($result['message'] ?? '')) !== '';
    }
}
```

`isAcceptable()` отримує те, що повернув `postprocess()`. Більше нічого міняти не треба — ні конструкторного `$attempt`, ні змін у `idempotencyKey()`/`serializeForQueue()`. Всю бухгалтерію ретраю бере на себе `PostprocessAiResult`: сам рахує idempotency-ключ ретраю (`idempotencyKey() . '-retry' . $n`) і диспетчить нову пару `ProcessAiPayload`/`PostprocessAiResult` на тому ж драйвері, що й оригінальний run. Task-клас взагалі не бачить і не тримає власний номер спроби.

Коли спроби вичерпані (або одразу, якщо `maxRetries()` — `0` за замовчуванням), run стає фінальним — перевір `attemptsExhausted` у [`onCompleted()`](#хук-oncompleted) нижче, щоб відрізнити невирішену невдачу від звичайного прийнятого результату, не дублюючи власну перевірку `isAcceptable()`. Працює лише на queue-шляху (`AI::queue()`); `AI::send()`/`AI::stream()` синхронні і завжди фаєряться один раз.

> **Примітка:** номер спроби не зберігається в `ai_runs` — відновити можна тільки з суфікса `idempotency_key` (`...-retry1`, `...-retry2`, ...). Немає колонки `attempt` чи зв'язку run з його ретраями, тому дашборд не показує ланцюжок структуровано.

### Хук `onCompleted()`

Для таска з єдиним споживачем результату перевизнач `onCompleted()` замість окремого listener-а на `AiTaskCompleted`:

```php
class GenerateChatAssistantReplyTask extends AiTask implements ShouldQueueAi
{
    use SerializesModelsAi;

    public function __construct(
        private readonly ChatMessage $message,
    ) {}

    public function onCompleted(AiResponse|array $result, bool $attemptsExhausted): void
    {
        if ($attemptsExhausted) {
            SetManagerAction::run($this->message);
            return;
        }

        // зберегти повідомлення, розіслати подію по вебсокету і т.д.
    }
}
```

Викликається рівно один раз, у тій самій точці, де фаєриться `AiTaskCompleted`, — коли `postprocess()`/`isAcceptable()` вже визначили фінальний результат (прийнятий або вичерпані ретраї). Не викликається на проміжних відхилених спробах ретраю. `$attemptsExhausted` означає те саме, що й `AiTaskCompleted::$attemptsExhausted`.

Виняток усередині `onCompleted()` перехоплюється й логується, фаєриться `AiTaskCompletedHandlerFailed` — це ніколи не ламає внутрішній пайплайн пакета і не блокує `AiTaskCompleted`.

Listener на `AiTaskCompleted` лишається доречним, коли за завершенням одного таска мають незалежно стежити декілька споживачів (напр. один зберігає доменний запис, інший пише в аналітику) — без правок самого таска. Обидва можуть співіснувати: пакет викликає `onCompleted()` і фаєрить подію в той самий момент.

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

## Опції генерації

Для текстових задач `temperature`, `max_tokens` і `top_p` з options `AiPayload` передаються провайдеру (будь-яку можна опустити):

```php
return new AiPayload(
    modality: 'text',
    messages: [new UserMessage($this->text)],
    options: ['temperature' => 0.3, 'max_tokens' => 1024, 'top_p' => 0.9],
);
```

> **Примітка при оновленні:** до v3.23.0 ці опції мовчки ігнорувались. Якщо твої задачі вже декларують `temperature` — після оновлення вихід моделей зміниться, бо значення тепер реально доходить до провайдера.

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
| `AiTaskCompletedHandlerFailed` | `AiTask::onCompleted()` кинув виняток |
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
| `ai:retry` | Повторний запуск failed-записів (`--dry-run` — тільки список) |

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

Поточна модель з конфігу позначається `✓`. Groq, Mistral, DeepSeek, xAI, Ollama і OpenRouter опитуються через OpenAI-сумісний `/v1/models` автоматично — за дефолтним URL провайдера з коробки; `ai.providers.{driver}.url` треба задавати лише щоб перевизначити його (наприклад, свій self-hosted Ollama).

Той самий лістинг доступний і з власного коду — `AI::models()` сам резолвить credentials з `config/ai.php`, так само як `send()`/`queue()`/`stream()`:

```php
use Fomvasss\AiTasks\Facades\AI;

$models = AI::models('openai', filter: 'gpt');
// [['id' => 'gpt-5.6-luna', 'display_name' => null, 'owner' => 'system', 'created' => '2026-06-23', ...], ...]
```

Кидає `Fomvasss\AiTasks\Exceptions\AiDriverException`, якщо в драйвера немає `api_key` в `config/ai.php`, `ModelListingUnavailableException` — якщо немає ендпоінту для лістингу, або `ModelListingException` — при помилці з'єднання/API.

Сама логіка запиту живе в `Fomvasss\AiTasks\Support\ModelLister` (її й використовує `AI::models()` всередині) — інжектуй або створюй напряму, якщо credentials вже маєш під рукою і хочеш пропустити lookup у конфігах:

```php
use Fomvasss\AiTasks\Support\ModelLister;

$models = app(ModelLister::class)->forDriver('openai', ['api_key' => config('ai.providers.openai.key')], filter: 'gpt');
```

### ai:retry

Повторно диспатчить runs зі статусом `error` або `dead`, відновлюючи таск з `task_class`/`task_args`, збережених в `ai_runs.request`, і ставлячи `ProcessAiPayload` для того ж run у чергу знову (статус скидається в `queued`):

```bash
php artisan ai:retry                 # повторити падіння за останні 24 год
php artisan ai:retry --since=1h --limit=10
php artisan ai:retry --dry-run       # показати, що було б повторено, нічого не змінюючи
```

Аргументи конструктора таску зберігаються лише при `AI_STORE_REQUEST=true` (вони зазвичай містять текст промпта) — runs без них показуються як skipped. Таски, чий конструктор не має обов'язкових параметрів, повторюються завжди.

## Асинхронні провайдери та вебхуки

Для провайдерів, що завершують роботу поза запитом (batch API, довгі генерації), run може чекати вебхук замість блокування воркера. Флоу керується застосунком:

1. Твій код драйвера/таску відправляє задачу провайдеру, потім паркує run:

```php
$run->markWaiting(['provider_run_id' => $providerJobId]);
```

2. Провайдер викликає `POST /ai-webhooks/{driver}`. Вбудований обробник (зареєстрований для `openai`; власний додається через `WebhookRegistry::extend()`) верифікує підпис, коли задано `ai-tasks.drivers.{driver}.webhook.secret`, знаходить waiting-run за `provider_run_id` і завершує його.

3. Якщо таск run-а можна відновити (його `task_class` зберігається автоматично; аргументи конструктора потребують `AI_STORE_REQUEST=true`, або таск без обов'язкових параметрів конструктора) — диспатчиться job постобробки, і `postprocess()`, `isAcceptable()`/повтори та `onCompleted()` виконуються так само, як на звичайному queue-шляху. Інакше run завершується як є, а в лог пишеться warning.

Обробник для OpenAI верифікує підписи [Standard Webhooks](https://www.standardwebhooks.com/) (заголовки `webhook-id`/`webhook-timestamp`/`webhook-signature`, HMAC-SHA256, replay-допуск ±5 хв) за секретом `whsec_...` з налаштувань вебхуків у кабінеті OpenAI — задай його як `OPENAI_WEBHOOK_SECRET`. `Fomvasss\AiTasks\Support\StandardWebhookVerifier::verify()` можна перевикористати у власному обробнику з тією ж схемою.

> **Безпека:** без налаштованого `webhook.secret` ендпоінт приймає непідписані запити. У production задай секрет. Якщо реєструєш обробник для провайдера, що не використовує Standard Webhooks — верифікуй за схемою того провайдера всередині свого `WebhookRegistry::extend()` closure.

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
| OpenRouter | `openrouter` | ✅ |
| VoyageAI | `voyageai` | додати вручну |
| AWS Bedrock | `bedrock` | додати вручну |
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
