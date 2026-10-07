<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Support;

use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * The messages a call paused for tool approval produced — every step of it, not only the call
 * waiting for a decision: tools that needed no approval in the same step have already run, and
 * their results belong to the history too. Kept as JSON in ai_runs.response.resume so a resume
 * replays the exact turn (tool call ids, result ids, reasoning replay blocks) instead of the
 * application rebuilding it from what it showed the user.
 */
final class PausedTurn
{
    /**
     * $provider marks replay blocks produced within the run (laravel/ai leaves those unmarked):
     * resumed later on another provider, laravel/ai then drops them instead of sending one
     * provider's raw blocks to another.
     *
     * @param iterable<Message> $messages
     * @return list<array<string, mixed>>
     */
    public static function serialize(iterable $messages, ?string $provider = null): array
    {
        $out = [];

        foreach ($messages as $message) {
            $out[] = match (true) {
                $message instanceof AssistantMessage => [
                    'role' => 'assistant',
                    'content' => $message->content,
                    'tool_calls' => $message->toolCalls->map(fn (ToolCall $call): array => $call->toArray())->values()->all(),
                    'replay_blocks' => $message->replayBlocks,
                    'replay_blocks_provider' => $message->replayBlocksProvider ?? ($message->replayBlocks !== [] ? $provider : null),
                ],
                $message instanceof ToolResultMessage => [
                    'role' => 'tool_result',
                    'tool_results' => $message->toolResults->map(fn (ToolResult $result): array => $result->toArray())->values()->all(),
                ],
                default => ['role' => $message->role->value, 'content' => $message->content],
            };
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return list<Message>
     */
    public static function restore(array $messages): array
    {
        return array_map(fn (array $m): Message => match ($m['role']) {
            'assistant' => new AssistantMessage(
                (string) ($m['content'] ?? ''),
                new Collection(array_map(fn (array $call): ToolCall => ToolCall::fromArray($call), $m['tool_calls'] ?? [])),
                $m['replay_blocks'] ?? [],
                $m['replay_blocks_provider'] ?? null,
            ),
            'tool_result' => new ToolResultMessage(
                new Collection(array_map(fn (array $result): ToolResult => ToolResult::fromArray($result), $m['tool_results'] ?? [])),
            ),
            default => new Message($m['role'], $m['content'] ?? ''),
        }, $messages);
    }
}
