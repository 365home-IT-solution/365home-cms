<div class="py-2 px-4 mb-4 bg-white rounded-lg rounded-t-lg border border-gray-200">

    @if ($isProduct)
        <h2 class="text-2xl font-bold mb-4 text-gray-800">Để lại một bình luận về sản phẩm này</h2>
    @elseif ($isPost)
        <h2 class="text-2xl font-bold mb-4 text-gray-800">Để lại một bình luận về bài viết này</h2>
    @endif


    <div x-data="{ showMessage: false, message: '', type: '' }" x-init="window.addEventListener('show-message', (event) => {
            showMessage = true;
            message = event.detail[0].message;
            type = event.detail[0].type;
            setTimeout(() => { showMessage = false }, 3000);
        })"
         x-show="showMessage"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 transform translate-x-full"
         x-transition:enter-end="opacity-100 transform translate-x-0"
         x-transition:leave="transition ease-in duration-300"
         x-transition:leave-start="opacity-100 transform translate-x-0"
         x-transition:leave-end="opacity-0 transform translate-x-full"
         class="fixed top-4 right-4 px-6 py-4 rounded-md text-lg flex items-center w-80 max-w-sm shadow-lg"
         :class="{ 'bg-green-500 bg-opacity-70': type === 'success', 'bg-red-500': type === 'error' }"
         style="z-index: 1000;">
        <svg viewBox="0 0 24 24" class="w-5 h-5 mr-3" :class="type === 'success' ? 'text-green-600' : 'text-red-600'">
            <path fill="currentColor"
                  d="M12,0A12,12,0,1,0,24,12,12.014,12.014,0,0,0,12,0Zm6.927,8.2-6.845,9.289a1.011,1.011,0,0,1-1.43.188L5.764,13.769a1,1,0,1,1,1.25-1.562l4.076,3.261,6.227-8.451A1,1,0,1,1,18.927,8.2Z">
            </path>
        </svg>
        <span class="flex-grow" x-text="message"></span>
    </div>


    <form wire:submit.prevent="submit" class="space-y-6">

        <div>
            <label for="name" class="block text-sm font-medium text-gray-700">
                Họ và Tên <span class="text-red-500">(*)</span>
            </label>
            <input type="text" id="name" wire:model="name"
                   class="mt-1 mb-2 block w-full p-3 border border-gray-800 rounded-md shadow-sm focus:outline-none focus:ring-black focus:border-black sm:text-sm">
            @error('name')
            <span class="text-red-500 font-bold mt-4 mb-4 text-sm">{{ $message }}</span>
            @enderror
        </div>


        <div>
            <label for="text" class="block text-sm font-medium text-gray-700">
                @if ($isProduct)
                    Gửi đánh giá của bạn đến với sản phẩm này <span class="text-red-500">(*)</span>
                @elseif ($isPost)
                    Gửi bình luận của bạn đến với bài viết này <span class="text-red-500">(*)</span>
                @endif
            </label>
            <textarea id="text" wire:model="text" rows="4"
                      class="mt-1 mb-2 block w-full p-3 border border-black rounded-md shadow-sm focus:outline-none focus:ring-black focus:border-black sm:text-sm"></textarea>
            @error('text')
            <span class="text-red-500 font-bold mt-4 text-sm">{{ $message }}</span>
            @enderror
        </div>

        <div class="flex justify-end mt-6">
            <button type="submit"
                    class="bg-primary text-white border border-primary flex justify-center items-center hover:bg-white hover:text-primary font-semibold py-2 px-4 rounded-lg transition-all duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-primary focus:ring-opacity-75"
                    wire:loading.attr="disabled"
                    wire:target="submit">

                <span wire:loading.remove wire:target="submit">{{ $form->submit_button_text ?? 'Gửi' }}</span>
                <span wire:loading wire:target="submit">Đang xử lý...</span>

                <div class="icon">
                    <svg height="24" width="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                         wire:loading.remove wire:target="submit">
                        <path d="M0 0h24v24H0z" fill="none"></path>
                        <path d="M16.172 11l-5.364-5.364 1.414-1.414L20 12l-7.778 7.778-1.414-1.414L16.172 13H4v-2z"
                              fill="currentColor"></path>
                    </svg>
                    <svg class="animate-spin" wire:loading wire:target="submit" height="24" width="24"
                         viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor"
                              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                        </path>
                    </svg>
                </div>
            </button>
        </div>

    </form>

    <div class="mb-6">
        @php
            $totalComments = count($comments);
            $totalReplies = 0;
            foreach ($comments as $comment) {
            if ($comment['show']) {
            $totalReplies += optional($comment['replies'])->where('show', true)->count() ?? 0;
            }
            }
            $totalComments = $totalComments + $totalReplies;
        @endphp

        @if($totalComments > 0)
            <div
                class="flex flex-col sm:flex-row justify-between sm:items-center gap-4 sm:gap-10 content-center align-middle mb-8 mt-6">
                <h2 class="text-lg lg:text-2xl font-bold text-gray-900">Cuộc thảo luận ({{ $totalComments }})</h2>

                @if($paginationInfo['lastPage'] > 1)
                    <nav class="flex justify-center" aria-label="Pagination">
                        <ul class="flex flex-wrap justify-center gap-2 sm:gap-3">
                            {{-- Nút Trước --}}
                            <li>
                                <button wire:click="gotoPage({{ max(1, $paginationInfo['currentPage'] - 1) }})"
                                        class="px-2 sm:px-4 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium border-2 border-primary bg-white text-primary hover:bg-red-100 transition duration-150 ease-in-out flex items-center {{ $paginationInfo['currentPage'] <= 1 ? 'opacity-50 cursor-not-allowed' : '' }}"
                                    {{ $paginationInfo['currentPage'] <= 1 ? 'disabled' : '' }}>
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 mr-1 sm:mr-2" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                    Trước
                                </button>
                            </li>

                            {{-- Các số trang --}}
                            @php
                                $visiblePages = 5;
                                $halfVisible = floor($visiblePages / 2);
                                $start = max(1, min($paginationInfo['currentPage'] - $halfVisible, $paginationInfo['lastPage'] -
                                $visiblePages + 1));
                                $end = min($paginationInfo['lastPage'], $start + $visiblePages - 1);
                            @endphp

                            @if($start > 1)
                                <li>
                                    <button wire:click="gotoPage(1)" class="px-2 sm:px-3 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium bg-white text-primary hover:bg-red-200 border-2 border-primary">1</button>
                                </li>
                                @if($start > 2)
                                    <li class="px-2 sm:px-3 py-1 sm:py-2 text-primary">...</li>
                                @endif
                            @endif

                            @for($i = $start; $i <= $end; $i++) <li>
                                <button wire:click="gotoPage({{ $i }})"
                                        class="px-2 sm:px-3 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium border-2 {{ $i === $paginationInfo['currentPage'] ? 'bg-primary text-white border-primary' : 'bg-white text-primary border-primary hover:bg-red-100' }}">
                                    {{ $i }}
                                </button>
                            </li>
                            @endfor

                            @if($end < $paginationInfo['lastPage']) @if($end < $paginationInfo['lastPage'] - 1) <li
                                class="px-2 sm:px-3 py-1 sm:py-2 text-primary">...</li>
                            @endif
                            <li>
                                <button wire:click="gotoPage({{ $paginationInfo['lastPage'] }})" class="px-2 sm:px-3 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium bg-white text-primary hover:bg-red-100 border-2 border-primary">
                                    {{ $paginationInfo['lastPage'] }}
                                </button>
                            </li>
                            @endif

                            {{-- Nút Kế tiếp --}}
                            <li>
                                <button wire:click="gotoPage({{ min($paginationInfo['lastPage'], $paginationInfo['currentPage'] + 1) }})"
                                        class="px-2 sm:px-4 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium border-2 border-primary bg-white text-primary hover:bg-red-100 transition duration-150 ease-in-out flex items-center {{ $paginationInfo['currentPage'] >= $paginationInfo['lastPage'] ? 'opacity-50 cursor-not-allowed' : '' }}"
                                    {{ $paginationInfo['currentPage'] >= $paginationInfo['lastPage'] ? 'disabled' : '' }}>
                                    Kế tiếp
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 ml-1 sm:ml-2" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                </button>
                            </li>
                        </ul>
                    </nav>
                @endif
            </div>
        @else
            <div class="flex justify-center items-center">
                <p class="text-center px-4 sm:px-6 py-3 rounded bg-primary text-white my-8">Chưa có bình luận nào.</p>
            </div>
        @endif
    </div>

    <div x-data="{ openReply: false, replyCommentId: null, replyCommentName: '' }">
        @foreach ($comments as $comment)
            <article class="p-6 mb-6 bg-white rounded-xl">
                @if($comment['pin'])
                    <div class="mb-4 text-red-500 text-md font-semibold">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 inline-block mr-1" viewBox="0 0 20 20"
                             fill="currentColor">
                            <path d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
                        </svg>
                        Bình luận được ghim
                    </div>
                @endif
                <footer class="flex flex-wrap justify-between items-center mb-4">
                    <div class="flex items-center space-x-4">
                        <div
                            class="w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center text-gray-500 font-bold">
                            {{ strtoupper(substr($comment['name'], 0, 1)) }}
                        </div>
                        <div>
                            <p class="text-lg font-semibold text-gray-900">
                                {{ $comment['name'] }}
                            </p>
                            <p class="text-sm text-gray-500">
                                <time pubdate datetime="{{ $comment['created_at'] }}" title="{{ $comment['created_at'] }}">
                                    {{ \Carbon\Carbon::parse($comment['created_at'])->locale('vi')->translatedFormat('d F, Y - H:i') }}
                                </time>
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-4 mt-4 sm:mt-0">
                        <button wire:click="openReplyModal({{ $comment['id'] }}, '{{ $comment['name'] }}')" type="button"
                                class="flex items-center text-sm text-primary hover:underline dark:text-gray-400 font-medium">
                            <svg class="mr-1.5 w-3.5 h-3.5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"
                                 fill="none" viewBox="0 0 20 18">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                      stroke-width="2"
                                      d="M5 5h5M5 8h2m6-3h2m-5 3h6m2-7H2a1 1 0 0 0-1 1v9a1 1 0 0 0 1 1h3v5l5-5h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1Z"></path>
                            </svg>
                            Phản hồi
                        </button>
                    </div>
                </footer>
                <p class="text-gray-700 mb-4">{{ $comment['text'] }}</p>
                @if ($comment['replies']->isNotEmpty())
                    <button id="toggleRepliesButton{{ $comment['id'] }}" onclick="toggleReplies({{ $comment['id'] }})"
                            class="flex items-center space-x-2 px-3 py-2 bg-primary text-white hover:bg-white hover:text-primary border border-primary rounded-lg transition-colors duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd"
                                  d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                  clip-rule="evenodd"/>
                        </svg>
                        <span class="text-white hover:text-primary">Xem phản hồi ({{ $comment['replies']->count() }})</span>
                    </button>
                @endif
                <div id="replies{{ $comment['id'] }}" class="mt-4 px-4 space-y-4 hidden {{ $comment['replies']->count() > 2 ? 'h-80 overflow-y-auto' : '' }}">

                    @foreach ($comment['replies'] as $reply)
                        @if ($reply['show'])
                            <article class="p-4 bg-gray-50 rounded-lg border border-gray-200 ">
                                @if($reply['pin'])
                                    <div class="mb-2 text-red-500 font-semibold">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 inline-block mr-1" viewBox="0 0 20 20"
                                             fill="currentColor">
                                            <path d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
                                        </svg>
                                        Phản hồi được ghim
                                    </div>
                                @endif
                                <footer class="flex flex-wrap justify-between items-center mb-2">
                                    <div class="flex items-center space-x-3">
                                        <div
                                            class="w-8 h-8 bg-blue-200 rounded-full flex items-center justify-center text-blue-600 font-bold text-sm">
                                            {{ strtoupper(substr($reply['name'], 0, 1)) }}
                                        </div>
                                        <div>
                                            <p class="text-sm font-semibold text-gray-900">{{ $reply['name'] }}</p>
                                            <p class="text-xs text-gray-500">
                                                <time pubdate datetime="{{ $reply['created_at'] }}" title="{{ $reply['created_at'] }}">
                                                    {{ \Carbon\Carbon::parse($reply['created_at'])->locale('vi')->translatedFormat('d F, Y - H:i') }}
                                                </time>
                                            </p>
                                        </div>
                                    </div>
                                </footer>
                                <p class="text-gray-700 text-sm">
                                    <span class="text-primary font-semibold">{{ $comment['name'] }}</span>
                                    {{ $reply['text'] }}
                                </p>
                            </article>
                        @endif
                    @endforeach

                </div>
            </article>
        @endforeach

        <!-- Phản hồi -->
        @if($showReplyModal)
            <div class="fixed inset-0 flex items-center justify-center z-50 bg-gray-600 bg-opacity-50">
                <div class="bg-white mx-4 p-6 rounded-lg shadow-lg w-full max-w-3xl relative">
                    <h2 class="text-lg font-semibold mb-4">Phản hồi đến: <span class="text-primary">{{ $replyCommentName }}</span></h2>

                    <button wire:click="closeReplyModal"
                            class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 focus:outline-none">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24"
                             stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>

                    <form wire:submit.prevent="submitReply" class="space-y-4">
                        <!-- Name input for reply -->
                        <div>
                            <label for="replyName" class="block text-sm font-medium text-gray-700">Họ và Tên <span class="text-primary">(*)</span></label>
                            <input type="text" id="replyName" wire:model.defer="replyName"
                                   class="mt-1 mb-2 block w-full p-3 border border-gray-800 rounded-md shadow-sm focus:outline-none focus:ring-black focus:border-black sm:text-sm">
                            @if(isset($replyErrors['replyName']))
                                <span class="text-primary font-bold mt-4 text-sm">{{ $replyErrors['replyName'][0] }}</span>
                            @endif
                        </div>

                        <div>
                            <label for="replyText" class="block text-sm font-medium text-gray-700">Nội dung phản hồi <span class="text-primary">(*)</span></label>
                            <textarea id="replyText" wire:model.defer="replyText" rows="4"
                                      class="mt-1 mb-2 block w-full p-3 border border-gray-800 rounded-md shadow-sm focus:outline-none focus:ring-black focus:border-black sm:text-sm"></textarea>
                            @if(isset($replyErrors['replyText']))
                                <span class="text-primary font-bold mt-4 text-sm">{{ $replyErrors['replyText'][0] }}</span>
                            @endif
                        </div>

                        <div class="button-main flex justify-end mt-6">
                            <button type="submit"
                                    wire:target="submitReply" wire:loading.attr="disabled"
                                    class="bg-primary text-white border border-primary flex justify-center items-center hover:bg-white hover:text-primary text-white font-semibold py-2 px-4 rounded-lg transition-all duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-primary focus:ring-opacity-75">
                                <span wire:loading.remove wire:target="submitReply">{{ $form->submit_button_text ?? 'Gửi' }}</span>
                                <span wire:loading wire:target="submitReply">Đang xử lý...</span>

                                <div class="icon">
                                    <svg height="24" width="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" wire:loading.remove wire:target="submitReply">
                                        <path d="M0 0h24v24H0z" fill="none"></path>
                                        <path d="M16.172 11l-5.364-5.364 1.414-1.414L20 12l-7.778 7.778-1.414-1.414L16.172 13H4v-2z" fill="currentColor"></path>
                                    </svg>
                                    <svg class="animate-spin" wire:loading wire:target="submitReply" height="24" width="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor"
                                              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                        </path>
                                    </svg>
                                </div>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>

    <div class="mb-2">
        @if($totalComments > 0)
            <div
                class="flex flex-col sm:flex-row justify-center sm:items-center gap-4 sm:gap-10 content-center align-middle mb-8 mt-6">
                @if($paginationInfo['lastPage'] > 1)
                    <nav class="flex justify-center" aria-label="Pagination">
                        <ul class="flex flex-wrap justify-center gap-2 sm:gap-3">
                            {{-- Nút Trước --}}
                            <li>
                                <button wire:click="gotoPage({{ max(1, $paginationInfo['currentPage'] - 1) }})"
                                        class="px-2 sm:px-4 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium border-2 border-primary bg-white text-primary hover:bg-red-100 transition duration-150 ease-in-out flex items-center {{ $paginationInfo['currentPage'] <= 1 ? 'opacity-50 cursor-not-allowed' : '' }}"
                                    {{ $paginationInfo['currentPage'] <= 1 ? 'disabled' : '' }}>
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 mr-1 sm:mr-2" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                    Trước
                                </button>
                            </li>

                            {{-- Các số trang --}}
                            @php
                                $visiblePages = 5;
                                $halfVisible = floor($visiblePages / 2);
                                $start = max(1, min($paginationInfo['currentPage'] - $halfVisible, $paginationInfo['lastPage'] -
                                $visiblePages + 1));
                                $end = min($paginationInfo['lastPage'], $start + $visiblePages - 1);
                            @endphp

                            @if($start > 1)
                                <li>
                                    <button wire:click="gotoPage(1)" class="px-2 sm:px-3 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium bg-white text-primary hover:bg-red-200 border-2 border-primary">1</button>
                                </li>
                                @if($start > 2)
                                    <li class="px-2 sm:px-3 py-1 sm:py-2 text-primary">...</li>
                                @endif
                            @endif

                            @for($i = $start; $i <= $end; $i++) <li>
                                <button wire:click="gotoPage({{ $i }})"
                                        class="px-2 sm:px-3 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium border-2 {{ $i === $paginationInfo['currentPage'] ? 'bg-primary text-white border-primary' : 'bg-white text-primary border-primary hover:bg-red-100' }}">
                                    {{ $i }}
                                </button>
                            </li>
                            @endfor

                            @if($end < $paginationInfo['lastPage']) @if($end < $paginationInfo['lastPage'] - 1) <li
                                class="px-2 sm:px-3 py-1 sm:py-2 text-primary">...</li>
                            @endif
                            <li>
                                <button wire:click="gotoPage({{ $paginationInfo['lastPage'] }})" class="px-2 sm:px-3 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium bg-white text-primary hover:bg-red-100 border-2 border-primary">
                                    {{ $paginationInfo['lastPage'] }}
                                </button>
                            </li>
                            @endif

                            {{-- Nút Kế tiếp --}}
                            <li>
                                <button wire:click="gotoPage({{ min($paginationInfo['lastPage'], $paginationInfo['currentPage'] + 1) }})"
                                        class="px-2 sm:px-4 py-1 sm:py-2 rounded-md text-xs sm:text-sm font-medium border-2 border-primary bg-white text-primary hover:bg-red-100 transition duration-150 ease-in-out flex items-center {{ $paginationInfo['currentPage'] >= $paginationInfo['lastPage'] ? 'opacity-50 cursor-not-allowed' : '' }}"
                                    {{ $paginationInfo['currentPage'] >= $paginationInfo['lastPage'] ? 'disabled' : '' }}>
                                    Kế tiếp
                                    <svg class="w-4 h-4 sm:w-5 sm:h-5 ml-1 sm:ml-2" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                </button>
                            </li>
                        </ul>
                    </nav>
                @endif
            </div>
        @endif
    </div>

    <style>
        @keyframes slideInRight {
            0% {
                transform: translateX(100%);
            }
            100% {
                transform: translateX(0);
            }
        }

        @keyframes fadeOut {
            0% {
                opacity: 1;
            }
            100% {
                opacity: 0;
            }
        }

        .animation-slide-in-right {
            animation: slideInRight 0.5s ease forwards, fadeOut 2s 2.5s ease forwards;
        }
    </style>
    <script>
        function toggleReplies(commentId) {
            const replies = document.getElementById(`replies${commentId}`);
            const toggleButton = document.getElementById(`toggleRepliesButton${commentId}`);
            const repliesCount = replies.childElementCount;

            replies.classList.toggle('hidden');

            // Thay đổi văn bản nút
            if (replies.classList.contains('hidden')) {
                toggleButton.textContent = `Xem phản hồi (${repliesCount})`;
            } else {
                toggleButton.textContent = 'Ẩn bình luận';
            }
        }
    </script>
</div>
