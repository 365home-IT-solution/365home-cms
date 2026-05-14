<div class="w-full mx-auto bg-white rounded overflow-hidden shadow-lg p-4">
    <h2 class="text-lg font-semibold text-gray-800">Danh mục bài viết</h2>
    <div class="h-px bg-gray-200 w-full mb-4"></div>

    <div class="mb-2 flex items-center space-x-2">
        <input type="text" wire:model.debounce.300ms.live="searchCate" placeholder="Tìm kiếm danh mục..."
               class="w-full px-3 py-2 border-primary rounded-md">
        <button wire:click="resetAllFilters" class="px-4 py-3 space-x-2 bg-white text-primary border border-primary rounded-md">
            <i class="fa-solid fa-rotate-right"></i>
        </button>
    </div>

    <div class="w-full mx-auto bg-white border border-primary rounded-md overflow-hidden">
        <div class="p-3">
            <nav>
                <ul x-cloak class="space-y-4 filter-container">
                    @forelse($this->getCategories() as $parentCategory)
                        @php
                            $hasSearchedChild = $this->searchCate && $parentCategory->children->contains(function($child) {
                                return stripos($child->name, $this->searchCate) !== false;
                            });
                            $isSearchedParent = $this->searchCate && stripos($parentCategory->name, $this->searchCate) !== false;
                        @endphp
                            <!-- Level 1: No indentation -->
                        <li x-data="{ parentOpen: false }"
                            class="transition duration-300 ease-in-out hover:bg-gray-100 rounded-lg p-1 {{ $selectedCategory == $parentCategory->name ? 'bg-yellow-100' : '' }}">
                            <div class="flex items-center justify-between text-md text-gray-700">
                                <div wire:click="setSelectedCategory('{{ $parentCategory->name }}')"
                                     class="flex cursor-pointer items-center w-full">
                                    <i class="fa-regular fa-circle-dot text-primary mr-3 text-sm"></i>
                                    <span class="text-md text-gray-700">{{ $parentCategory->name }}</span>
                                </div>
                                @if($parentCategory->children->isNotEmpty())
                                    <button @click="parentOpen = !parentOpen" class="focus:outline-none">
                                        <i x-show="!parentOpen" class="fa-solid fa-square-caret-down text-primary"></i>
                                        <i x-show="parentOpen" class="fa-solid fa-square-caret-up text-primary"></i>
                                    </button>
                                @endif
                            </div>

                            @if($parentCategory->children->isNotEmpty())
                                <!-- Level 2: Indented -->
                                <ul x-show="parentOpen" x-transition class="ml-6 mt-2 space-y-2">
                                    @foreach($parentCategory->children as $childCategory)
                                        @php
                                            $isSearched = $this->searchCate && stripos($childCategory->name, $this->searchCate) !== false;
                                        @endphp
                                        <li x-data="{ childOpen: false }"
                                            class="transition cursor-pointer duration-300 ease-in-out rounded p-1
                                            {{ $isSearched ? 'bg-yellow-100' : ''}}
                                            {{ $selectedCategory == $childCategory->name ? 'bg-yellow-100' : '' }}">
                                            <div class="flex items-center justify-between text-base text-gray-800 menu-item">
                                                <div wire:click="setSelectedCategory('{{ $childCategory->name }}')"
                                                     class="flex items-center w-full">
                                                    <i class="fa-solid fa-circle text-primary mr-2" style="font-size: 5px;"></i>
                                                    <span>{{ $childCategory->name }}</span>
                                                </div>
                                                @if($childCategory->children->isNotEmpty())
                                                    <button @click.stop="childOpen = !childOpen" class="focus:outline-none">
                                                        <i x-show="!childOpen" class="fa-solid fa-square-caret-down text-primary"></i>
                                                        <i x-show="childOpen" class="fa-solid fa-square-caret-up text-primary"></i>
                                                    </button>
                                                @endif
                                            </div>

                                            @if($childCategory->children->isNotEmpty())
                                                <!-- Level 3+: No indentation -->
                                                <ul x-show="childOpen" x-transition class="mt-2 space-y-2">
                                                    @include('bladethemev1::components.posts.sidebar.category', [
                                                        'parentCategory' => $childCategory,
                                                        'level' => 3
                                                    ])
                                                </ul>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @empty
                        <li class="text-center text-gray-600 py-4">Không có danh mục!!!</li>
                    @endforelse
                </ul>
            </nav>
        </div>
    </div>
</div>
