{{-- Row actions menu. The runs table re-renders its tbody from JSON on every poll, so the same
     markup also exists in renderActions() in index.blade.php — keep the two in step. --}}
@php
    $withView = $withView ?? true;
    $isOpen = ! in_array($run->status, ['ok', 'dead', 'skipped'], true);
@endphp
<div class="dropdown relative inline-block">
    <button type="button" title="Actions"
            class="dropdown-toggle w-7 h-7 inline-flex items-center justify-center rounded border border-gray-200 dark:border-gray-600 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800">
        &#8942;
    </button>
    <div class="dropdown-menu hidden w-40 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-lg py-1 z-20">
        @if($withView)
            <a href="{{ route('ai-tasks.show', $run->id) }}"
               class="block px-3 py-1.5 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800">View</a>
        @endif
        @if($run->canRetry())
            <form method="POST" action="{{ route('ai-tasks.retry', $run->id) }}">
                @csrf
                <button type="submit"
                        class="w-full text-left px-3 py-1.5 text-sm text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/30">Retry</button>
            </form>
        @endif
        @if($isOpen)
            <form method="POST" action="{{ route('ai-tasks.dead', $run->id) }}"
                  onsubmit="return confirm('Mark this run dead?')">
                @csrf
                <button type="submit"
                        class="w-full text-left px-3 py-1.5 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30">Dead</button>
            </form>
        @endif
    </div>
</div>
