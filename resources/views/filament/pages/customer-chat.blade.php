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

        {{-- ── Right: Thông tin đơn hàng (sidebar) ──────────────────── --}}
        @if ($selectedConversation && $orderInfo)
        <div class="w-72 flex-shrink-0 flex flex-col bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl overflow-y-auto">

            {{-- Header --}}
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-document-text class="w-4 h-4 text-primary-600" />
                    <span class="font-semibold text-sm text-gray-900 dark:text-white">Đơn #{{ $orderInfo['order_code'] }}</span>
                </div>
                @php
                    $statusMap = [
                        'pending'   => ['label' => 'Chờ thanh toán', 'color' => 'text-yellow-600 bg-yellow-50'],
                        'paid'      => ['label' => 'Đã thanh toán',  'color' => 'text-green-600 bg-green-50'],
                        'confirmed' => ['label' => 'Đã xác nhận',    'color' => 'text-blue-600 bg-blue-50'],
                        'cancelled' => ['label' => 'Đã huỷ',         'color' => 'text-red-600 bg-red-50'],
                        'completed' => ['label' => 'Hoàn thành',     'color' => 'text-gray-600 bg-gray-100'],
                    ];
                    $st = $statusMap[$orderInfo['status']] ?? ['label' => $orderInfo['status'], 'color' => 'text-gray-600 bg-gray-100'];
                @endphp
                <span class="inline-block mt-1 px-2 py-0.5 text-xs font-medium rounded-full {{ $st['color'] }}">{{ $st['label'] }}</span>
            </div>

            <div class="px-4 py-3 space-y-4 text-sm">

                {{-- Phòng --}}
                @if ($orderInfo['room_name'])
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Phòng</p>
                    <p class="text-gray-900 dark:text-white font-medium">{{ $orderInfo['room_name'] }}</p>
                </div>
                @endif

                {{-- Ngày --}}
                @if (count($orderInfo['slots']))
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Ngày thuê ({{ count($orderInfo['slots']) }} đêm)</p>
                    <div class="space-y-0.5">
                        @foreach ($orderInfo['slots'] as $slot)
                        <div class="flex justify-between text-gray-700 dark:text-gray-300">
                            <span>{{ $slot['date'] }}</span>
                            <span>{{ number_format($slot['price']) }}đ</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Dịch vụ --}}
                @if (count($orderInfo['services']))
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Dịch vụ</p>
                    <div class="space-y-0.5">
                        @foreach ($orderInfo['services'] as $svc)
                        <div class="flex justify-between text-gray-700 dark:text-gray-300">
                            <span>{{ $svc['service_name'] }} ×{{ $svc['quantity'] }}</span>
                            <span>{{ number_format($svc['subtotal']) }}đ</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Tổng --}}
                <div class="border-t border-gray-100 dark:border-gray-700 pt-3 space-y-1">
                    @if ($orderInfo['discount'] > 0)
                    <div class="flex justify-between text-gray-500 dark:text-gray-400">
                        <span>Giảm giá</span>
                        <span class="text-green-600">-{{ number_format($orderInfo['discount']) }}đ</span>
                    </div>
                    @endif
                    @if ($orderInfo['services_total'] > 0)
                    <div class="flex justify-between text-gray-500 dark:text-gray-400">
                        <span>Dịch vụ</span>
                        <span>{{ number_format($orderInfo['services_total']) }}đ</span>
                    </div>
                    @endif
                    <div class="flex justify-between font-semibold text-gray-900 dark:text-white">
                        <span>Tổng cộng</span>
                        <span>{{ number_format($orderInfo['total']) }}đ</span>
                    </div>
                    @if ($orderInfo['deposit_pct'])
                    <div class="flex justify-between text-primary-600 dark:text-primary-400 text-xs">
                        <span>Cọc {{ $orderInfo['deposit_pct'] }}%</span>
                        <span>{{ number_format($orderInfo['deposit_amount']) }}đ</span>
                    </div>
                    @if ($orderInfo['remaining'] > 0)
                    <div class="flex justify-between text-gray-500 dark:text-gray-400 text-xs">
                        <span>Còn lại</span>
                        <span>{{ number_format($orderInfo['remaining']) }}đ</span>
                    </div>
                    @endif
                    @endif
                </div>

                {{-- Khách hàng --}}
                <div class="border-t border-gray-100 dark:border-gray-700 pt-3">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Người đặt</p>
                    <p class="text-gray-900 dark:text-white">{{ $orderInfo['buyer_name'] }}</p>
                    <p class="text-gray-500 dark:text-gray-400 text-xs">{{ $orderInfo['buyer_phone'] }}</p>
                </div>

            </div>
        </div>
        @endif

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
                const prev = window._adminChatPrevConvId;
                if (prev && prev !== id) {
                    socket.emit('unsubscribe:chat', { conversation_id: prev });
                }
                window._adminChatPrevConvId = id;
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
