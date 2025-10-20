<?php

namespace Fomvasss\AiTasks\Support;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

/**
 * Спрощена бібліотека шаблонів. У проді — з БД або файлів.
 */
class Prompt
{
    public function __construct(public string $template, public ?string $key = null, public ?string $source = null) {}

    /**
     * @param string $key
     * @return self
     */
    public static function get(string $key): self
    {
        $driver = config('ai.prompts.driver', 'files');

        return match ($driver) {
//            'files'     => self::fromFiles($key),
//            'database'  => self::fromDatabase($key),
            'inline'    => self::fromInline($key),
            default     => throw new RuntimeException("Unknown prompts driver: {$driver}"),
        };
    }

    /**
     * @param array $vars
     * @return string
     */
    public function render(array $vars = []): string
    {
        if (($this->source && Str::endsWith($this->source, '.blade.php'))) {
            return Blade::render($this->template, $vars);
        }

        $out = $this->template;
        foreach ($vars as $k => $v) {
            $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $scalar = is_scalar($v) ? (string) $v : $json;

            $out = str_replace('{{'.$k.'}}', $scalar, $out);
            $out = str_replace('{{'.$k.' | json}}', $json, $out);
        }

        return $out;
    }

    /**
     * @param string $key
     * @return self
     */
    protected static function fromInline(string $key): self
    {
        $data = config('ai.prompts.inline', []);

        if (! array_key_exists($key, $data)) {
            throw new RuntimeException("Inline prompt not found: {$key}");
        }

        return new self((string) $data[$key], $key, 'inline');
    }

    protected static function fromFiles(string $key): self
    {
        // TOODO
    }

    protected static function fromDatabase(string $key): self
    {
        // TOODO
    }
}
