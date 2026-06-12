<x-filament-panels::page>
    <div class="flex gap-4 overflow-hidden rounded-xl" style="height: calc(100vh - 13rem)">

        {{-- ── Left: Danh sách conversation ──────────────────────────── --}}
        <div class="w-72 flex flex-col flex-shrink-0 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden">

            {{-- Header --}}
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <span class="font-semibold text-sm text-gray-900 dark:text-white">Danh sách</span>
                @php $totalUnread = collect($conversations)->sum('unread') @endphp
                @if ($totalUnread > 0)
                    <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1 text-xs font-bold bg-red-500 text-white rounded-full">
                        {{ min($totalUnread, 99) }}
                    </span>
                @endif
            </div>

            {{-- List --}}
            <div class="flex-1 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($conversations as $conv)
                    <button
                        wire:click="selectConversation('{{ $conv['id'] }}')"
                        wire:loading.class="opacity-50"
                        wire:target="selectConversation('{{ $conv['id'] }}')"
                        class="w-full p-3 text-left transition-colors {{ $selectedId === $conv['id'] ? 'bg-primary-50 dark:bg-primary-900/20' : 'hover:bg-gray-50 dark:hover:bg-gray-800/60' }}"
                    >
                        <div class="flex items-start justify-between gap-1">
                            <span class="text-sm font-medium text-gray-900 dark:text-white truncate leading-5">
                                {{ $conv['customer']['fullname'] }}
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
        <div class="flex-1 flex flex-col min-w-0 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden">

            @if ($selectedConversation)

                {{-- Header --}}
                <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
                    <div class="w-9 h-9 rounded-full bg-primary-100 dark:bg-primary-900/40 flex items-center justify-center text-primary-700 dark:text-primary-300 font-bold text-sm flex-shrink-0">
                        {{ mb_strtoupper(mb_substr($selectedConversation['customer']['fullname'], 0, 1)) }}
                    </div>
                    <div class="min-w-0">
                        <div class="font-semibold text-sm text-gray-900 dark:text-white truncate">
                            {{ $selectedConversation['customer']['fullname'] }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $selectedConversation['customer']['phone'] }}
                        </div>
                    </div>
                </div>

                {{-- Messages --}}
                <div
                    id="chat-messages-scroll"
                    x-init="$el.scrollTop = $el.scrollHeight"
                    x-on:scrolltobottom.window="setTimeout(() => $el.scrollTop = $el.scrollHeight, 60)"
                    class="flex-1 overflow-y-auto px-4 py-4 space-y-3"
                >
                    @foreach ($messages as $msg)
                        <div
                            wire:key="msg-{{ $msg['id'] }}"
                            class="flex {{ $msg['sender_type'] === 'admin' ? 'justify-end' : 'justify-start' }}"
                        >
                            <div class="max-w-xs sm:max-w-sm lg:max-w-md xl:max-w-lg">
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
                    @endforeach
                </div>

                {{-- Input --}}
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

                {{-- Empty state --}}
                <div class="flex-1 flex flex-col items-center justify-center gap-3 text-gray-400 dark:text-gray-500 select-none">
                    <x-heroicon-o-chat-bubble-left-right class="w-16 h-16 opacity-25" />
                    <p class="text-sm">Chọn một cuộc trò chuyện để bắt đầu</p>
                </div>

            @endif
        </div>
    </div>

    @script
    <script>
    (function () {
        const WS_URL = @js(rtrim(config('services.websocket.public_url', config('services.websocket.url')), '/'));

        // Tránh tạo nhiều kết nối khi Livewire re-render
        if (window._adminChatSocket && window._adminChatSocket.connected) {
            bindWireEvents();
            return;
        }

        // Load Socket.IO client từ WS server
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
                transports:       ['websocket', 'polling'],
                reconnectionDelay: 2000,
            });

            window._adminChatSocket = socket;

            socket.on('connect', () => {
                // Đăng ký nhận cập nhật danh sách cho tất cả admin
                socket.emit('subscribe:chat-admin');

                // Subscribe vào conversation đang mở (nếu có)
                const convId = $wire.selectedId;
                if (convId) {
                    socket.emit('subscribe:chat', { conversation_id: convId });
                }
            });

            // Tin nhắn mới trong conversation
            socket.on('chat.message', (data) => {
                $wire.dispatch('newChatMessage', {
                    message:        data.message,
                    conversationId: data.conversation_id,
                });
            });

            // Cập nhật danh sách (khách gửi tin, hoặc admin khác trả lời)
            socket.on('chat.list_update', () => {
                $wire.dispatch('refreshConversationList');
            });

            // Read receipt — khách đã đọc → reload messages để cập nhật ✓✓
            socket.on('chat.read', (data) => {
                if (data.read_by === 'customer' && data.conversation_id === $wire.selectedId) {
                    $wire.$refresh();
                }
            });

            bindWireEvents();
        }

        function bindWireEvents() {
            // PHP dispatch → JS subscribe vào conversation channel
            $wire.$on('subscribeToConversation', ({ id }) => {
                const socket = window._adminChatSocket;
                if (!socket) return;
                socket.emit('unsubscribe:chat-admin'); // không cần bỏ, nhưng rõ ràng
                socket.emit('subscribe:chat-admin');
                socket.emit('subscribe:chat', { conversation_id: id });
            });

            // PHP dispatch → cuộn xuống cuối
            $wire.$on('scrollToBottom', () => {
                setTimeout(() => {
                    const el = document.getElementById('chat-messages-scroll');
                    if (el) el.scrollTop = el.scrollHeight;
                }, 60);
            });
        }
    })();
    </script>
    @endscript
</x-filament-panels::page>
