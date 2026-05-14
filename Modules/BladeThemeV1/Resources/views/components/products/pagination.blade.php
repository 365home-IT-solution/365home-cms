<div class="mt-8 flex justify-center">
    <div class="mt-3 flex justify-center">
        @if($products->total() > 0)
            <div class="flex flex-col sm:flex-row justify-center sm:items-center gap-4 sm:gap-10 content-center align-middle">
                @if($products->lastPage() > 1)
                    <nav class="flex justify-center" aria-label="Pagination">
                        <ul class="flex flex-wrap justify-center gap-2 sm:gap-3">
                            {{-- Previous Button --}}
                            <li>
                                <button wire:click="previousPage"
                                        class="px-2 sm:px-4 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium paginate transition duration-150 ease-in-out flex items-center {{ $products->onFirstPage() ? 'opacity-50 cursor-not-allowed' : '' }}"
                                        {{ $products->onFirstPage() ? 'disabled' : '' }}>
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 mr-1 sm:mr-2" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                    Trước
                                </button>
                            </li>

                            {{-- Page Numbers --}}
                            @php
                                $window = 2; // Number of pages to show on each side of the current page
                                $start = max(1, $products->currentPage() - $window);
                                $end = min($products->lastPage(), $products->currentPage() + $window);
                            @endphp

                            @if($start > 1)
                                <li>
                                    <button wire:click="gotoPage(1)" class="px-2 sm:px-3 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium paginate transition duration-300">1</button>
                                </li>
                                @if($start > 2)
                                    <li class="px-2 sm:px-3 py-1 sm:py-2 colorPrimary transition duration-300">...</li>
                                @endif
                            @endif

                            @for($i = $start; $i <= $end; $i++)
                                <li>
                                    <button wire:click="gotoPage({{ $i }})"
                                            class="px-2 sm:px-3 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium transition duration-300 {{ $i === $products->currentPage() ? 'pagiItem' : 'paginate' }}">
                                        {{ $i }}
                                    </button>
                                </li>
                            @endfor

                            @if($end < $products->lastPage())
                                @if($end < $products->lastPage() - 1)
                                    <li class="px-2 sm:px-3 py-1 sm:py-2 colorPrimary transition duration-300">...</li>
                                @endif
                                <li>
                                    <button wire:click="gotoPage({{ $products->lastPage() }})" class="px-2 sm:px-3 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium transition duration-300 paginate">
                                        {{ $products->lastPage() }}
                                    </button>
                                </li>
                            @endif

                            {{-- Next Button --}}
                            <li>
                                <button wire:click="nextPage"
                                        class="px-2 sm:px-4 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium paginate transition duration-150 ease-in-out flex items-center {{ !$products->hasMorePages() ? 'opacity-50 cursor-not-allowed' : '' }}"
                                        {{ !$products->hasMorePages() ? 'disabled' : '' }}>
                                    Kế tiếp
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 ml-1 sm:ml-2" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                </button>
                            </li>
                        </ul>
                    </nav>
                @endif

                {{-- Page Info --}}
                <div class="text-sm text-gray-700 leading-5 text-center sm:text-left">
                    <span>Hiển thị</span>
                    <span class="font-medium">{{ $products->firstItem() }}</span>
                    <span>đến</span>
                    <span class="font-medium">{{ $products->lastItem() }}</span>
                    <span>trong</span>
                    <span class="font-medium">{{ $products->total() }}</span>
                    <span>kết quả</span>
                </div>
            </div>
        @endif
    </div>
</div>