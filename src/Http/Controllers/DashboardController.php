<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Http\Controllers;

use Fomvasss\AiTasks\Models\AiRun;
use Fomvasss\AiTasks\Support\RunRetrier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $runs = $this->buildQuery($request)->simplePaginate((int) config('ai-tasks.dashboard.per_page', 50))->withQueryString();

        $drivers = AiRun::distinct()->orderBy('driver')->pluck('driver');
        $tenants = AiRun::distinct()->orderBy('tenant_id')->pluck('tenant_id');

        $stats = $this->stats();
        $filteredStats = $this->filteredStats($request);

        return view('ai-tasks::index', compact('runs', 'stats', 'filteredStats', 'drivers', 'tenants'));
    }

    public function data(Request $request): JsonResponse
    {
        $runs = $this->buildQuery($request)->simplePaginate((int) config('ai-tasks.dashboard.per_page', 50))->withQueryString();

        return response()->json([
            'stats' => $this->stats(),
            'filtered_stats' => $this->filteredStats($request),
            'runs' => $runs->map(fn($r) => [
                'id' => $r->id,
                'task' => $r->task,
                'driver' => $r->driver,
                'model' => $r->model,
                'tenant_id' => $r->tenant_id,
                'dispatch' => $r->dispatch,
                'status' => $r->status,
                'modality' => $r->modality,
                'subject_type' => $r->subject_type ? class_basename($r->subject_type) : null,
                'subject_id' => $r->subject_id,
                'tokens_in' => $r->tokens_in,
                'tokens_out' => $r->tokens_out,
                'cost' => $r->cost,
                'duration_ms' => $r->duration_ms,
                'started_at' => $r->started_at?->format('m-d H:i:s'),
                'is_stuck' => $r->isStuck(),
                'can_retry' => $r->canRetry(),
                'is_open' => ! in_array($r->status, ['ok', 'dead', 'skipped'], true),
            ]),
        ]);
    }

    private function buildQuery(Request $request): Builder
    {
        // COALESCE, not latest('started_at'): a queued run has no started_at, and DESC puts NULLs
        // first on Postgres but last on MySQL — the same data would order differently per driver.
        $query = AiRun::query()->orderByRaw('COALESCE(started_at, created_at) DESC')->select([
            'id', 'tenant_id', 'task', 'driver', 'model', 'modality', 'dispatch', 'status',
            'subject_type', 'subject_id', 'tokens_in', 'tokens_out', 'cost',
            'started_at', 'finished_at', 'duration_ms', 'created_at',
        ]);

        // 'stuck' is a pseudo-status: a state derived from time, not a value in the column
        if (($v = $request->input('status')) === 'stuck') {
            $query->stuck();
        } elseif ($v) {
            $query->where('status', $v);
        }
        if ($v = $request->input('driver')) { $query->where('driver', $v); }
        if ($v = $request->input('tenant')) { $query->where('tenant_id', $v); }
        if ($v = $request->input('dispatch')) { $query->where('dispatch', $v); }
        if ($v = $request->input('task')) { $query->where('task', 'like', "%{$v}%"); }
        if ($v = $request->input('from')) { $query->where('started_at', '>=', $v); }
        if ($v = $request->input('to')) { $query->where('started_at', '<=', $v . ' 23:59:59'); }

        return $query;
    }

    private function stats(): array
    {
        $today = now()->startOfDay();

        return [
            'today_total' => AiRun::where('started_at', '>=', $today)->count(),
            'today_ok' => AiRun::where('started_at', '>=', $today)->where('status', 'ok')->count(),
            'today_error' => AiRun::where('started_at', '>=', $today)->whereIn('status', ['error', 'dead'])->count(),
            'month_cost' => round((float) AiRun::where('status', 'ok')
                ->whereBetween('started_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('cost'), 6),
            // Not scoped to today: a stuck run is stuck until someone deals with it
            'stuck' => AiRun::query()->stuck()->count(),
        ];
    }

    private function filteredStats(Request $request): array
    {
        $row = $this->buildQuery($request)
            ->reorder()
            ->select(\DB::raw("count(*) as total, sum(case when status = 'ok' then 1 else 0 end) as ok, sum(case when status in ('error','dead') then 1 else 0 end) as errors, sum(tokens_in) as tokens_in, sum(tokens_out) as tokens_out, sum(cost) as cost"))
            ->toBase()
            ->first();

        return [
            'total' => (int) $row->total,
            'ok' => (int) $row->ok,
            'errors' => (int) $row->errors,
            'tokens_in' => (int) $row->tokens_in,
            'tokens_out' => (int) $row->tokens_out,
            'cost' => round((float) $row->cost, 6),
        ];
    }

    public function show(string $id): View
    {
        $run = AiRun::findOrFail($id);

        return view('ai-tasks::show', compact('run'));
    }

    public function retry(string $id): RedirectResponse
    {
        $run = AiRun::findOrFail($id);

        if (! $run->canRetry()) {
            return back()->with('ai-tasks-flash', ['error', "Run in status '{$run->status}' cannot be retried."]);
        }

        if (! RunRetrier::retry($run)) {
            return back()->with('ai-tasks-flash', ['error', 'Task cannot be reconstructed — this run was recorded with store_request disabled.']);
        }

        return back()->with('ai-tasks-flash', ['ok', 'Run re-dispatched.']);
    }

    public function markDead(string $id): RedirectResponse
    {
        $run = AiRun::findOrFail($id);

        $run->abandon('Marked dead from the dashboard.');

        return back()->with('ai-tasks-flash', ['ok', 'Run marked dead.']);
    }
}
