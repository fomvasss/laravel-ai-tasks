<?php

namespace Fomvasss\AiTasks\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use JsonSerializable;
use ReflectionClass;

class QueueSerializer
{
    private const UNRESOLVED = '__UNRESOLVED__';

    /**
     * Серіалізує аргументи конструктора таска (позиційно) без вимог до serializeForQueue().
     * Порядок відповідає порядку параметрів конструктора.
     *
     * Алгоритм:
     *  - якщо є serializeForQueue() — використовуємо його (беккомпат);
     *  - знімаємо всі властивості: через Reflection, get_object_vars(), (array)$obj;
     *  - для кожного параметра конструктора шукаємо значення за точним іменем, camel/snake,
     *    а також через геттер getX()/x();
     *  - якщо значення не знайдено — беремо дефолт або кидаємо зрозумілий виняток із діагностикою.
     *
     * @throws \RuntimeException
     */
    public static function serializeTask(object $task): array
    {
        if (method_exists($task, 'serializeForQueue')) {
            return $task->serializeForQueue();
        }

        $rc = new ReflectionClass($task);
        $ctor = $rc->getConstructor();
        if (! $ctor) {
            return [];
        }

        // 1) Забираємо ВСІ властивості різними способами
        $propVals = self::collectAllProps($task, $rc);

        $args = [];
        $unresolved = [];

        foreach ($ctor->getParameters() as $param) {
            $name = $param->getName();

            // пряме попадання по імені
            if (array_key_exists($name, $propVals)) {
                $args[] = self::normalize($propVals[$name]);
                continue;
            }

            // camel/snake/_ + геттери
            $val = self::tryResolveParamValueFromMap($task, $rc, $propVals, $name);
            if ($val !== self::UNRESOLVED) {
                $args[] = self::normalize($val);
                continue;
            }

            // значення за замовчуванням
            if ($param->isDefaultValueAvailable()) {
                $args[] = self::normalize($param->getDefaultValue());
                continue;
            }

            // не змогли — позначимо для діагностики
            $unresolved[] = '$'.$name;
        }

        if ($unresolved) {
            $diag = self::buildDiagnostic($task, $rc, $propVals);
            throw new \RuntimeException(
                "QueueSerializer: cannot resolve constructor parameters (".implode(', ', $unresolved).") ".
                "for ".get_class($task).". Make them promoted properties with the same names, or implement serializeForQueue(). ".
                $diag
            );
        }

        return $args;
    }

    /**
     * Інстанціює таск за позиційним масивом аргументів з денормалізацією типів.
     */
    public static function instantiate(string $taskClass, array $ctorArgs): object
    {
        $ctorArgs = array_map([self::class, 'denormalize'], $ctorArgs);
        return new $taskClass(...$ctorArgs);
    }

    /** ---------- helpers ---------- */

    /**
     * Збір значень ВСІХ властивостей:
     * - Reflection: public/protected/private (включно з promoted);
     * - get_object_vars(): публічні (зручні для promoted public);
     * - (array)$obj: приватні/захищені (з префіксами), беремо по суфіксу.
     */
    private static function collectAllProps(object $task, ReflectionClass $rc): array
    {
        $out = [];

        // A) Reflection
        foreach ($rc->getProperties() as $prop) {
            $prop->setAccessible(true);
            $name = $prop->getName();
            $out[$name] = $prop->isInitialized($task) ? $prop->getValue($task) : null;
        }

        // B) get_object_vars (public only)
        foreach (get_object_vars($task) as $name => $val) {
            $out[$name] = $val;
        }

        // C) (array)$obj — приватні/захищені як "\0Class\0prop" або "\0*\0prop"
        foreach ((array)$task as $k => $v) {
            // шукаємо суфікс властивості після останнього \0
            $pos = strrpos($k, "\0");
            $name = $pos === false ? $k : substr($k, $pos + 1);

            if (!array_key_exists($name, $out)) {
                $out[$name] = $v;
            }
        }

        return $out;
    }

    private static function tryResolveParamValueFromMap(object $task, \ReflectionClass $rc, array $propVals, string $name): mixed
    {
        $alts = [
            $name,
            Str::camel($name),
            Str::snake($name),
            '_'.$name,
        ];

        foreach ($alts as $alt) {
            if (array_key_exists($alt, $propVals)) {
                return $propVals[$alt];
            }
        }

        $getter = 'get'.Str::studly($name);
        if ($rc->hasMethod($getter)) {
            $m = $rc->getMethod($getter); $m->setAccessible(true);
            if ($m->getNumberOfRequiredParameters() === 0) {
                return $m->invoke($task);
            }
        }

        if ($rc->hasMethod($name)) {
            $m = $rc->getMethod($name); $m->setAccessible(true);
            if ($m->getNumberOfRequiredParameters() === 0) {
                return $m->invoke($task);
            }
        }

        return self::UNRESOLVED;
    }

    private static function buildDiagnostic(object $task, \ReflectionClass $rc, array $propVals): string
    {
        try {
            $ctor = $rc->getConstructor();
            $params = $ctor ? array_map(fn($p)=>'$'.$p->getName(), $ctor->getParameters()) : [];
            $props  = array_keys($propVals);

            return '[diagnostic ctor='.implode(', ', $params).
                ' | props='.implode(', ', $props).']';
        } catch (\Throwable $e) {
            return '[diagnostic unavailable: '.$e->getMessage().']';
        }
    }

    private static function normalize(mixed $val): mixed
    {
        if ($val instanceof Model) {
            return ['__model' => get_class($val), 'id' => $val->getKey()];
        }
        if ($val instanceof \DateTimeInterface) {
            return ['__date' => $val->format(\DateTimeInterface::ATOM)];
        }
        if ($val instanceof \BackedEnum) {
            return ['__enum' => get_class($val), 'value' => $val->value];
        }
        if ($val instanceof Arrayable) {
            return ['__arrayable' => get_class($val), 'data' => $val->toArray()];
        }
        if ($val instanceof JsonSerializable) {
            return ['__json' => get_class($val), 'data' => $val->jsonSerialize()];
        }
        if (is_resource($val) || $val instanceof \Closure) {
            return ['__unsupported' => get_debug_type($val)];
        }
        if (is_string($val) && strlen($val) > 20000) {
            return substr($val, 0, 20000);
        }
        if (is_array($val)) {
            return array_map(fn($v) => self::normalize($v), $val);
        }
        return $val;
    }

    private static function denormalize(mixed $val): mixed
    {
        if (is_array($val)) {
            if (isset($val['__model'], $val['id']) && class_exists($val['__model'])) {
                /** @var Model $m */
                $m = $val['__model'];
                return $m::query()->findOrFail($val['id']);
            }
            if (isset($val['__date'])) {
                return new \DateTimeImmutable($val['__date']);
            }
            if (isset($val['__enum'], $val['value']) && enum_exists($val['__enum'])) {
                $cls = $val['__enum'];
                return $cls::from($val['value']);
            }
            if (isset($val['__arrayable'])) {
                return $val['data'] ?? [];
            }
            if (isset($val['__json'])) {
                return $val['data'] ?? [];
            }
            if (isset($val['__unsupported'])) {
                return null;
            }
            return array_map([self::class, 'denormalize'], $val);
        }
        return $val;
    }
}
