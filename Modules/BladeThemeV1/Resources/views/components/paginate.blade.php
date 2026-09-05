<div class="mt-8 flex justify-center">
    @if($items->total() > 0)
        <div class="flex flex-col sm:flex-row justify-center sm:items-center gap-4 sm:gap-10 content-center align-middle">
            @if($items->lastPage() > 1)
                <nav class="flex justify-center" aria-label="Pagination">
                    <ul class="flex flex-wrap justify-center gap-2 sm:gap-3">
                        {{-- Previous Button — <a href> thật (không phải <button> chỉ có wire:click) để
                             Googlebot/site-audit tool theo được sang trang kế, tránh các trang từ trang
                             2 trở đi bị "orphaned" (không tìm thấy qua link nội bộ, chỉ có trong sitemap). --}}
                        <li>
                            @if($items->onFirstPage())
                                <span aria-disabled="true"
                                      class="px-2 sm:px-4 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium paginate opacity-50 cursor-not-allowed flex items-center">
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 mr-1 sm:mr-2" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                    Trước
                                </span>
                            @else
                                <a href="{{ $items->previousPageUrl() }}" wire:click.prevent="previousPage"
                                   class="px-2 sm:px-4 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium paginate transition duration-150 ease-in-out flex items-center">
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 mr-1 sm:mr-2" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                    Trước
                                </a>
                            @endif
                        </li>

                        {{-- Page Numbers --}}
                        @php
                            $window = 2; // Number of pages to show on each side of the current page
                            $start = max(1, $items->currentPage() - $window);
                            $end = min($items->lastPage(), $items->currentPage() + $window);
                        @endphp

                        @if($start > 1)
                            <li>
                                <a href="{{ $items->url(1) }}" wire:click.prevent="gotoPage(1)"
                                   class="px-2 sm:px-3 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium paginate">
                                    1
                                </a>
                            </li>
                            @if($start > 2)
                                <li class="px-2 sm:px-3 py-1 sm:py-2 colorPrimary">...</li>
                            @endif
                        @endif

                        @for($i = $start; $i <= $end; $i++)
                            <li>
                                <a href="{{ $items->url($i) }}" wire:click.prevent="gotoPage({{ $i }})"
                                   class="px-2 sm:px-3 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium {{ $i === $items->currentPage() ? 'pagiItem' : 'paginate' }}">
                                    {{ $i }}
                                </a>
                            </li>
                        @endfor

                        @if($end < $items->lastPage())
                            @if($end < $items->lastPage() - 1)
                                <li class="px-2 sm:px-3 py-1 sm:py-2 colorPrimary">...</li>
                            @endif
                            <li>
                                <a href="{{ $items->url($items->lastPage()) }}" wire:click.prevent="gotoPage({{ $items->lastPage() }})"
                                   class="px-2 sm:px-3 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium paginate">
                                    {{ $items->lastPage() }}
                                </a>
                            </li>
                        @endif

                        {{-- Next Button --}}
                        <li>
                            @if(!$items->hasMorePages())
                                <span aria-disabled="true"
                                      class="px-2 sm:px-4 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium paginate opacity-50 cursor-not-allowed flex items-center">
                                    Kế tiếp
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 ml-1 sm:ml-2" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                </span>
                            @else
                                <a href="{{ $items->nextPageUrl() }}" wire:click.prevent="nextPage"
                                   class="px-2 sm:px-4 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium paginate transition duration-150 ease-in-out flex items-center">
                                    Kế tiếp
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 ml-1 sm:ml-2" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                </a>
                            @endif
                        </li>
                    </ul>
                </nav>
            @endif
        </div>
    @endif
</div>
