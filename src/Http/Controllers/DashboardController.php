<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Http\Controllers;

use Fomvasss\AiTasks\Models\AiRun;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $query = AiRun::query()->latest('started_at')->select([
            'id', 'tenant_id', 'task', 'driver', 'modality', 'dispatch', 'status',
            'tokens_in', 'tokens_out', 'cost', 'error', 'started_at', 'finished_at', 'duration_ms',
        ]);

        if ($v = $request->input('status'))   { $query->where('status', $v); }
        if ($v = $request->input('driver'))   { $query->where('driver', $v); }
        if ($v = $request->input('tenant'))   { $query->where('tenant_id', $v); }
        if ($v = $request->input('dispatch')) { $query->where('dispatch', $v); }
        if ($v = $request->input('task'))     { $query->where('task', 'like', "%{$v}%"); }
        if ($v = $request->input('from'))     { $query->where('started_at', '>=', $v); }
        if ($v = $request->input('to'))       { $query->where('started_at', '<=', $v . ' 23:59:59'); }

        $runs = $query->paginate(50)->withQueryString();

        $today = now()->startOfDay();

        $stats = [
            'today_total' => AiRun::where('started_at', '>=', $today)->count(),
            'today_ok'    => AiRun::where('started_at', '>=', $today)->where('status', 'ok')->count(),
            'today_error' => AiRun::where('started_at', '>=', $today)->whereIn('status', ['error', 'dead'])->count(),
            'month_cost'  => round((float) AiRun::where('status', 'ok')
                ->whereBetween('started_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('cost'), 6),
        ];

        $drivers = AiRun::distinct()->orderBy('driver')->pluck('driver');
        $tenants = AiRun::distinct()->orderBy('tenant_id')->pluck('tenant_id');

        return view('ai-tasks::index', compact('runs', 'stats', 'drivers', 'tenants'));
    }

    public function show(string $id): View
    {
        $run = AiRun::findOrFail($id);

        return view('ai-tasks::show', compact('run'));
    }
}
