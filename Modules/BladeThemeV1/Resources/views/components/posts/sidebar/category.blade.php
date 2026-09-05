@php
    $level = $level ?? 1;
@endphp

<ul class="space-y-2">
    @foreach($parentCategory->children as $childCategory)
        @php
            $isSearched = $this->searchCate && stripos($childCategory->name, $this->searchCate) !== false;
            $hasChildren = $childCategory->children->isNotEmpty();
        @endphp
        <li x-data="{ subOpen: false }"
            class="transition cursor-pointer duration-300 ease-in-out rounded-lg p-2
            {{ $isSearched ? 'bg-yellow-100' : '' }}
            {{ $selectedCategory == $childCategory->name ? 'bg-yellow-200' : '' }}
            hover:bg-gray-100">
            <div class="flex items-center justify-between text-base text-gray-800 menu-item">
                <a href="{{ url('/bai-viet') }}?danh-muc={{ urlencode($childCategory->name) }}"
                   wire:click.prevent="setSelectedCategory('{{ $childCategory->name }}')"
                   class="flex cursor-pointer items-center w-full space-x-2">
                    <i class="fa-solid fa-circle text-primary mr-2" style="font-size: 8px;"></i>
                    <span class="text-sm">{{ $childCategory->name }}</span>
                </a>
                @if($hasChildren)
                    <button @click.stop="subOpen = !subOpen" class="focus:outline-none">
                        <i x-show="!subOpen" class="fa-solid fa-caret-down text-primary"></i>
                        <i x-show="subOpen" class="fa-solid fa-caret-up text-primary"></i>
                    </button>
                @endif
            </div>

            @if($hasChildren)
                <ul x-show="subOpen" x-transition class="mt-2 ml-4 space-y-2">
                    @include('bladethemev1::components.posts.sidebar.category', [
                        'parentCategory' => $childCategory,
                        'level' => $level + 1
                    ])
                </ul>
            @endif
        </li>
    @endforeach
</ul>
