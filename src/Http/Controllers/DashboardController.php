<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Http\Controllers;

use Fomvasss\AiTasks\Models\AiRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
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

        return view('ai-tasks::index', compact('runs', 'stats', 'drivers', 'tenants'));
    }

    public function data(Request $request): JsonResponse
    {
        $runs = $this->buildQuery($request)->simplePaginate((int) config('ai-tasks.dashboard.per_page', 50))->withQueryString();

        return response()->json([
            'stats' => $this->stats(),
            'runs' => $runs->map(fn($r) => [
                'id' => $r->id,
                'task' => $r->task,
                'driver' => $r->driver,
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
            ]),
        ]);
    }

    private function buildQuery(Request $request): Builder
    {
        $query = AiRun::query()->latest('started_at')->select([
            'id', 'tenant_id', 'task', 'driver', 'modality', 'dispatch', 'status',
            'subject_type', 'subject_id', 'tokens_in', 'tokens_out', 'cost',
            'started_at', 'finished_at', 'duration_ms',
        ]);

        if ($v = $request->input('status')) { $query->where('status', $v); }
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
        ];
    }

    public function show(string $id): View
    {
        $run = AiRun::findOrFail($id);

        return view('ai-tasks::show', compact('run'));
    }
}
