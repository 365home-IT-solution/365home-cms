<div x-data="{
    open: $persist(true).as('category-menu-open'),
    search: ''
}" class="w-full lg:w-3/12">
    <div class="bg-white rounded shadow-lg overflow-hidden border border-gray-100">
        <div class="p-4 border-b border-gray-100">
            <div class="flex items-center justify-between mb-3">
                <div class="text-lg font-semibold text-gray-800">Danh mục</div>
                <button @click="open = !open"
                        class="lg:hidden text-gray-500 hover:text-gray-700 transition-colors duration-200 focus:outline-none">
                    <svg class="h-5 w-5" x-show="!open" xmlns="http://www.w3.org/2000/svg" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                    <svg class="h-5 w-5" x-show="open" xmlns="http://www.w3.org/2000/svg" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="relative">
                <input wire:model.live="categorySearch" x-model="search" type="text"
                       placeholder="Tìm kiếm danh mục..."
                       class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md text-sm focus:ring-0 focus:ring-offset-0 focus:border-primary focus:outline-none transition-all duration-200">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                         fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd"
                              d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z"
                              clip-rule="evenodd" />
                    </svg>
                </div>
            </div>
        </div>
        <div x-show="open" x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 transform -translate-y-2"
             x-transition:enter-end="opacity-100 transform translate-y-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 transform translate-y-0"
             x-transition:leave-end="opacity-0 transform -translate-y-2" class="p-4 bg-white lg:!block">
            <div class="space-y-2">
                <div class="flex items-center group">
                    <input wire:model.live="selectedCategory" id="category-all" value="" name="category"
                           type="radio"
                           class="h-4 w-4 colorPrimary focus:colorPrimary border-gray-300 transition-all duration-200 ease-in-out">
                    <label for="category-all"
                           class="ml-3 block text-sm font-medium text-gray-700 group-hover:colorPrimary transition-colors duration-200">Tất
                        cả</label>
                </div>
                @foreach ($filteredCategories as $category)
                    <x-bladethemev1::category-item :category="$category" :level="0" :primaryColor="$primaryColor"/>
                @endforeach
                @if ($filteredCategories->isEmpty())
                    <p class="text-sm text-gray-500">Không tìm thấy danh mục nào.</p>
                @endif
            </div>
        </div>
    </div>
</div>