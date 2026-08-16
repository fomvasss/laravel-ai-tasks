<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Support;

use Illuminate\Contracts\Database\ModelIdentifier;

/**
 * Restores task ctor args that went through ai_runs.request JSON — SerializesModelsAi
 * stores Eloquent models as ModelIdentifier instances, which json-decode back to plain
 * arrays and would no longer be recognized by fromQueueArgs(). Used by ai:retry and
 * the webhook controller when reconstructing a task from a stored run.
 */
class QueueArgs
{
    public static function revive(array $args): array
    {
        return array_map(function (mixed $arg): mixed {
            if (is_array($arg) && isset($arg['class'], $arg['id']) && array_key_exists('relations', $arg)) {
                return new ModelIdentifier(
                    $arg['class'],
                    $arg['id'],
                    $arg['relations'] ?? [],
                    $arg['connection'] ?? null,
                );
            }

            return $arg;
        }, $args);
    }
}
