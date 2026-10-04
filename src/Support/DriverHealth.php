<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Support;

use Fomvasss\AiTasks\DTO\AiPayload;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Поточний стан драйверів для дашборда: збої поспіль, остання помилка, остання успішна відповідь.
 *
 * Окремо від ai_runs, бо з 3.30 черговий прогін, що перейшов на запасний драйвер, лишає в ai_runs
 * лише той драйвер, що відповів, — збій першого там не видно. Живе в Cache (Octane-safe, спільний
 * для серверів при Redis); губиться разом із кешем — це стан "зараз", не історія.
 *
 * Збій — лише транзиєнтна помилка провайдера (Failover::shouldTryNext()): відхилений запит (4xx)
 * говорить про запит, не про провайдера. Прогони зі своїм ключем (providerOverride) не враховуються:
 * вони йдуть в інший акаунт/API, і їхні збої не означають, що лежить спільний драйвер.
 */
final class DriverHealth
{
    public const DOWN_AFTER = 3;

    private const TTL_SECONDS = 7 * 24 * 3600;

    public static function recordSuccess(string $driver, AiPayload $payload): void
    {
        if (self::isOwnKey($payload)) {
            return;
        }

        self::update($driver, fn (array $s): array => [
            ...$s,
            'failures' => 0,
            'last_ok_at' => now()->toIso8601String(),
        ]);
    }

    public static function recordFailure(string $driver, AiPayload $payload, \Throwable $e): void
    {
        if (self::isOwnKey($payload) || ! Failover::shouldTryNext($e)) {
            return;
        }

        self::update($driver, fn (array $s): array => [
            ...$s,
            'failures' => $s['failures'] + 1,
            'last_error' => mb_substr($e->getMessage(), 0, 300),
            'last_error_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array{failures: int, last_ok_at: ?string, last_error: ?string, last_error_at: ?string, status: string}
     */
    public static function get(string $driver): array
    {
        $s = self::read($driver);

        $s['status'] = match (true) {
            $s['failures'] >= self::DOWN_AFTER => 'down',
            $s['failures'] > 0 => 'degraded',
            $s['last_ok_at'] !== null => 'ok',
            default => 'unknown',
        };

        return $s;
    }

    public static function forget(string $driver): void
    {
        Cache::forget(self::key($driver));
    }

    private static function isOwnKey(AiPayload $payload): bool
    {
        return (bool) ($payload->providerOverride['key'] ?? null);
    }

    private static function update(string $driver, \Closure $change): void
    {
        // Облік стану не має права зламати сам виклик AI — навіть якщо кеш недоступний.
        try {
            Cache::put(self::key($driver), $change(self::read($driver)), self::TTL_SECONDS);
        } catch (\Throwable $e) {
            Log::warning('ai-tasks: driver health not recorded', ['driver' => $driver, 'error' => $e->getMessage()]);
        }
    }

    private static function read(string $driver): array
    {
        return array_merge(
            ['failures' => 0, 'last_ok_at' => null, 'last_error' => null, 'last_error_at' => null],
            (array) Cache::get(self::key($driver), []),
        );
    }

    private static function key(string $driver): string
    {
        return 'ai-tasks:driver-health:' . $driver;
    }
}
