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
        class="mh-card flex flex-col overflow-hidden"
        style="height: calc(100vh - 8rem);"
    >
        <div class="border-b px-4 py-3.5" style="border-color: var(--mh-border);">
            <h1 class="text-base font-bold text-gray-900 mh-heading">Chat với nhân viên</h1>
            <p class="text-xs text-gray-500 mt-0.5">Gửi tin nhắn nếu bạn cần hỗ trợ — nhân viên toà nhà sẽ trả lời sớm nhất có thể.</p>
        </div>

        <div x-ref="scrollArea" class="flex-1 space-y-2.5 overflow-y-auto px-4 py-4" style="background: var(--mh-bg);">
            <template x-if="hasMore">
                <button type="button" x-on:click="loadOlder()" class="mx-auto block text-xs font-medium hover:underline" style="color: var(--mh-primary);">
                    Tải tin nhắn cũ hơn
                </button>
            </template>

            <template x-for="msg in messages" :key="msg.id">
                <div :class="msg.sender_type === 'tenant' ? 'flex justify-end' : 'flex justify-start'">
                    <div
                        :class="msg.sender_type === 'tenant' ? 'text-white' : 'bg-white text-gray-900 border'"
                        :style="msg.sender_type === 'tenant' ? 'background: var(--mh-primary);' : 'border-color: var(--mh-border);'"
                        class="max-w-[75%] rounded-2xl px-3.5 py-2.5 text-sm shadow-sm"
                    >
                        <template x-if="msg.sender_type === 'admin'">
                            <div class="mb-0.5 text-xs font-semibold text-gray-500" x-text="msg.sender_name || 'Nhân viên'"></div>
                        </template>
                        <div x-text="msg.body" style="white-space: pre-wrap;"></div>
                        <div :class="msg.sender_type === 'tenant' ? 'text-white/70' : 'text-gray-400'" class="mt-1 text-right text-[10px] mh-tabular" x-text="msg.time"></div>
                    </div>
                </div>
            </template>
        </div>

        <form x-on:submit.prevent="send()" class="flex items-center gap-2 border-t p-3" style="border-color: var(--mh-border);">
            <input
                type="text"
                x-model="draft"
                placeholder="Nhập tin nhắn..."
                class="mh-input flex-1"
                maxlength="2000"
            >
            <button type="submit" class="mh-btn-primary" style="width: auto; padding: 0.625rem 1.25rem;">
                Gửi
            </button>
        </form>
    </div>

    {{-- Alpine.js giờ tải chung ở portal/layout.blade.php, không cần tự tải lại ở đây nữa. --}}
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
                    // Dự phòng: socket realtime có thể không kết nối được (sai WS_PUBLIC_URL, bị chặn mạng...) —
                    // poll tin mới mỗi 4 giây để tin của nhân viên luôn tới, chỉ khi tab đang mở.
                    setInterval(() => { if (!document.hidden) this.fetchNew(); }, 4000);

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

                async fetchNew() {
                    const lastId = this.messages.length ? this.messages[this.messages.length - 1].id : null;
                    if (!lastId) return;

                    const url = new URL(config.messagesUrl, window.location.origin);
                    url.searchParams.set('after_id', lastId);

                    try {
                        const res = await fetch(url, { headers: { Accept: 'application/json' } });
                        if (!res.ok) return;
                        const data = await res.json();
                        const fresh = (data.messages || []).filter((m) => !this.messages.some((x) => x.id === m.id));
                        if (!fresh.length) return;

                        this.messages.push(...fresh);
                        this.$nextTick(() => this.scrollToBottom());
                        if (fresh.some((m) => m.sender_type === 'admin')) this.markRead();
                    } catch (e) { /* mất mạng tạm thời — lần poll sau sẽ thử lại */ }
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
