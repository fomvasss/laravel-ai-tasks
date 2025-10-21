<?php

namespace Fomvasss\AiTasks\Support;

/**
 * Простий валідатор/парсер очікуваних відповідей.
 * У реалі — підключити JSON Schema валідатор (justinrainbow/json-schema або opis/json-schema).
 */
class Schema
{
    public static function parse(string $content, string $schemaKey, bool $strict = true): array
    {
        $data = json_decode($content, true);
        if (!is_array($data)) {
            // наївний витяг JSON з тексту
            if (preg_match('/\{.*\}/s', $content, $m)) {
                $data = json_decode($m[0], true);
            }
        }

        if (!is_array($data)) {
            throw new \RuntimeException("Schema parse error: not JSON for {$schemaKey}");
        }

        $schema = self::load($schemaKey);

        // Simple check
        // TODO: use in assert example opis/json-schema
        if ($strict) {
            self::assert($data, $schema, $schemaKey);
        }

        return $data;
    }

    public static function load(string $key): array
    {
        $driver = config('ai.schemas.driver', 'inline');

        if ($driver === 'files') {
            $path = rtrim(config('ai.schemas.path'), '/')."/{$key}.json";
            if (!is_file($path)) {
                throw new \RuntimeException("Schema file not found: {$key}");
            }
            
            return json_decode(file_get_contents($path), true) ?? [];
        }

        $inline = config('ai.schemas.inline', []);
        if (! array_key_exists($key, $inline)) {
            throw new \RuntimeException("Schema not registered: {$key}");
        }
        return $inline[$key];
    }

    protected static function assert(array $data, array $schema, string $key): void
    {
        // Дуже легка перевірка required (щоб не тягнути пакет-валідатор по дефолту):
        $required = $schema['required'] ?? [];
        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                throw new \RuntimeException("Schema '{$key}' violation: missing '{$field}'");
            }
        }
        // Мінімальний контроль типів для string
        $props = $schema['properties'] ?? [];
        foreach ($props as $name => $rule) {
            if (!array_key_exists($name, $data)) continue;
            if (($rule['type'] ?? null) === 'string' && !is_string($data[$name])) {
                throw new \RuntimeException("Schema '{$key}' violation: '{$name}' must be string");
            }
        }
        // можна розширити далі або підключити повний валідатор
    }
}
