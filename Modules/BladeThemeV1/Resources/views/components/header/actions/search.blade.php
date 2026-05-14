<div class="relative">
    <button
        type="button"
        data-dropdown-toggle="search-dropdown"
        class="p-2 hover:bg-gray-100 rounded-full transition-colors duration-200">
        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
             stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        </svg>
    </button>

    <!-- Search Dropdown -->
    <div id="search-dropdown"
         x-data="{
                                    search: '',
                                    handleSearch() {
                                        if (this.search.trim()) {
                                            window.location.href = '/san-pham/tim-kiem?s=' + encodeURIComponent(this.search);
                                        }
                                    }
                                 }"
         class="hidden z-50 w-72 p-3 bg-white rounded-lg shadow-xl">
        <div class="relative">
            <input type="text"
                   x-model="search"
                   @keyup.enter="handleSearch()"
                   class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary"
                   placeholder="Tìm kiếm...">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <svg class="w-5 h-5 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                     fill="none"
                     viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>
        </div>
    </div>

</div>
