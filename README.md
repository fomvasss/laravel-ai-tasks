
# Laravel Ai Tasks

[![License](https://img.shields.io/packagist/l/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Build Status](https://img.shields.io/github/stars/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://github.com/fomvasss/laravel-ai-tasks)
[![Latest Stable Version](https://img.shields.io/packagist/v/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Total Downloads](https://img.shields.io/packagist/dt/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://packagist.org/packages/fomvasss/laravel-ai-tasks)
[![Quality Score](https://img.shields.io/scrutinizer/g/fomvasss/laravel-ai-tasks.svg?style=for-the-badge)](https://scrutinizer-ci.com/g/fomvasss/laravel-ai-tasks)

Orchestrator AI-tasks for Laravel: drivers, routing, queue, ai_runs, post-processing.

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
QUEUE_CONNECTION=redis

OPENAI_API_KEY=...
GEMINI_API_KEY=...
```

## Usage

```php
<?php

use Fomvasss\AiTasks\Facades\AI;
use Fomvasss\AiTasks\Tasks\GenerateProductDescription;

// sync
$result = AI::send(new GenerateProductDescription((object)[
  'title' => 'Samsung S25 Plus',
  'features' => ['Mint', 'USB-C', '512GB'],
]));

// async
AI::queue(new GenerateProductDescription((object)['title'=>'MacBook Pro','features'=>[]]));
```
To perform async tasks and process webhooks, queues with names as specified in the configuration file must be launched `ai.php` section `queues`.

### Usage queue in tasks

```php
<?php

class GenerateProductDescription extends AiTask implements ShouldQueueAi {

  use QueueableAi;

  public function viaQueues(): array { return ['request'=>'ai:low','postprocess'=>'ai:post']; }
}

```

### Configure routings

```php
'routing' => [
  'product_description' => ['gemini','openai'],
]
```

### Task generator

Make new task:

```bash
php artisan ai:make-task GenerateSeoMeta --queued --modality=text --namespace=App\\Ai\\Tasks
```

Params:
```
    name — base name (sufix Task added automaticly).
    --queued — added ShouldQueueAi + QueueableAi and method viaQueues().
    --modality= — text|chat|image|vision|embed (default text).
    --namespace=App\\Ai\\Tasks — place file (default: App\Ai\Tasks).
    --force — rewrite the file if it exists.
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [fomvasss](https://github.com/fomvasss)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
