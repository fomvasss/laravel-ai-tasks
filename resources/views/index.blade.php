@extends('ai-tasks::layout')

@section('title', 'Runs')

@section('content')

{{-- Stats --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    @foreach([
        ['label' => 'Today total',  'value' => $stats['today_total'], 'color' => ''],
        ['label' => 'Today OK',     'value' => $stats['today_ok'],    'color' => 'text-green-600'],
        ['label' => 'Today errors', 'value' => $stats['today_error'], 'color' => 'text-red-600'],
        ['label' => 'Month cost',   'value' => '$' . $stats['month_cost'], 'color' => ''],
    ] as $s)
    <div class="bg-white rounded-lg border border-gray-200 px-4 py-3">
        <div class="text-xs text-gray-500 mb-1">{{ $s['label'] }}</div>
        <div class="text-2xl font-semibold {{ $s['color'] }}">{{ $s['value'] }}</div>
    </div>
    @endforeach
</div>

{{-- Filters --}}
<form method="GET" class="bg-white rounded-lg border border-gray-200 px-4 py-3 mb-4 flex flex-wrap gap-3 items-end">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="border border-gray-300 rounded px-2 py-1 text-sm">
            <option value="">All</option>
            @foreach(['ok','error','dead','running','queued','waiting','skipped'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Driver</label>
        <select name="driver" class="border border-gray-300 rounded px-2 py-1 text-sm">
            <option value="">All</option>
            @foreach($drivers as $d)
                <option value="{{ $d }}" @selected(request('driver') === $d)>{{ $d }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Tenant</label>
        <select name="tenant" class="border border-gray-300 rounded px-2 py-1 text-sm">
            <option value="">All</option>
            @foreach($tenants as $t)
                <option value="{{ $t }}" @selected(request('tenant') === $t)>{{ $t }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Dispatch</label>
        <select name="dispatch" class="border border-gray-300 rounded px-2 py-1 text-sm">
            <option value="">All</option>
            @foreach(['sync','queue'] as $d)
                <option value="{{ $d }}" @selected(request('dispatch') === $d)>{{ $d }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Task</label>
        <input type="text" name="task" value="{{ request('task') }}" placeholder="search..."
               class="border border-gray-300 rounded px-2 py-1 text-sm w-32">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">From</label>
        <input type="date" name="from" value="{{ request('from') }}" class="border border-gray-300 rounded px-2 py-1 text-sm">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">To</label>
        <input type="date" name="to" value="{{ request('to') }}" class="border border-gray-300 rounded px-2 py-1 text-sm">
    </div>
    <button type="submit" class="bg-gray-800 text-white text-sm px-3 py-1.5 rounded hover:bg-gray-700">Filter</button>
    <a href="{{ route('ai-tasks.index') }}" class="text-sm text-gray-500 hover:text-gray-700 py-1.5">Reset</a>
</form>

{{-- Table --}}
<div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
    <table class="min-w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
                @foreach(['Task','Driver','Tenant','Dispatch','Status','Tok in','Tok out','Cost','Duration','Started'] as $col)
                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ $col }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($runs as $run)
            @php
                $badge = match($run->status) {
                    'ok'      => 'bg-green-100 text-green-700',
                    'error'   => 'bg-red-100 text-red-700',
                    'dead'    => 'bg-red-200 text-red-800',
                    'running' => 'bg-blue-100 text-blue-700',
                    'queued'  => 'bg-yellow-100 text-yellow-700',
                    'waiting' => 'bg-orange-100 text-orange-700',
                    default   => 'bg-gray-100 text-gray-600',
                };
                $dur = $run->duration_ms;
                $durStr = $dur === null ? '—' : ($dur < 1000 ? "{$dur}ms" : number_format($dur / 1000, 1) . 's');
            @endphp
            <tr class="hover:bg-gray-50">
                <td class="px-3 py-2">
                    <a href="{{ route('ai-tasks.show', $run->id) }}" class="text-blue-600 hover:underline font-medium">
                        {{ $run->task }}
                    </a>
                    @if($run->subject_type)
                        <div class="text-xs text-gray-400">{{ class_basename($run->subject_type) }}#{{ $run->subject_id }}</div>
                    @endif
                </td>
                <td class="px-3 py-2 text-gray-600">{{ $run->driver }}</td>
                <td class="px-3 py-2 text-gray-500 text-xs">{{ $run->tenant_id }}</td>
                <td class="px-3 py-2">
                    @php $dispatchBadge = $run->dispatch === 'queue' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600'; @endphp
                    <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium {{ $dispatchBadge }}">{{ $run->dispatch }}</span>
                </td>
                <td class="px-3 py-2">
                    <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium {{ $badge }}">{{ $run->status }}</span>
                </td>
                <td class="px-3 py-2 text-gray-600">{{ $run->tokens_in ?? '—' }}</td>
                <td class="px-3 py-2 text-gray-600">{{ $run->tokens_out ?? '—' }}</td>
                <td class="px-3 py-2 text-gray-600">{{ $run->cost !== null ? '$' . number_format($run->cost, 6) : '—' }}</td>
                <td class="px-3 py-2 text-gray-500">{{ $durStr }}</td>
                <td class="px-3 py-2 text-gray-400 text-xs whitespace-nowrap">
                    {{ $run->started_at?->format('m-d H:i:s') ?? '—' }}
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="10" class="px-3 py-8 text-center text-gray-400">No runs found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $runs->links('pagination::tailwind') }}
</div>

@endsection
