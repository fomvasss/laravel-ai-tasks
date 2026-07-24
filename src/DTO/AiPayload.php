<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\DTO;

use Laravel\Ai\ToolChoice;
use Laravel\SerializableClosure\SerializableClosure;

final class AiPayload
{
    public readonly ?SerializableClosure $schema;

    public readonly ?ToolChoice $toolChoice;

    /**
     * @param \Closure(\Illuminate\Contracts\JsonSchema\JsonSchema): array<string, \Illuminate\JsonSchema\Types\Type>|null $schema
     */
    public function __construct(
        public readonly string  $modality,
        public readonly array   $messages = [],
        public readonly ?string $systemPrompt = null,
        public readonly array   $options = [],
        public readonly array   $meta = [],
        public readonly array   $tools = [],
        public readonly bool    $jsonMode = false,
        public readonly ?array  $providerOverride = null,
        ?\Closure                    $schema = null,
        ToolChoice|string|array|null $toolChoice = null,
    ) {
        $this->schema     = $schema !== null ? new SerializableClosure($schema) : null;
        $this->toolChoice = $toolChoice !== null ? ToolChoice::from($toolChoice) : null;
    }
}
