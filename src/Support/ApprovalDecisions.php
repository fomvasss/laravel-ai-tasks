<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Support;

use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\McpTool;
use Laravel\Ai\Tools\ToolNameResolver;

final class ApprovalDecisions
{
    /**
     * A rejection without a reason ends the model's turn with an empty answer — laravel/ai feeds
     * the model nothing to reply to. With approvals.reject_reason set, such a rejection carries
     * that text, so the model answers the user itself (offers an alternative, asks what to change).
     */
    public static function normalize(Decisions|array|null $decisions): ?Decisions
    {
        if ($decisions === null) {
            return null;
        }

        $decisions = $decisions instanceof Decisions ? $decisions : Decisions::from($decisions);
        $reason    = config('ai-tasks.approvals.reject_reason');

        if (blank($reason)) {
            return $decisions;
        }

        return Decisions::from(array_map(
            fn (Decision $d): Decision => $d->isRejected() && $d->result === null ? Decision::reject($reason) : $d,
            $decisions->all(),
        ));
    }

    /**
     * Names the model would see for the task's tools, resolved the way laravel/ai wraps them —
     * to tell before claiming a pause whether the tools it waits for still exist.
     *
     * @return list<string>
     */
    public static function toolNames(array $tools): array
    {
        $names = [];

        foreach ($tools as $tool) {
            match (true) {
                $tool instanceof ToolSearch => array_push($names, ...self::toolNames($tool->tools)),
                $tool instanceof Tool => $names[] = ToolNameResolver::resolve($tool),
                $tool instanceof \Laravel\Ai\Contracts\Agent => $names[] = ToolNameResolver::resolve(new AgentTool($tool)),
                McpTool::supports($tool) => $names[] = ToolNameResolver::resolve(new McpTool($tool)),
                McpServerTool::supports($tool) => $names[] = ToolNameResolver::resolve(new McpServerTool($tool)),
                default => null,
            };
        }

        return $names;
    }
}
