@props(['items', 'level' => 0])

<ul class="{{ $level === 0 ? 'space-y-1' : 'ml-4 mt-1 space-y-1 border-l border-gray-200 pl-3' }}">
    @foreach ($items as $item)
        <li>
            <a href="#{{ $item['id'] }}"
               title="{{ $item['text'] }}"
               class="toc-link group"
               style="--tw-bg-opacity: 1;"
               onmouseover="this.style.backgroundColor='rgba(var(--color-primary-rgb), 0.08)'"
               onmouseout="this.style.backgroundColor='transparent'">
                <span class="toc-dot"></span>
                <span class="min-w-0 flex-1 truncate">{{ $item['text'] }}</span>
            </a>
            @if (!empty($item['children']))
                <x-bladethemev1::posts.toc-list :items="$item['children']" :level="$level + 1" />
            @endif
        </li>
    @endforeach
</ul>
