<?php

namespace Fomvasss\AiTasks\Support;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Спрощена бібліотека шаблонів. У проді — з БД або файлів.
 */
class Prompt
{
    public function __construct(
        public string $template,
        public ?string $key = null,
        public ?string $source = null, // path|database|inline
        public bool $isBlade = false
    ) {}

    /**
     * @param string $key
     * @return self
     */
    public static function get(string $key): self
    {
        self::assertKey($key);

        $driver = config('ai.prompts.driver', 'files');
        return match ($driver) {
            'inline'    => self::fromInline($key),
            'files'     => self::fromFiles($key),
//            'database'  => self::fromDatabase($key),
            default     => throw new \RuntimeException("Unknown prompts driver: {$driver}"),
        };
    }

    public static function text(string $template): self
    {
        return new self($template, key: null, source: 'inline', isBlade: false);
    }

    public static function blade(string $bladeString): self
    {
        return new self($bladeString, key: null, source: 'inline', isBlade: true);
    }

    public static function make(?string $key = null, ?string $text = null, bool $blade = false): self
    {
        if ($text !== null) {
            return $blade ? self::blade($text) : self::text($text);
        }
        if ($key === null) {
            throw new RuntimeException('Either text or key must be provided.');
        }

        return self::get($key);
    }

    /**
     * @param array $vars
     * @return string
     */
    public function render(array $vars = []): string
    {
        if ($this->isBlade || ($this->source && Str::endsWith($this->source, '.blade.php'))) {
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
            throw new \RuntimeException("Inline prompt not found: {$key}");
        }

        $val = $data[$key];
        $isBlade = false;
        if (is_array($val)) {
            $tpl = (string) ($val['template'] ?? '');
            $isBlade = (bool) ($val['blade'] ?? false);
        } else {
            $tpl = (string) $val;
        }

        return new self($tpl, $key, 'inline', $isBlade);
    }

    protected static function fromFiles(string $key): self
    {
        $basePath   = rtrim((string) config('ai.prompts.path'), DIRECTORY_SEPARATOR);
        $extensions = (array) config('ai.prompts.extensions', ['blade.php', 'md', 'txt', 'prompt']);
        $cacheOn    = (bool) data_get(config('ai.prompts'), 'cache.enabled', true);
        $ttl        = (int) data_get(config('ai.prompts'), 'cache.ttl', 300);

        $cacheKey = "ai:prompt:files:{$key}";
        if ($cacheOn && ($hit = Cache::get($cacheKey))) {
            return new self($hit['template'], $key, $hit['source']);
        }

        foreach ($extensions as $ext) {
            $path = $basePath . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $key) . '.' . $ext;
            if (is_file($path)) {
                $contents = file_get_contents($path);

                $prompt = new self($contents ?: '', $key, $path);

                if ($cacheOn) {
                    Cache::put($cacheKey, ['template' => $prompt->template, 'source' => $prompt->source], $ttl);
                }

                return $prompt;
            }
        }

        throw new \RuntimeException("Prompt file not found for key '{$key}' in {$basePath}");
    }

    protected static function fromDatabase(string $key): self
    {
        // TOODO
    }

    protected static function assertKey(string $key): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
            throw new RuntimeException("Invalid prompt key '{$key}'. Use only [A-Za-z0-9_-], no dots.");
        }
    }
}
