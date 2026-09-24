<x-filament-panels::page>
    {{-- 3 cột — mirror App\Filament\Pages\CustomerChat (Home): danh sách hội thoại / khung chat /
         hợp đồng (thay cho cột "orders" của Home), xem Modules\Minihouse\App\Services\MinihouseChatService. --}}
    <div x-data="{ mobileView: 'list' }" class="flex gap-4 overflow-hidden rounded-xl" style="height: calc(100vh - 13rem)">

        {{-- ── Left: Danh sách hội thoại ──────────────────────────── --}}
        <div
            :class="mobileView === 'list' ? 'flex' : 'hidden lg:flex'"
            class="w-full lg:w-80 flex-col flex-shrink-0 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden"
        >
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <span class="font-semibold text-sm text-gray-900 dark:text-white">Danh sách</span>
                @php($totalUnread = collect($conversations)->sum('unread'))
                @if ($totalUnread > 0)
                    <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1 text-xs font-bold bg-red-500 text-white rounded-full">
                        {{ min($totalUnread, 99) }}
                    </span>
                @endif
            </div>

            <div wire:poll.8000ms="loadConversations" class="flex-1 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($conversations as $conv)
                    <button
                        wire:key="conv-{{ $conv['id'] }}"
                        wire:click="selectConversation('{{ $conv['id'] }}')"
                        wire:loading.class="opacity-50"
                        wire:target="selectConversation('{{ $conv['id'] }}')"
                        @click="mobileView = 'chat'"
                        class="w-full p-3 text-left transition-colors {{ $selectedId === $conv['id'] ? 'bg-primary-50 dark:bg-primary-900/20' : 'hover:bg-gray-50 dark:hover:bg-gray-800/60' }}"
                    >
                        <div class="flex items-start justify-between gap-1">
                            <span class="text-sm font-medium text-gray-900 dark:text-white truncate leading-5">
                                {{ $conv['tenant']['fullname'] }}
                            </span>
                            @if ($conv['unread'] > 0)
                                <span class="flex-shrink-0 inline-flex items-center justify-center min-w-[1.2rem] h-5 px-1 text-xs font-bold bg-primary-600 text-white rounded-full">
                                    {{ $conv['unread'] }}
                                </span>
                            @endif
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 truncate mt-0.5">
                            {{ $conv['preview'] ?? 'Chưa có tin nhắn' }}
                        </div>
                        @if ($conv['at'])
                            <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ $conv['at'] }}</div>
                        @endif
                    </button>
                @empty
                    <div class="p-6 text-center text-sm text-gray-400 dark:text-gray-500">
                        Chưa có cuộc trò chuyện nào
                    </div>
                @endforelse
            </div>
        </div>

        {{-- ── Right: Khung chat ──────────────────────────────────────── --}}
        <div
            :class="mobileView === 'chat' ? 'flex' : 'hidden lg:flex'"
            class="flex-1 flex-col min-w-0 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden"
        >
            @if ($selectedConversation)
                <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
                    <button type="button" @click="mobileView = 'list'" class="lg:hidden flex-shrink-0 w-8 h-8 -ml-1 rounded-full flex items-center justify-center text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800">
                        <x-heroicon-o-chevron-left class="w-5 h-5" />
                    </button>
                    <div class="w-9 h-9 rounded-full bg-primary-100 dark:bg-primary-900/40 flex items-center justify-center text-primary-700 dark:text-primary-300 font-bold text-sm flex-shrink-0">
                        {{ mb_strtoupper(mb_substr($selectedConversation['tenant']['fullname'], 0, 1)) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold text-sm text-gray-900 dark:text-white truncate">
                            {{ $selectedConversation['tenant']['fullname'] }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $selectedConversation['tenant']['phone'] }}
                        </div>
                    </div>
                </div>

                <div
                    wire:poll.3000ms="checkNewMessages"
                    id="mh-chat-messages-scroll"
                    x-init="$el.scrollTop = $el.scrollHeight"
                    x-on:scrolltobottom.window="setTimeout(() => $el.scrollTop = $el.scrollHeight, 60)"
                    class="flex-1 overflow-y-auto px-4 py-4 space-y-3"
                >
                    @if ($hasMore)
                        <div class="text-center">
                            <button type="button" wire:click="loadOlder" class="text-xs text-primary-600 hover:underline">
                                Tải tin nhắn cũ hơn
                            </button>
                        </div>
                    @endif

                    @forelse ($messages as $msg)
                        <div wire:key="msg-{{ $msg['id'] }}" class="flex {{ $msg['sender_type'] === 'admin' ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-xs sm:max-w-sm lg:max-w-md xl:max-w-lg">
                                @if ($msg['sender_type'] === 'admin' && $msg['sender_name'])
                                    <div class="text-xs text-gray-400 dark:text-gray-500 mb-0.5 text-right">{{ $msg['sender_name'] }}</div>
                                @endif
                                <div class="px-3.5 py-2 text-sm leading-relaxed rounded-2xl {{ $msg['sender_type'] === 'admin' ? 'bg-primary-600 text-white rounded-tr-sm' : 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-tl-sm' }}">
                                    {!! nl2br(e($msg['body'])) !!}
                                </div>
                                <div class="flex items-center gap-1 mt-1 {{ $msg['sender_type'] === 'admin' ? 'justify-end' : 'justify-start' }}">
                                    <span class="text-xs text-gray-400 dark:text-gray-500">{{ $msg['time'] }}</span>
                                    @if ($msg['sender_type'] === 'admin')
                                        <span class="text-xs {{ $msg['read_at'] ? 'text-primary-500' : 'text-gray-400 dark:text-gray-600' }}">
                                            {{ $msg['read_at'] ? '✓✓' : '✓' }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="flex-1 flex flex-col items-center justify-center gap-2 py-16 text-gray-400 dark:text-gray-500 select-none">
                            <x-heroicon-o-chat-bubble-left class="w-10 h-10 opacity-25" />
                            <p class="text-sm">Chưa có tin nhắn nào</p>
                        </div>
                    @endforelse
                </div>

                <div class="flex items-end gap-2 px-4 py-3 border-t border-gray-200 dark:border-gray-700 flex-shrink-0">
                    <textarea
                        wire:model="draft"
                        wire:keydown.enter.prevent="sendMessage"
                        rows="1"
                        placeholder="Nhập tin nhắn... (Enter để gửi)"
                        class="flex-1 resize-none rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:focus:ring-primary-400"
                        style="max-height: 8rem; overflow-y: auto"
                    ></textarea>
                    <button
                        wire:click="sendMessage"
                        wire:loading.attr="disabled"
                        wire:target="sendMessage"
                        class="flex-shrink-0 inline-flex items-center gap-1.5 bg-primary-600 hover:bg-primary-700 active:bg-primary-800 disabled:opacity-60 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors"
                    >
                        <x-heroicon-m-paper-airplane class="w-4 h-4" wire:loading.class="hidden" wire:target="sendMessage" />
                        <x-heroicon-o-arrow-path class="w-4 h-4 animate-spin hidden" wire:loading.class.remove="hidden" wire:target="sendMessage" />
                        Gửi
                    </button>
                </div>
            @else
                <div class="flex-1 flex flex-col items-center justify-center gap-3 text-gray-400 dark:text-gray-500 select-none">
                    <x-heroicon-o-chat-bubble-left-right class="w-16 h-16 opacity-25" />
                    <p class="text-sm">Chọn một cuộc trò chuyện để bắt đầu</p>
                </div>
            @endif
        </div>

        {{-- ── Cột 3: Hợp đồng (mirror cột "orders" của Home) ─────────── --}}
        @if ($selectedConversation)
            <div
                :class="mobileView === 'orders' ? 'flex' : 'hidden xl:flex'"
                class="w-full xl:w-72 flex-col flex-shrink-0 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden"
            >
                <div class="flex items-center gap-2 px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
                    <x-heroicon-o-document-text class="w-4 h-4 text-primary-600" />
                    <span class="font-semibold text-sm text-gray-900 dark:text-white">
                        Hợp đồng
                        @if (count($tenantContracts) > 0)
                            <span class="ml-1 text-xs font-normal text-gray-400">({{ count($tenantContracts) }})</span>
                        @endif
                    </span>
                </div>

                @php
                    $contractStatusMap = [
                        'active'    => ['label' => 'Đang hiệu lực', 'color' => 'text-green-700 bg-green-50 border-green-200'],
                        'expired'   => ['label' => 'Hết hạn',       'color' => 'text-gray-600 bg-gray-100 border-gray-200'],
                        'cancelled' => ['label' => 'Đã huỷ',        'color' => 'text-red-700 bg-red-50 border-red-200'],
                    ];
                @endphp

                <div class="flex-1 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800">
                    <button
                        wire:click="selectContract('__general__')"
                        wire:loading.class="opacity-50"
                        wire:target="selectContract('__general__')"
                        class="w-full p-3 text-left transition-colors {{ $selectedContractId === '__general__' ? 'bg-primary-50 dark:bg-primary-900/20 border-l-2 border-l-primary-500' : 'hover:bg-gray-50 dark:hover:bg-gray-800/60' }}"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2 min-w-0">
                                <x-heroicon-o-chat-bubble-left-ellipsis class="w-4 h-4 text-gray-400 flex-shrink-0" />
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300 truncate">Hỗ trợ chung</span>
                            </div>
                            @if ($generalUnread > 0)
                                <span class="flex-shrink-0 inline-flex items-center justify-center min-w-[1.2rem] h-5 px-1 text-xs font-bold bg-red-500 text-white rounded-full">
                                    {{ min($generalUnread, 99) }}
                                </span>
                            @endif
                        </div>
                        <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5 pl-6">Không gắn với hợp đồng</div>
                    </button>

                    @forelse ($tenantContracts as $contract)
                        @php $st = $contractStatusMap[$contract['status']] ?? ['label' => $contract['status'], 'color' => 'text-gray-600 bg-gray-100 border-gray-200']; @endphp
                        <button
                            wire:click="selectContract('{{ $contract['id'] }}')"
                            wire:loading.class="opacity-50"
                            wire:target="selectContract('{{ $contract['id'] }}')"
                            class="w-full p-3 text-left transition-colors {{ $selectedContractId === $contract['id'] ? 'bg-primary-50 dark:bg-primary-900/20 border-l-2 border-l-primary-500' : 'hover:bg-gray-50 dark:hover:bg-gray-800/60' }}"
                        >
                            <div class="flex items-center justify-between gap-1 mb-1">
                                <div class="flex items-center gap-1.5 min-w-0">
                                    <span class="text-sm font-semibold text-gray-900 dark:text-white truncate">
                                        {{ $contract['room_code'] ?? 'HĐ #' . $contract['id'] }}
                                    </span>
                                    @if (($contract['unread'] ?? 0) > 0)
                                        <span class="flex-shrink-0 inline-flex items-center justify-center min-w-[1.2rem] h-5 px-1 text-xs font-bold bg-red-500 text-white rounded-full">
                                            {{ min($contract['unread'], 99) }}
                                        </span>
                                    @endif
                                </div>
                                <span class="flex-shrink-0 inline-block px-1.5 py-0.5 text-xs font-medium rounded border {{ $st['color'] }}">
                                    {{ $st['label'] }}
                                </span>
                            </div>
                            @if ($contract['start_date'])
                                <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ $contract['start_date'] }} - {{ $contract['end_date'] ?? '?' }}</div>
                            @endif
                        </button>
                    @empty
                        <div class="p-6 text-center text-sm text-gray-400 dark:text-gray-500">
                            Khách chưa có hợp đồng nào
                        </div>
                    @endforelse
                </div>
            </div>
        @endif
    </div>

    @script
    <script>
    (function () {
        const WS_URL = @js(rtrim(config('services.websocket.public_url', config('services.websocket.url')), '/'));

        if (window._mhChatSocket && window._mhChatSocket.connected) {
            bindWireEvents();
            return;
        }

        if (!window.io) {
            const s = document.createElement('script');
            s.src = WS_URL + '/socket.io/socket.io.js';
            s.onload = () => initSocket();
            document.head.appendChild(s);
        } else {
            initSocket();
        }

        function initSocket() {
            const socket = io(WS_URL, {
                transports:        ['websocket', 'polling'],
                reconnectionDelay: 2000,
            });

            window._mhChatSocket = socket;

            socket.on('connect', () => {
                socket.emit('subscribe:mh-chat-admin');

                const convId = $wire.selectedId;
                if (convId) {
                    socket.emit('subscribe:mh-chat', { conversation_id: convId });
                }
            });

            socket.on('mhchat.message', (data) => {
                $wire.newChatMessage(data.message, data.conversation_id);
            });

            socket.on('mhchat.list_update', () => {
                $wire.refreshConversationList();
            });

            socket.on('mhchat.read', (data) => {
                if (data.read_by === 'tenant' && data.conversation_id === $wire.selectedId) {
                    $wire.$refresh();
                }
            });

            bindWireEvents();
        }

        function bindWireEvents() {
            $wire.$on('subscribeToConversation', ({ id }) => {
                const socket = window._mhChatSocket;
                if (!socket) return;
                const prev = window._mhChatPrevConvId;
                if (prev && prev !== id) {
                    socket.emit('unsubscribe:mh-chat', { conversation_id: prev });
                }
                window._mhChatPrevConvId = id;
                socket.emit('subscribe:mh-chat', { conversation_id: id });
            });

            $wire.$on('scrollToBottom', () => {
                setTimeout(() => {
                    const el = document.getElementById('mh-chat-messages-scroll');
                    if (el) el.scrollTop = el.scrollHeight;
                }, 60);
            });
        }
    })();
    </script>
    @endscript
</x-filament-panels::page>
