<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Support;

use Fomvasss\AiTasks\Jobs\ProcessAiPayload;

/**
 * Setup problems that fail silently in production, reported by `php artisan about`.
 */
final class Diagnostics
{
    /**
     * Sections of the config that hold the application's own lists rather than package settings —
     * their keys are compared, their contents are not (an app trims drivers on purpose).
     */
    private const OWN_LISTS = ['drivers', 'routing', 'budgets', 'postprocess.pipes', 'dashboard.middleware', 'webhook_middleware'];

    /**
     * Keys the published config/ai-tasks.php lacks compared with the package's, and keys the package
     * no longer has. `composer update` never touches a published config, so new settings stay
     * invisible until someone diffs it by hand.
     *
     * @return array{missing: list<string>, unknown: list<string>}|null null when the config is not published
     */
    public static function configDrift(?string $publishedPath = null): ?array
    {
        $publishedPath ??= config_path('ai-tasks.php');

        if (! is_file($publishedPath)) {
            return null;
        }

        $package = self::keys(require __DIR__ . '/../../config/ai-tasks.php');
        $published = self::keys(require $publishedPath);

        return [
            'missing' => array_values(array_diff($package, $published)),
            'unknown' => array_values(array_diff($published, $package)),
        ];
    }

    /**
     * The queue connection the `ai` queue is worked on, and its retry_after. Redis and database queues
     * hand a reserved job out again after retry_after — when that is not above the job timeout, a slow
     * provider call is executed twice. The connection is the Horizon supervisor's one when Horizon
     * works the queue (the worker's connection decides), otherwise the default connection.
     *
     * @return array{queue: string, connection: string, retry_after: ?int, too_short: bool}
     */
    public static function aiQueue(): array
    {
        $queue = (string) config('ai-tasks.queues.default', 'ai');
        $connection = self::horizonConnectionFor($queue) ?? (string) config('queue.default');
        $config = (array) config("queue.connections.{$connection}", []);
        $retryAfter = isset($config['retry_after']) ? (int) $config['retry_after'] : null;

        return [
            'queue' => $queue,
            'connection' => $connection,
            'retry_after' => $retryAfter,
            'too_short' => in_array($config['driver'] ?? null, ['redis', 'database'], true)
                && ($retryAfter ?? 60) <= (new \ReflectionProperty(ProcessAiPayload::class, 'timeout'))->getDefaultValue(),
        ];
    }

    private static function horizonConnectionFor(string $queue): ?string
    {
        $supervisors = config('horizon.environments.' . app()->environment()) ?? config('horizon.environments.*');

        if (! is_array($supervisors)) {
            return null;
        }

        foreach ($supervisors as $name => $options) {
            $supervisor = array_replace((array) config("horizon.defaults.{$name}", []), (array) $options);

            if (in_array($queue, (array) ($supervisor['queue'] ?? []), true)) {
                return $supervisor['connection'] ?? null;
            }
        }

        return null;
    }

    /** @return list<string> dot paths of all keys, not descending into lists and OWN_LISTS */
    private static function keys(array $config, string $prefix = ''): array
    {
        $keys = [];

        foreach ($config as $key => $value) {
            $path = $prefix . $key;
            $keys[] = $path;

            if (is_array($value) && $value !== [] && ! array_is_list($value) && ! in_array($path, self::OWN_LISTS, true)) {
                array_push($keys, ...self::keys($value, $path . '.'));
            }
        }

        return $keys;
    }
}
