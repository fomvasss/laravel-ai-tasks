<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\DTO;

final class AiResponse
{
    /**
     * @param list<array<string, mixed>> $resumeMessages the paused turn, see PausedTurn — filled
     *        by the driver when the call paused for tool approval, stored with the run
     */
    public function __construct(
        public readonly bool    $ok,
        public readonly ?string $content   = null,
        public readonly array   $usage     = [],
        public readonly array   $raw       = [],
        public readonly ?string $error     = null,
        public readonly array   $toolCalls = [],
        public readonly ?array  $structured = null,
        public readonly ?string $finishReason = null,
        public readonly array   $pendingApprovals = [],
        public readonly array   $resumeMessages = [],
        public readonly ?string $runId = null,
    ) {}

    /** The run stopped before a tool that needs approval — resume it with AI::resume(). */
    public function paused(): bool
    {
        return $this->pendingApprovals !== [];
    }

    /**
     * The calls waiting for a decision in their full form (result_id, reasoning fields) — what
     * $pendingApprovals reduces to id/tool/arguments/reason for display.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingToolCalls(): array
    {
        $ids = array_column($this->pendingApprovals, 'id');

        return array_values(array_filter($this->toolCalls, fn (array $call): bool => in_array($call['id'] ?? null, $ids, true)));
    }

    public function withRunId(string $runId): self
    {
        return new self(
            $this->ok, $this->content, $this->usage, $this->raw, $this->error, $this->toolCalls,
            $this->structured, $this->finishReason, $this->pendingApprovals, $this->resumeMessages, $runId,
        );
    }
}
