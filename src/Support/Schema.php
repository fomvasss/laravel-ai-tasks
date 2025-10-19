<?php

namespace Fomvasss\AiTasks\Support;

/**
 * Простий валідатор/парсер очікуваних відповідей.
 * У реалі — підключити JSON Schema валідатор (justinrainbow/json-schema або opis/json-schema).
 */
class Schema
{
    public static function parse(string $content, ?string $schemaKey = null): array
    {
        // try JSON first
        $data = json_decode($content, true);
        if (! is_array($data)) {
            // fallback: наївний витяг JSON-об’єкта
            if (preg_match('/\{.*\}/s', $content, $m)) {
                $data = json_decode($m[0], true);
            }
        }
        
        if (! is_array($data)) {
            throw new \RuntimeException("Schema parse error: not JSON for {$schemaKey}");
        }

        // мінімальна перевірка для demo
        return $data;
    }
}
