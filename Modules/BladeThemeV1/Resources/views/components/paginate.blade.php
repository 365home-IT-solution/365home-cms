<div class="mt-8 flex justify-center">
    @if($items->total() > 0)
        <div class="flex flex-col sm:flex-row justify-center sm:items-center gap-4 sm:gap-10 content-center align-middle">
            @if($items->lastPage() > 1)
                <nav class="flex justify-center" aria-label="Pagination">
                    <ul class="flex flex-wrap justify-center gap-2 sm:gap-3">
                        {{-- Previous Button --}}
                        <li>
                            <button wire:click="previousPage"
                                    class="px-2 sm:px-4 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium paginate transition duration-150 ease-in-out flex items-center {{ $items->onFirstPage() ? 'opacity-50 cursor-not-allowed' : '' }}"
                                    {{ $items->onFirstPage() ? 'disabled' : '' }}>
                                <svg class="w-4 h-4 sm:w-5 sm:h-5 mr-1 sm:mr-2" fill="currentColor"
                                     viewBox="0 0 20 20">
                                    <path fill-rule="evenodd"
                                          d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z"
                                          clip-rule="evenodd"></path>
                                </svg>
                                Trước
                            </button>
                        </li>

                        {{-- Page Numbers --}}
                        @php
                            $window = 2; // Number of pages to show on each side of the current page
                            $start = max(1, $items->currentPage() - $window);
                            $end = min($items->lastPage(), $items->currentPage() + $window);
                        @endphp

                        @if($start > 1)
                            <li>
                                <button wire:click="gotoPage(1)"
                                        class="px-2 sm:px-3 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium paginate">
                                    1
                                </button>
                            </li>
                            @if($start > 2)
                                <li class="px-2 sm:px-3 py-1 sm:py-2 colorPrimary">...</li>
                            @endif
                        @endif

                        @for($i = $start; $i <= $end; $i++)
                            <li>
                                <button wire:click="gotoPage({{ $i }})"
                                        class="px-2 sm:px-3 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium {{ $i === $items->currentPage() ? 'pagiItem' : 'paginate' }}">
                                    {{ $i }}
                                </button>
                            </li>
                        @endfor

                        @if($end < $items->lastPage())
                            @if($end < $items->lastPage() - 1)
                                <li class="px-2 sm:px-3 py-1 sm:py-2 colorPrimary">...</li>
                            @endif
                            <li>
                                <button wire:click="gotoPage({{ $items->lastPage() }})"
                                        class="px-2 sm:px-3 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium paginate">
                                    {{ $items->lastPage() }}
                                </button>
                            </li>
                        @endif

                        {{-- Next Button --}}
                        <li>
                            <button wire:click="nextPage"
                                    class="px-2 sm:px-4 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium paginate transition duration-150 ease-in-out flex items-center {{ !$items->hasMorePages() ? 'opacity-50 cursor-not-allowed' : '' }}"
                                    {{ !$items->hasMorePages() ? 'disabled' : '' }}>
                                Kế tiếp
                                <svg class="w-4 h-4 sm:w-5 sm:h-5 ml-1 sm:ml-2" fill="currentColor"
                                     viewBox="0 0 20 20">
                                    <path fill-rule="evenodd"
                                          d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                          clip-rule="evenodd"></path>
                                </svg>
                            </button>
                        </li>
                    </ul>
                </nav>
            @endif
        </div>
    @endif
</div>
