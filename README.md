
# Laravel Ai Tasks

[![License](https://img.shields.io/packagist/l/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Build Status](https://img.shields.io/github/stars/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://github.com/fomvasss/laravel-ai-tasks)
[![Latest Stable Version](https://img.shields.io/packagist/v/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Total Downloads](https://img.shields.io/packagist/dt/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Quality Score](https://img.shields.io/scrutinizer/g/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://scrutinizer-ci.com/g/fomvasss/laravel-ai-tasks)

Orchestrator AI-tasks for Laravel: drivers, routing, webhooks, queue, ai_runs, post-processing.

## Installation

Install the package via composer:

```bash
composer require fomvasss/laravel-ai-tasks
```

Publish and run the migrations with:

```bash
php artisan vendor:publish --provider="Fomvasss\AiTasks\AiServiceProvider" --tag=config
php artisan vendor:publish --provider="Fomvasss\AiTasks\AiServiceProvider" --tag=migrations
php artisan migrate
```

Example `.env`:
```

OPENAI_API_KEY=...
GEMINI_API_KEY=...
```

### Supervisor/Queue configuration
Add the following queues to your queue worker command (or use Horizon as below):

```bash
php artisan queue:work --queue=ai:webhook,ai:high,ai:default,ai:low,ai:post,ai:webhook
``` 

### Horizon configuration
If you are using Laravel Horizon, you can configure the queues in `config/horizon.php`:

<details>
  <summary>See details:</summary>

```php
'environments' => [
    '*' => [
        // Prioryty online dialogues/streams (min. latency)
        'supervisor-ai-high' => [
            'connection'   => 'redis',
            'queue'        => ['ai:high'],
            'balance'      => 'auto',     // auto|simple|false
            'minProcesses' => 2,
            'maxProcesses' => 24,
            'tries'        => 1,
            'timeout'      => 40,         // seconds
            'nice'         => 0,
        ],

        // Typical text generations
        'supervisor-ai-default' => [
            'connection'   => 'redis',
            'queue'        => ['ai:default'],
            'balance'      => 'auto',
            'minProcesses' => 2,
            'maxProcesses' => 32,
            'tries'        => 3,
            'timeout'      => 120,
            'nice'         => 5,
        ],

        // Mass/slow tasks (catalogs, images)
        'supervisor-ai-low' => [
            'connection'   => 'redis',
            'queue'        => ['ai:low'],
            'balance'      => 'simple',
            'minProcesses' => 1,
            'maxProcesses' => 24,
            'tries'        => 2,
            'timeout'      => 300,
            'nice'         => 10,
        ],

        // Postprocessing tasks (validation JSON, conversions, saving)
        'supervisor-ai-post' => [
            'connection'   => 'redis',
            'queue'        => ['ai:post'],
            'balance'      => 'simple',
            'minProcesses' => 1,
            'maxProcesses' => 12,
            'tries'        => 2,
            'timeout'      => 300,
            'nice'         => 5,
        ],

        // Webhook from providers (short, reactive)
        'supervisor-ai-webhook' => [
            'connection'   => 'redis',
            'queue'        => ['ai:webhook'],
            'balance'      => 'simple',
            'minProcesses' => 1,
            'maxProcesses' => 8,
            'tries'        => 1,
            'timeout'      => 30,
        ],
    ],
]
```
</details>


## Usage

### Make Task
Use command to create new task:

```bash
php artisan ai:make-task SomeInterestingTask --modality=text
````

This will create new task class in `app/Ai/Tasks/SomeInterestingTask.php`.

### Configure Task

<details>
  <summary>Edit the created task class to configure it:</summary>

```php
<?php

namespace App\Ai\Tasks;

use Fomvasss\AiTasks\Contracts\QueueSerializableAi;
use Fomvasss\AiTasks\Contracts\ShouldQueueAi;
use Fomvasss\AiTasks\Tasks\AiTask;
use Fomvasss\AiTasks\DTO\AiPayload;
use Fomvasss\AiTasks\DTO\AiResponse;
use Fomvasss\AiTasks\Support\Prompt;
use Fomvasss\AiTasks\Support\Schema;

class SomeInterestingTask extends AiTask 
{
    public function name(): string
    {
        return 'some_interesting';
    }

    public function modality(): string
    {
        return 'text'; // text|chat|image|vision|embed
    }

    public function toPayload(): AiPayload
    {
        // TODO add your payload generation logic here        
        return new AiPayload(
            modality: $this->modality(),
            messages: [['role' => 'system', 'content' => 'You are a web programmer\'s assistant.'], [ 'role' => 'user', 'content' => 'Tell me something interesting.']],
            options:  ['temperature' => 0.3, 'model' => 'gpt-4o'], // model options
        );
    }

    public function postprocess(AiResponse $resp): array|AiResponse
    {
        // TODO add your post-processing logic here
        // Post-processing of responses (can be stored in a database/storage or other your own mechanism)
        // If you expect JSON — parse it and return an array
        return $resp;
    }
    
}

```
</details>

### Run Task
```php
<?php

use Fomvasss\AiTasks\Facades\AI;

// 1) Sync
$result = AI::send(new \App\Ai\Tasks\SomeInterestingTask());

// 2) Async
AI::queue(new \App\Ai\Tasks\SomeInterestingTask(), drivers: 'openai');

// 3) Direct driver usage
$payload = new \Fomvasss\AiTasks\DTO\AiPayload(
    modality: 'text',
    messages: [[ 'role'=>'user','content'=> 'Tell me something interesting' ]],
    options: ['temperature' => 0.3],
);
$context = new \Fomvasss\AiTasks\DTO\AiContext(
    tenantId:  '123456',
    taskName: 'interesting_task'
);
$result = app(\Fomvasss\AiTasks\Core\AiManager::class)->driver('gemini')
    ->send($payload, $context);
```


To perform async tasks and process webhooks, queues with names as specified in the configuration file must be launched `ai.php` section `queues`.

## Commands
The package provides several Artisan commands to manage AI tasks:
- `ai:budget` — show tenant budget vs spent
- `ai:runs` — list recent ai_runs
- `ai:retry` — demo retry of failed runs
- `ai:make-task` — generate a new Ai task class
- `ai:request` — ad-hoc AI request (sync or queued)

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [fomvasss](https://github.com/fomvasss)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
