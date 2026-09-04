@props(['items', 'level' => 0])

<ul class="{{ $level === 0 ? 'space-y-1' : 'ml-4 mt-1 space-y-1 border-l border-gray-200 pl-3' }}">
    @foreach ($items as $item)
        <li>
            <a href="#{{ $item['id'] }}"
               title="{{ $item['text'] }}"
               class="toc-link group flex min-w-0 items-center gap-2 rounded-md px-2 py-2 text-sm text-gray-600 transition-colors hover:text-primary"
               style="--tw-bg-opacity: 1;"
               onmouseover="this.style.backgroundColor='rgba(var(--color-primary-rgb), 0.08)'"
               onmouseout="this.style.backgroundColor='transparent'">
                <span class="toc-dot h-1.5 w-1.5 flex-shrink-0 rounded-full bg-gray-300"></span>
                <span class="min-w-0 flex-1 truncate">{{ $item['text'] }}</span>
            </a>
            @if (!empty($item['children']))
                <x-bladethemev1::posts.toc-list :items="$item['children']" :level="$level + 1" />
            @endif
        </li>
    @endforeach
</ul>
