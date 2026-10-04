<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Support;

use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\FailoverableException;

final class Failover
{
    /**
     * Чи має сенс пробувати наступний драйвер ланцюжка після цієї помилки.
     *
     * Ні — лише коли провайдер відхилив сам запит (4xx: невалідна схема, задовгий контекст,
     * поганий ключ моделі): інший провайдер отримає той самий запит. laravel/ai перетворює
     * транзиєнтні статуси (429, 402, 502/503/504, обрив з'єднання) на FailoverableException,
     * а 500 і решту 4xx прокидає сирим RequestException — тож 500 тут теж вважається
     * транзиєнтним. Невідомі помилки — так само, як було до розрізнення: пробуємо далі.
     */
    public static function shouldTryNext(\Throwable $e): bool
    {
        if ($e instanceof FailoverableException) {
            return true;
        }

        if ($e instanceof RequestException && $e->response !== null) {
            $status = $e->response->status();

            return ! ($status >= 400 && $status < 500 && ! in_array($status, [408, 429], true));
        }

        return true;
    }
}
