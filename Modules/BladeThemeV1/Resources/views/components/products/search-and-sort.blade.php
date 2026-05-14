@props([
    'is_virtual_product',
    'sortBy',
    'viewStyle'
])
<div class="mb-4 bg-white rounded shadow-lg overflow-hidden border border-gray-100">
    <div class="p-4 border-b border-gray-100">
        <div class="text-lg font-semibold text-gray-800 mb-3">Tìm kiếm và Sắp xếp</div>
        <div class="flex flex-wrap items-center justify-between">
            <div class="w-full md:w-auto mb-4 md:mb-0">
                <span class="font-medium mr-2 text-sm text-gray-700">Sắp xếp theo:</span>
                <div class="inline-flex rounded-md shadow-sm" role="group">
                    <button wire:click="$set('sortBy', 'A-Z')"
                        class="px-4 py-2 transition duration-300 text-sm font-medium rounded-l-lg border border-gray-200 focus:z-10 focus:ring-2 focus:colorPrimary focus:colorPrimary
                            {{ $sortBy == 'A-Z' ? 'pagiItem' : 'paginate' }}">
                        A - Z
                    </button>
                    <button wire:click="$set('sortBy', 'Z-A')"
                        class="px-4 py-2 transition duration-300 text-sm font-medium border-t border-b border-gray-200 focus:z-10 focus:ring-2 focus:colorPrimary focus:colorPrimary
                            {{ $sortBy == 'Z-A' ? 'pagiItem' : 'paginate' }}">
                        Z - A
                    </button>
                    <button wire:click="$set('sortBy', 'newest')"
                        class="px-4 py-2 transition duration-300 text-sm font-medium rounded-r-md border border-gray-200 focus:z-10 focus:ring-2 focus:colorPrimary focus:colorPrimary
                            {{ $sortBy == 'newest' ? 'pagiItem' : 'paginate' }}">
                        Mới nhất
                    </button>
                </div>
            </div>

            <div class="w-full md:w-auto flex flex-wrap items-center justify-between">
                <div class="relative inline-flex max-md:w-full">
                    <input wire:model.live="search" type="text" placeholder="Tìm kiếm sản phẩm..."
                        class="w-full md:w-64 pl-10 pr-4 py-2 border-gray-300 focus:ring-0 focus:ring-offset-0 focus:border-primary rounded-md text-sm focus:outline-none transition-all duration-200">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                            fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd"
                                d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z"
                                clip-rule="evenodd" />
                        </svg>
                    </div>
                </div>
                @if (!$is_virtual_product)
                    <div class="md:inline-flex hidden rounded-md shadow-sm ml-2" role="group">
                        <button wire:click="$set('viewStyle', 'grid')"
                            class="p-2 text-sm font-medium rounded-l-lg border border-gray-200 focus:z-10 focus:ring-2 focus:colorPrimary transition-all duration-200
                            {{ $viewStyle == 'grid' ? 'pagiItem' : 'paginate' }}">
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="currentColor"
                                viewBox="0 0 20 20">
                                <path d="M2 2h6v6H2V2zm0 10h6v6H2v-6zm10-10h6v6h-6V2zm0 10h6v6h-6v-6z" />
                            </svg>
                        </button>
                        <button wire:click="$set('viewStyle', 'list')"
                            class="p-2 text-sm font-medium rounded-r-md border border-gray-200 focus:z-10 focus:ring-2 focus:colorPrimary transition-all duration-200
                            {{ $viewStyle == 'list' ? 'pagiItem' : 'paginate' }}">
                            <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="currentColor"
                                viewBox="0 0 20 20">
                                <path d="M3 3h14v2H3V3zm0 5h14v2H3V8zm0 5h14v2H3v-2z" />
                            </svg>
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
