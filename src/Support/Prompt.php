<?php

namespace Fomvasss\AiTasks\Support;

/**
 * Спрощена бібліотека шаблонів. У проді — з БД або файлів.
 */
class Prompt
{
    public function __construct(public string $template) {}

    public static function get(string $key): self
    {
        // демо — вшиті варіанти
        $templates = [
            'product.description.v3' => "Мова: {{locale}}\nНазва: {{title}}\nХарактеристики: {{features | json}}\nЗгенеруй структурований опис телефону s25 Plus у JSON: {\"short\":\"...\",\"html\":\"...\"}",
        ];

        return new self($templates[$key] ?? '');
    }

    public function render(array $vars = []): string
    {
        $out = $this->template;
        foreach ($vars as $k => $v) {
            if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            $out = str_replace('{{'.$k.'}}', (string) $v, $out);
            $out = str_replace('{{'.$k.' | json}}', json_encode($v, JSON_UNESCAPED_UNICODE), $out);
        }
        return $out;
    }
}
