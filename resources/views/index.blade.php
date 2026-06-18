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
    <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ $s['label'] }}</div>
        <div class="text-2xl font-semibold {{ $s['color'] }} dark:text-gray-100">{{ $s['value'] }}</div>
    </div>
    @endforeach
</div>

{{-- Filters --}}
<form method="GET" class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 px-4 py-3 mb-4 flex flex-wrap gap-3 items-end">
    @php
        $selectClass = 'border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-200 rounded px-2 py-1 text-sm';
        $inputClass  = 'border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-200 rounded px-2 py-1 text-sm';
        $labelClass  = 'block text-xs text-gray-500 dark:text-gray-400 mb-1';
    @endphp
    <div>
        <label class="{{ $labelClass }}">Status</label>
        <select name="status" class="{{ $selectClass }}">
            <option value="">All</option>
            @foreach(['ok','error','dead','running','queued','waiting','skipped'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="{{ $labelClass }}">Driver</label>
        <select name="driver" class="{{ $selectClass }}">
            <option value="">All</option>
            @foreach($drivers as $d)
                <option value="{{ $d }}" @selected(request('driver') === $d)>{{ $d }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="{{ $labelClass }}">Tenant</label>
        <select name="tenant" class="{{ $selectClass }}">
            <option value="">All</option>
            @foreach($tenants as $t)
                <option value="{{ $t }}" @selected(request('tenant') === $t)>{{ $t }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="{{ $labelClass }}">Dispatch</label>
        <select name="dispatch" class="{{ $selectClass }}">
            <option value="">All</option>
            @foreach(['sync','queue'] as $d)
                <option value="{{ $d }}" @selected(request('dispatch') === $d)>{{ $d }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="{{ $labelClass }}">Task</label>
        <input type="text" name="task" value="{{ request('task') }}" placeholder="search..."
               class="{{ $inputClass }} w-32">
    </div>
    <div>
        <label class="{{ $labelClass }}">From</label>
        <input type="date" name="from" value="{{ request('from') }}" class="{{ $inputClass }}">
    </div>
    <div>
        <label class="{{ $labelClass }}">To</label>
        <input type="date" name="to" value="{{ request('to') }}" class="{{ $inputClass }}">
    </div>
    <button type="submit" class="bg-gray-800 dark:bg-gray-700 text-white text-sm px-3 py-1.5 rounded hover:bg-gray-700 dark:hover:bg-gray-600">Filter</button>
    <a href="{{ route('ai-tasks.index') }}" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 py-1.5">Reset</a>
</form>

{{-- Table --}}
<div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 overflow-x-auto">
    <table class="min-w-full text-sm">
        <thead class="bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
            <tr>
                @foreach(['Task','Driver','Tenant','Dispatch','Status','Tok in','Tok out','Cost','Duration','Started'] as $col)
                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ $col }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($runs as $run)
            @php
                $badge = match($run->status) {
                    'ok'      => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400',
                    'error'   => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400',
                    'dead'    => 'bg-red-200 text-red-800 dark:bg-red-900/60 dark:text-red-300',
                    'running' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                    'queued'  => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400',
                    'waiting' => 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400',
                    default   => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
                };
                $dur = $run->duration_ms;
                $durStr = $dur === null ? '—' : ($dur < 1000 ? "{$dur}ms" : number_format($dur / 1000, 1) . 's');
            @endphp
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                <td class="px-3 py-2">
                    <a href="{{ route('ai-tasks.show', $run->id) }}" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                        {{ $run->task }}
                    </a>
                    <div class="flex gap-1 mt-0.5 flex-wrap">
                        @php $modalityColor = match($run->modality) {
                            'image'         => 'bg-pink-100 text-pink-700 dark:bg-pink-900/40 dark:text-pink-400',
                            'embed'         => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-400',
                            'audio'         => 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400',
                            'transcription' => 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-400',
                            default         => 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400',
                        }; @endphp
                        <span class="inline-flex px-1.5 py-0 rounded text-xs {{ $modalityColor }}">{{ $run->modality }}</span>
                        @if($run->subject_type)
                            <span class="text-xs text-gray-400 dark:text-gray-500">{{ class_basename($run->subject_type) }}#{{ $run->subject_id }}</span>
                        @endif
                    </div>
                </td>
                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $run->driver }}</td>
                <td class="px-3 py-2 text-gray-500 dark:text-gray-400 text-xs">{{ $run->tenant_id }}</td>
                <td class="px-3 py-2">
                    @php $dispatchBadge = $run->dispatch === 'queue'
                        ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-400'
                        : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'; @endphp
                    <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium {{ $dispatchBadge }}">{{ $run->dispatch }}</span>
                </td>
                <td class="px-3 py-2">
                    <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium {{ $badge }}">{{ $run->status }}</span>
                </td>
                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $run->tokens_in ?? '—' }}</td>
                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $run->tokens_out ?? '—' }}</td>
                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $run->cost !== null ? '$' . number_format($run->cost, 6) : '—' }}</td>
                <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $durStr }}</td>
                <td class="px-3 py-2 text-gray-400 dark:text-gray-500 text-xs whitespace-nowrap">
                    {{ $run->started_at?->format('m-d H:i:s') ?? '—' }}
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="10" class="px-3 py-8 text-center text-gray-400 dark:text-gray-500">No runs found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $runs->links('pagination::tailwind') }}
</div>

@endsection
