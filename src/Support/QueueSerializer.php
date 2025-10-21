<?php

namespace Fomvasss\AiTasks\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use ReflectionClass;
use ReflectionNamedType;

class QueueSerializer
{
    /**
     * Знімає дані для конструктора таска.
     * Пріоритет:
     * 1) якщо у таска є serializeForQueue() — скористатися ним (беккомпат)
     * 2) якщо є public toArray() — взяти як є і загорнути у ['__ctor_array' => ...]
     * 3) зчитати аргументи конструктора рефлексією і зібрати їхні значення з об'єкта
     */
    public static function serializeTask(object $task): array
    {
        if (method_exists($task, 'serializeForQueue')) {
            return $task->serializeForQueue();
        }

        if (method_exists($task, 'toArray')) {
            return ['__ctor_array' => $task->toArray()];
        }

        $rc = new ReflectionClass($task);
        $ctor = $rc->getConstructor();
        if (! $ctor) {
            return []; // без аргументів
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $name = $param->getName();

            // Спроба взяти значення з властивості (імена збігаються з promoted properties)
            $val = null;
            if ($rc->hasProperty($name)) {
                $prop = $rc->getProperty($name);
                $prop->setAccessible(true);
                $val = $prop->isInitialized($task) ? $prop->getValue($task) : null;
            } else {
                // fallback: значення може бути тільки у конструкторі (без властивості) — беремо default або null
                $val = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
            }

            $args[] = self::normalize($val);
        }

        return $args;
    }

    /** Нормалізація значень у безпечний/серіалізований вигляд */
    protected static function normalize(mixed $val): mixed
    {
        // Eloquent-моделі → зберігаємо ідентифікатор для ре-гідратації
        if ($val instanceof Model) {
            return ['__model' => get_class($val), 'id' => $val->getKey()];
        }

        // Дати
        if ($val instanceof \DateTimeInterface) {
            return ['__date' => $val->format(\DateTimeInterface::ATOM)];
        }

        // Enum
        if ($val instanceof \BackedEnum) {
            return ['__enum' => get_class($val), 'value' => $val->value];
        }

        // Arrayable / JsonSerializable
        if ($val instanceof Arrayable) {
            return ['__arrayable' => get_class($val), 'data' => $val->toArray()];
        }

        if ($val instanceof JsonSerializable) {
            return ['__json' => get_class($val), 'data' => $val->jsonSerialize()];
        }

        // Ресурси/Closures/Streams — не серіалізуємо
        if (is_resource($val) || $val instanceof \Closure) {
            return ['__unsupported' => get_debug_type($val)];
        }

        // Великі строки трохи обрізати, щоб не роздувати payload (опційно)
        if (is_string($val) && strlen($val) > 20000) {
            return substr($val, 0, 20000);
        }

        // Масиви рекурсивно
        if (is_array($val)) {
            return array_map(fn($v) => self::normalize($v), $val);
        }

        return $val; // скаляри/просте
    }

    /**
     * Відтворення екземпляра таска.
     * Підтримує:
     * - ['__ctor_array' => [...]]  → new Class(...array_values)
     * - позиційний масив аргументів
     * - ре-гідратацію моделей/дат/enum усередині масиву
     */
    public static function instantiate(string $taskClass, array $ctorArgs): object
    {
        if (isset($ctorArgs['__ctor_array']) && is_array($ctorArgs['__ctor_array'])) {
            $ctorArgs = array_values($ctorArgs['__ctor_array']);
        } else {
            $ctorArgs = array_map([self::class, 'denormalize'], $ctorArgs);
        }

        return new $taskClass(...$ctorArgs);
    }

    protected static function denormalize(mixed $val): mixed
    {
        if (is_array($val)) {
            // Модель
            if (isset($val['__model'], $val['id']) && class_exists($val['__model'])) {
                /** @var Model $m */
                $m = $val['__model'];
                return $m::query()->findOrFail($val['id']);
            }
            // Дата
            if (isset($val['__date'])) {
                return new \DateTimeImmutable($val['__date']);
            }
            // Enum
            if (isset($val['__enum'], $val['value']) && enum_exists($val['__enum'])) {
                $cls = $val['__enum'];
                return $cls::from($val['value']);
            }
            // Arrayable/Json — віддаємо data як чистий масив (конструктор вже вирішить)
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
