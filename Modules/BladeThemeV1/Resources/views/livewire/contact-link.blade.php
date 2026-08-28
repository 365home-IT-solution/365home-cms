<style>
    /* Mặc định z-index cao để đè lên mọi thứ */
    .custom-floating-contact {
        z-index: 9999 !important;
    }

    .custom-floating-contact.hide-for-modal {
        display: none !important;
    }

    /* Chỉ áp dụng cho màn hình nhỏ hơn 768px (Mobile/Tablet dọc) */
    @media (max-width: 768px) {
        .custom-floating-contact {
            /* bottom-5 của tailwind là 1.25rem (20px) */
            /* Ta đè nó bằng 90px để né thanh sidebar dưới */
            bottom: 90px !important;
        }
    }
</style>
<div x-cloak x-data="{ open: false }" x-on:amenities-modal-open.window="$el.classList.add('hide-for-modal')"
    x-on:amenities-modal-close.window="$el.classList.remove('hide-for-modal')"
    class="custom-floating-contact fixed right-4 bottom-5 z-50 flex flex-col items-end gap-3">
    <!-- Contact Menu -->
    <div x-show="open" x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-2" class="flex flex-col items-end">

        <!-- Contact Options -->
        <div class="flex flex-col gap-3 items-end">
            @foreach ($contact_button['contact_button'] as $contact)
            @if ($contact['data']['visible'])
            @switch($contact['type'])
            @case('hotline')
            <a href="tel:{{ $contact['data']['value'] }}" class="flex items-center group">
                <span class="bg-white px-3 py-2 rounded-lg shadow-lg text-sm font-medium mr-2">
                                    {{ $contact['data']['value'] }}
                                </span>
                <div
                    class="{{ !$contact['data']['icon'] ? 'md:w-14 md:h-14 w-10 h-10' : '' }} bg-red-500 hover:bg-red-600 rounded-full shadow-lg flex items-center justify-center text-white">
                    @if ($contact['data']['icon'])
                    <img src="{{ Storage::url($contact['data']['icon']) }}" alt="hotline"
                                            class="md:w-14 md:h-14 w-10 h-10">
                                    @else
                    <svg xmlns="http://www.w3.org/2000/svg" class="md:h-6 md:w-6 h-5 w-5" fill="none"
                        viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                    </svg>
                    @endif
                </div>
            </a>
            @break

            @case('email')
            <a href="mailto:{{ $contact['data']['value'] }}" class="flex items-center group">
                <span class="bg-white px-3 py-2 rounded-lg shadow-lg text-sm font-medium mr-2">
                                    {{ $contact['data']['value'] }}
                                </span>
                <div
                    class="{{ !$contact['data']['icon'] ? 'md:w-14 md:h-14 w-10 h-10' : '' }} bg-blue-500 hover:bg-blue-600 rounded-full shadow-lg flex items-center justify-center text-white">
                    @if ($contact['data']['icon'])
                    <img src="{{ Storage::url($contact['data']['icon']) }}" alt="email"
                                            class="md:w-14 md:h-14 w-10 h-10">
                                    @else
                    <svg xmlns="http://www.w3.org/2000/svg" class="md:h-6 md:w-6 h-5 w-5" fill="none"
                        viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    @endif
                </div>
            </a>
            @break

            @case('zalo')
            <a href="https://zalo.me/{{ $contact['data']['value'] }}" target="_blank" class="flex items-center group">
                <span class="bg-white px-3 py-2 rounded-lg shadow-lg text-sm font-medium mr-2">
                                    Chat Zalo
                                </span>
                <div
                    class="{{ !$contact['data']['icon'] ? 'md:w-14 md:h-14 w-10 h-10' : '' }} bg-[#0068FF] hover:bg-[#0054CC] rounded-full shadow-lg flex items-center justify-center text-white">
                    @if ($contact['data']['icon'])
                    <img src="{{ Storage::url($contact['data']['icon']) }}" alt="zalo"
                                            class="md:w-14 md:h-14 w-10 h-10">
                                    @else
                    <span class="font-bold text-lg">Z</span>
                    @endif
                </div>
            </a>
            @break

            @case('messenger')
            <a href="{{ $contact['data']['value'] }}" target="_blank" class="flex items-center group">
                <span class="bg-white px-3 py-2 rounded-lg shadow-lg text-sm font-medium mr-2">
                                    Chat Messenger
                                </span>
                <div
                    class="{{ !$contact['data']['icon'] ? 'md:w-14 md:h-14 w-10 h-10' : '' }} bg-blue-600 hover:bg-blue-700 rounded-full shadow-lg flex items-center justify-center text-white">
                    @if ($contact['data']['icon'])
                    <img src="{{ Storage::url($contact['data']['icon']) }}" alt="messenger"
                                            class="md:w-14 md:h-14 w-10 h-10">
                                    @else
                    <svg xmlns="http://www.w3.org/2000/svg" class="md:h-6 md:w-6 h-5 w-5" fill="none"
                        viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                    </svg>
                    @endif
                </div>
            </a>
            @break
            @endswitch
            @endif
            @endforeach
        </div>
    </div>

    <!-- Back To Top -->
    <button type="button" aria-label="Cuộn lên đầu trang" x-data="{ show: false }" x-init="window.addEventListener('scroll', () => { show = window.scrollY > 300 })" x-show="show"
        @click="window.scrollTo({top: 0, behavior: 'smooth'})"
        class="md:w-14 md:h-14 h-10 w-10 bg-white rounded-full shadow-lg hover:shadow-xl transition-all flex items-center justify-center text-gray-600 hover:text-primary">
        <svg xmlns="http://www.w3.org/2000/svg" class="md:h-6 md:w-6 w-5 h-5" fill="none" viewBox="0 0 24 24"
            stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18" />
        </svg>
    </button>

    <!-- Toggle Button -->
    <button type="button" @click="open = !open" aria-label="Mở các kênh liên hệ"
        :aria-expanded="open.toString()"
        class="md:w-14 md:h-14 w-10 h-10 bg-primary rounded-full shadow-xl flex items-center justify-center text-white relative transition-all duration-300 hover:scale-105">
        <!-- Icon khi đóng -->
        <div x-show="!open" class="flex items-center justify-center relative">
            <span class="absolute inset-0 rounded-full animate-pulse bg-white/20"></span>
            <svg xmlns="http://www.w3.org/2000/svg" class="md:h-6 md:w-6 h-5 w-5" fill="none" viewBox="0 0 24 24"
                stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
            </svg>
        </div>

        <!-- Icon khi mở -->
        <div x-show="open" class="transform transition-transform duration-300">
            <svg xmlns="http://www.w3.org/2000/svg" class="md:h-6 md:w-6 h-5 w-5" viewBox="0 0 24 24" fill="none"
                stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </div>

        <!-- Notification Dot -->
        <span class="absolute -top-1 -right-1 flex h-4 w-4">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-50"></span>
            <span class="relative inline-flex rounded-full h-4 w-4 bg-red-500"></span>
        </span>
    </button>
</div>
