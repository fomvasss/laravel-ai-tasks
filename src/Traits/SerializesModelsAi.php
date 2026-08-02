<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Traits;

use Illuminate\Queue\SerializesAndRestoresModelIdentifiers;

/**
 * Opt-in AiTask::serializeForQueue()/fromQueueArgs() implementation that lets a task's
 * constructor accept Eloquent models directly — the same swap Laravel's own ShouldQueue
 * jobs/Notifications/Mailables perform via Illuminate\Queue\SerializesModels, reused here
 * instead of every task hand-rolling "pass the id, reload the model" boilerplate.
 *
 * Reflects over the constructor's promoted properties (in declaration order). Any value
 * that is an Eloquent model or model collection is swapped for an Illuminate\Contracts\
 * Database\ModelIdentifier (class + id [+ connection], no attributes) — JSON-safe and
 * stable for idempotencyKey() hashing / the ai_runs audit log, unlike embedding the
 * model's full (mutable) attributes would be. Everything else passes through unchanged.
 * fromQueueArgs() reverses the swap by re-querying the model — so the worker always gets
 * a fresh instance, never a stale snapshot of what the model looked like at dispatch time.
 *
 * Requires every constructor parameter to be a promoted property (`private readonly Foo
 * $foo`) — a plain, unpromoted parameter has nowhere to read the value back from.
 *
 * A constructor parameter holding a plain PHP array of models (not an Eloquent
 * collection) is NOT swapped — same limitation as Laravel's own SerializesModels. Use an
 * Eloquent collection (e.g. Model::query()->get()) instead of an array when queuing more
 * than one model.
 */
trait SerializesModelsAi
{
    use SerializesAndRestoresModelIdentifiers;

    public function serializeForQueue(): array
    {
        return array_map(
            fn (mixed $value): mixed => $this->getSerializedPropertyValue($value),
            $this->constructorArgValues(),
        );
    }

    public static function fromQueueArgs(array $args): static
    {
        $restorer = new class {
            use SerializesAndRestoresModelIdentifiers;

            public function restore(mixed $value): mixed
            {
                return $this->getRestoredPropertyValue($value);
            }
        };

        return new static(...array_map($restorer->restore(...), $args));
    }

    private function constructorArgValues(): array
    {
        $constructor = (new \ReflectionClass($this))->getConstructor();

        if (! $constructor) {
            return [];
        }

        return array_map(function (\ReflectionParameter $param): mixed {
            $name = $param->getName();

            if (! property_exists($this, $name)) {
                throw new \LogicException(
                    static::class . '::__construct() has a non-promoted parameter $' . $name . ' — ' .
                    'SerializesModelsAi requires every constructor parameter to be a promoted ' .
                    'property (e.g. `private readonly Foo $foo`).'
                );
            }

            $property = new \ReflectionProperty($this, $name);
            $property->setAccessible(true);

            return $property->getValue($this);
        }, $constructor->getParameters());
    }
}
