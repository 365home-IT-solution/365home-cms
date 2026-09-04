<div class="p-4 border-b border-gray-100">
    <div class="text-lg font-semibold text-gray-800 mb-3">Tìm kiếm và Sắp xếp</div>
    <div class="flex flex-wrap items-center justify-between">
        <div class="w-full md:w-auto mb-4 md:mb-0">
            <span class="font-medium mr-2 mb-2 text-sm text-gray-700">Sắp xếp theo:</span>
            <div class="inline-flex rounded-md shadow-sm mt-2" role="group">
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
        <div class="w-full md:w-auto md:flex items-center justify-between">
            <div class="relative flex">
                <input wire:model.live="search" type="search" name="tim-kiem-bai-viet"
                       autocomplete="off" data-lpignore="true" data-1p-ignore data-form-type="other"
                       aria-label="Tìm kiếm bài viết" placeholder="Tìm kiếm bài viết..."
                       class="w-full md:w-64 pl-10 pr-4 py-2 focus:ring-0 focus:ring-offset-0 border-gray-300 focus:border-primary rounded-md text-sm focus:outline-none transition-all duration-200">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">

                    <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                         viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd"
                              d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z"
                              clip-rule="evenodd"/>
                    </svg>

                </div>
            </div>
            <div class="rounded-md shadow-sm ml-2 md:inline-flex hidden" role="group">
                <button type="button"
                        wire:click="setStyle('grid')"
                        class="inline-flex items-center p-2 text-sm font-medium bg-transparent rounded-s-lg transition-colors duration-200 ease-in-out {{ $currentStyle === 'grid' ? 'text-white bg-primary' : 'bg-white text-primary border-primary border' }}">
                    <svg class="w-5 h-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"
                         width="24"
                         height="24"
                         fill="none" viewBox="0 0 24 24">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                              stroke-width="2"
                              d="M9.143 4H4.857A.857.857 0 0 0 4 4.857v4.286c0 .473.384.857.857.857h4.286A.857.857 0 0 0 10 9.143V4.857A.857.857 0 0 0 9.143 4Zm10 0h-4.286a.857.857 0 0 0-.857.857v4.286c0 .473.384.857.857.857h4.286A.857.857 0 0 0 20 9.143V4.857A.857.857 0 0 0 19.143 4Zm-10 10H4.857a.857.857 0 0 0-.857.857v4.286c0 .473.384.857.857.857h4.286a.857.857 0 0 0 .857-.857v-4.286A.857.857 0 0 0 9.143 14Zm10 0h-4.286a.857.857 0 0 0-.857.857v4.286c0 .473.384.857.857.857h4.286a.857.857 0 0 0 .857-.857v-4.286a.857.857 0 0 0-.857-.857Z"/>
                    </svg>
                </button>
                <button type="button"
                        wire:click="setStyle('list')"
                        class="inline-flex items-center p-2 text-sm font-medium bg-transparent rounded-e-lg transition-colors duration-200 ease-in-out {{ $currentStyle === 'list' ? 'text-white bg-primary' : 'bg-white text-primary border-primary border' }}">
                    <svg class="w-5 h-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"
                         width="24"
                         height="24"
                         fill="none" viewBox="0 0 24 24">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                              stroke-width="2"
                              d="M11 9h6m-6 3h6m-6 3h6M6.996 9h.01m-.01 3h.01m-.01 3h.01M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>
</div>
