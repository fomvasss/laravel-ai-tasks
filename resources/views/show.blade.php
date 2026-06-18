@extends('ai-tasks::layout')

@section('title', $run->task)

@section('content')

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

{{-- Header --}}
<div class="flex items-center gap-3 mb-6 flex-wrap">
    <a href="{{ route('ai-tasks.index') }}" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 text-sm">← Back</a>
    <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $run->task }}</h1>
    <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium {{ $badge }}">{{ $run->status }}</span>
    <span class="text-xs text-gray-400 dark:text-gray-500 font-mono">{{ $run->id }}</span>
</div>

{{-- Meta --}}
<div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-4 mb-4">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        @foreach([
            'Driver'       => $run->driver,
            'Modality'     => $run->modality,
            'Dispatch'     => $run->dispatch ?? '—',
            'Tenant'       => $run->tenant_id,
            'Subject'      => $run->subject_type ? class_basename($run->subject_type) . '#' . $run->subject_id : '—',
            'Started'      => $run->started_at?->format('Y-m-d H:i:s') ?? '—',
            'Finished'     => $run->finished_at?->format('Y-m-d H:i:s') ?? '—',
            'Duration'     => $durStr,
        ] as $label => $value)
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-0.5">{{ $label }}</div>
            <div class="font-medium text-gray-800 dark:text-gray-200">{{ $value }}</div>
        </div>
        @endforeach
    </div>
</div>

{{-- Tokens / Cost --}}
<div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 p-4 mb-4">
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        @foreach([
            'Tokens in'    => $run->tokens_in ?? '—',
            'Tokens out'   => $run->tokens_out ?? '—',
            'Cache read'   => $run->cache_read_tokens ?? '—',
            'Cache write'  => $run->cache_write_tokens ?? '—',
            'Cost'         => $run->cost !== null ? '$' . number_format($run->cost, 8) : '—',
        ] as $label => $value)
        <div>
            <div class="text-xs text-gray-500 dark:text-gray-400 mb-0.5">{{ $label }}</div>
            <div class="font-semibold text-gray-800 dark:text-gray-200">{{ $value }}</div>
        </div>
        @endforeach
    </div>
</div>

{{-- Error --}}
@if($run->error)
<div class="bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 rounded-lg p-4 mb-4">
    <div class="text-xs font-semibold text-red-700 dark:text-red-400 mb-2 uppercase tracking-wider">Error</div>
    <pre class="text-xs text-red-800 dark:text-red-300 overflow-auto whitespace-pre-wrap">{{ $run->error }}</pre>
</div>
@endif

{{-- Request --}}
@if($run->request)
<details class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 mb-4" open>
    <summary class="px-4 py-3 font-medium text-gray-700 dark:text-gray-300 cursor-pointer select-none hover:bg-gray-50 dark:hover:bg-gray-800 rounded-lg">
        Request
    </summary>
    <div class="border-t border-gray-100 dark:border-gray-700 px-4 py-3">
        <pre class="text-xs text-gray-700 dark:text-gray-300 overflow-auto bg-gray-50 dark:bg-gray-800 rounded p-3 max-h-96">{{ json_encode($run->request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
</details>
@endif

{{-- Response --}}
@if($run->response)
<details class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 mb-4" open>
    <summary class="px-4 py-3 font-medium text-gray-700 dark:text-gray-300 cursor-pointer select-none hover:bg-gray-50 dark:hover:bg-gray-800 rounded-lg">
        Response
    </summary>
    <div class="border-t border-gray-100 dark:border-gray-700 px-4 py-3">
        <pre class="text-xs text-gray-700 dark:text-gray-300 overflow-auto bg-gray-50 dark:bg-gray-800 rounded p-3 max-h-96">{{ json_encode($run->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
</details>
@endif

@endsection
