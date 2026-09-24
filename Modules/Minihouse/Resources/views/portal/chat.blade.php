@extends('minihouse::portal.layout')

@section('title', 'Chat với nhân viên - Portal khách thuê')

@section('content')
    <div
        x-data="minihouseChat({
            conversationId: @js($conversation->id),
            initialMessages: @js($messages),
            hasMore: @js($has_more),
            wsUrl: @js(rtrim((string) config('services.websocket.public_url'), '/')),
            messagesUrl: @js(route('minihouse.portal.chat.messages')),
            sendUrl: @js(route('minihouse.portal.chat.send')),
        })"
        x-init="init()"
        class="flex flex-col rounded-xl border border-gray-100 bg-white shadow-sm"
        style="height: calc(100vh - 8rem);"
    >
        <div class="border-b border-gray-100 px-4 py-3">
            <h1 class="text-base font-semibold text-gray-900">Chat với nhân viên</h1>
            <p class="text-xs text-gray-500">Gửi tin nhắn nếu bạn cần hỗ trợ — nhân viên toà nhà sẽ trả lời sớm nhất có thể.</p>
        </div>

        <div x-ref="scrollArea" class="flex-1 space-y-2 overflow-y-auto px-4 py-3">
            <template x-if="hasMore">
                <button type="button" x-on:click="loadOlder()" class="mx-auto block text-xs text-blue-600 hover:underline">
                    Tải tin nhắn cũ hơn
                </button>
            </template>

            <template x-for="msg in messages" :key="msg.id">
                <div :class="msg.sender_type === 'tenant' ? 'flex justify-end' : 'flex justify-start'">
                    <div
                        :class="msg.sender_type === 'tenant' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-900'"
                        class="max-w-[75%] rounded-2xl px-3 py-2 text-sm"
                    >
                        <template x-if="msg.sender_type === 'admin'">
                            <div class="mb-0.5 text-xs font-medium text-gray-500" x-text="msg.sender_name || 'Nhân viên'"></div>
                        </template>
                        <div x-text="msg.body" style="white-space: pre-wrap;"></div>
                        <div :class="msg.sender_type === 'tenant' ? 'text-blue-100' : 'text-gray-400'" class="mt-1 text-right text-[10px]" x-text="msg.time"></div>
                    </div>
                </div>
            </template>
        </div>

        <form x-on:submit.prevent="send()" class="flex items-center gap-2 border-t border-gray-100 p-3">
            <input
                type="text"
                x-model="draft"
                placeholder="Nhập tin nhắn..."
                class="flex-1 rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500"
                maxlength="2000"
            >
            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-500">
                Gửi
            </button>
        </form>
    </div>

    @once
        <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    @endonce

    <script>
        function minihouseChat(config) {
            return {
                conversationId: config.conversationId,
                messages: config.initialMessages || [],
                hasMore: config.hasMore,
                draft: '',
                socket: null,

                init() {
                    this.scrollToBottom();
                    this.connectSocket(config.wsUrl);

                    // Đánh dấu đã đọc mỗi khi tab được focus lại (phòng khi bỏ lỡ sự kiện socket).
                    window.addEventListener('focus', () => this.markRead());
                },

                connectSocket(wsUrl) {
                    if (!wsUrl) return;

                    const script = document.createElement('script');
                    script.src = wsUrl + '/socket.io/socket.io.js';
                    script.onload = () => {
                        this.socket = io(wsUrl, { transports: ['websocket', 'polling'] });

                        this.socket.on('connect', () => {
                            this.socket.emit('subscribe:mh-chat', { conversation_id: this.conversationId });
                        });

                        this.socket.on('mhchat.message', (data) => {
                            if (data.conversation_id !== this.conversationId) return;
                            if (this.messages.some((m) => m.id === data.message.id)) return;

                            this.messages.push(data.message);
                            this.$nextTick(() => this.scrollToBottom());

                            if (data.message.sender_type === 'admin') {
                                this.markRead();
                            }
                        });
                    };
                    document.head.appendChild(script);
                },

                async loadOlder() {
                    const oldestId = this.messages.length ? this.messages[0].id : null;
                    const url = new URL(config.messagesUrl, window.location.origin);
                    if (oldestId) url.searchParams.set('before_id', oldestId);

                    const res = await fetch(url, { headers: { Accept: 'application/json' } });
                    const data = await res.json();

                    this.messages = [...data.messages, ...this.messages];
                    this.hasMore = data.has_more;
                },

                async send() {
                    const body = this.draft.trim();
                    if (!body) return;

                    this.draft = '';

                    const res = await fetch(config.sendUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}',
                        },
                        body: JSON.stringify({ body }),
                    });

                    const data = await res.json();
                    if (res.ok) {
                        this.messages.push(data.message);
                        this.$nextTick(() => this.scrollToBottom());
                    }
                },

                async markRead() {
                    await fetch('{{ route('minihouse.portal.chat.read') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}',
                        },
                    });
                },

                scrollToBottom() {
                    const el = this.$refs.scrollArea;
                    if (el) el.scrollTop = el.scrollHeight;
                },
            };
        }
    </script>
@endsection
