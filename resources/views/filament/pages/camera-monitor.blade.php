<x-filament-panels::page>
    @php($cameras = $this->getCameras())

    {{--
        Mỗi đối tác có thể dùng server Frigate RIÊNG (App\Models\CameraSetting) — không còn 1 cờ
        "đã cấu hình go2rtc" chung cho CẢ TRANG (super_admin xem camera nhiều đối tác cùng lúc, mỗi
        đối tác cấu hình khác nhau). Camera nào chưa cấu hình xong thì tự hiện thông báo NGAY TRÊN Ô
        của chính camera đó (nhánh @else bên dưới, dựa vào Camera::wsProxyUrl() trả null hay không).
    --}}
    @if ($cameras->isEmpty())
        <div class="rounded-xl border border-gray-200 bg-white p-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
            Chưa có camera nào đang hoạt động. Vào menu "Camera" để thêm.
        </div>
    @else
        {{--
            <video-rtc> là custom element CHÍNH THỨC của go2rtc (public/vendor/go2rtc/video-rtc.js,
            vendor nguyên văn — xem file đó để hiểu vì sao không tự viết lại). mode="mse" ép CHỈ dùng
            đường MSE-qua-WebSocket (bỏ qua nhánh webrtc/hls/mjpeg mặc định của thư viện) — WebRTC
            cần trình duyệt kết nối UDP TRỰC TIẾP tới Frigate (không đi qua proxy của ta được), không
            phù hợp kiến trúc "server tự đăng nhập thay người xem" đang dùng ở đây.
        --}}
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach ($cameras as $camera)
                {{--
                    wire:ignore trên CẢ Ô CAMERA (không chỉ khối video như trước) — từ khi ghi hình
                    chuyển hẳn sang client-side (MediaRecorder, xem x-data bên dưới), ô này không còn
                    action PHP/wire:click nào nữa nên không cần Livewire re-render đụng vào, tránh hẳn
                    lớp lỗi "bấm 1 nút làm đen hết camera khác" đã từng gặp (morphdom reset
                    <video-rtc>). "Lịch sử" chỉ dispatch 1 CustomEvent ra window, modal xử lý nó nằm
                    NGOÀI wire:ignore này nên $wire.loadPlayback() vẫn gọi được bình thường.
                --}}
                <div
                    wire:key="camera-tile-{{ $camera->id }}"
                    wire:ignore
                    x-data="{
                        muted: true,
                        recording: false,
                        mediaRecorder: null,
                        chunks: [],
                        cameraName: @js($camera->name),
                        toggleRecording() {
                            if (this.recording) {
                                this.mediaRecorder?.stop();
                                return;
                            }
                            const video = this.$refs.videoRtc?.querySelector('video');
                            if (! video) {
                                alert('Chưa có luồng video để ghi.');
                                return;
                            }
                            if (typeof window.MediaRecorder === 'undefined' || typeof video.captureStream !== 'function') {
                                alert('Trình duyệt này không hỗ trợ ghi hình trực tiếp từ video (cần Chrome/Edge/Firefox bản mới).');
                                return;
                            }
                            let stream;
                            try {
                                stream = video.captureStream();
                            } catch (e) {
                                alert('Không lấy được luồng video để ghi: ' + e.message);
                                return;
                            }
                            const mimeCandidates = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm'];
                            const mimeType = mimeCandidates.find((t) => MediaRecorder.isTypeSupported(t)) || '';
                            this.chunks = [];
                            this.mediaRecorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);
                            this.mediaRecorder.ondataavailable = (e) => { if (e.data.size > 0) this.chunks.push(e.data); };
                            this.mediaRecorder.onstop = () => {
                                // Tải file .webm THẲNG về máy đang mở trang này (thư mục Downloads
                                // mặc định của trình duyệt) — KHÔNG gửi lên server nào cả, đúng yêu
                                // cầu 'lưu ở máy tính đang sử dụng, không lưu ở server'.
                                const blob = new Blob(this.chunks, { type: mimeType || 'video/webm' });
                                const url = URL.createObjectURL(blob);
                                const a = document.createElement('a');
                                const safeName = this.cameraName.replace(/[^a-zA-Z0-9-_]+/g, '_');
                                const ts = new Date().toISOString().replace(/[:.]/g, '-');
                                a.href = url;
                                a.download = `camera-${safeName}-${ts}.webm`;
                                document.body.appendChild(a);
                                a.click();
                                a.remove();
                                setTimeout(() => URL.revokeObjectURL(url), 5000);
                                this.recording = false;
                            };
                            this.mediaRecorder.start();
                            this.recording = true;
                        },
                    }"
                    class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900"
                >
                    <div class="flex items-center justify-between px-4 py-2">
                        <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $camera->name }}</span>
                        @if ($camera->branch)
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $camera->branch->name }}</span>
                        @endif
                    </div>

                    @if ($url = $camera->wsProxyUrl())
                        {{--
                            "mode"/"src" của VideoRTC là THUỘC TÍNH JS (set src(value){...}), KHÔNG
                            phải HTML attribute thường — class không đọc getAttribute(...) ở đâu cả,
                            viết thẳng ra HTML sẽ bị lờ đi hoàn toàn. Phải gán qua JS property, và
                            "src" gán SAU CÙNG (setter của nó tự gọi onconnect() ngay lập tức, mở
                            WebSocket thật).

                            Đã BỎ cách "chỉ kết nối camera trong khung nhìn" (visibilityThreshold) —
                            người dùng phản hồi việc tự ngắt/nối lại theo cuộn trang khó chịu hơn cả
                            vấn đề nó sửa. Thay bằng rải nhẹ THỜI ĐIỂM BẮT ĐẦU kết nối theo thứ tự
                            camera (mỗi camera cách nhau 350ms) — tất cả camera đều kết nối và giữ kết
                            nối bất kể có cuộn tới hay không, chỉ tránh việc TOÀN BỘ 17 camera cùng dội
                            vào Frigate trong cùng 1 khoảnh khắc (nguyên nhân khiến không camera nào
                            lên hình khi mở đồng loạt, đã tự xác nhận qua test trước đó).
                        --}}
                        {{--
                            wire:ignore giờ đặt ở CẢ Ô CAMERA (xem div cha) nên không còn bắt buộc lặp
                            lại ở đây nữa — vẫn giữ để khối này an toàn dù sau này có đổi cấu trúc.
                            x-ref="videoRtc" — để toggleRecording() (x-data ở div cha) tự tìm được
                            đúng thẻ <video> BÊN TRONG <video-rtc> (light DOM, không shadow DOM) mà
                            gọi video.captureStream() ghi hình.
                        --}}
                        <div class="relative" wire:ignore>
                            <video-rtc
                                x-ref="videoRtc"
                                x-init="$el.mode = 'mse'; setTimeout(() => { $el.src = @js($url) }, {{ $loop->index * 350 }})"
                                style="display:block;width:100%;aspect-ratio:16/9;background:#000;"
                            ></video-rtc>

                            {{--
                                video-rtc.js TỰ MUTE video khi trình duyệt chặn autoplay-có-tiếng
                                (chính sách Chrome/Safari — chỉ cho phát tự động nếu đã tắt tiếng),
                                xem VideoRTC.play() trong file vendor — KHÔNG phải audio track không
                                tồn tại. Bấm nút này (có thao tác người dùng thật) mới được phép bật
                                lại tiếng. <video> là con LIGHT DOM trực tiếp của <video-rtc> (không
                                có shadow DOM, xem video-rtc.js dòng ~272), nên querySelector từ
                                ngoài vào lấy được thẳng.
                            --}}
                            <button
                                type="button"
                                x-on:click="
                                    const v = $el.closest('.relative').querySelector('video');
                                    if (! v) return;
                                    v.muted = ! v.muted;
                                    muted = v.muted;
                                "
                                class="absolute bottom-2 right-2 flex h-8 w-8 items-center justify-center rounded-full bg-gray-950/60 text-white transition hover:bg-gray-950/80"
                                :aria-label="muted ? 'Bật âm thanh' : 'Tắt âm thanh'"
                            >
                                <template x-if="muted">
                                    <x-heroicon-o-speaker-x-mark class="h-4 w-4" />
                                </template>
                                <template x-if="! muted">
                                    <x-heroicon-o-speaker-wave class="h-4 w-4" />
                                </template>
                            </button>
                        </div>

                        {{--
                            Ghi hình thủ công — GHI Ở TRÌNH DUYỆT (MediaRecorder trên chính thẻ
                            <video> đang phát, xem toggleRecording() ở x-data của ô camera cha), tự
                            tải file .webm về máy đang xem trang này. KHÔNG còn gọi Frigate/Livewire
                            nữa (yêu cầu 2026-09-23: lưu ở máy đang dùng, không lưu ở server) + mở
                            modal xem lại lịch sử (dùng chung ở cuối trang, vẫn qua Frigate như cũ).
                        --}}
                        <div class="flex items-center gap-2 border-t border-gray-100 px-3 py-2 dark:border-gray-800">
                            <button
                                type="button"
                                x-on:click="toggleRecording()"
                                :class="recording
                                    ? 'bg-danger-50 text-danger-700 hover:bg-danger-100 dark:bg-danger-950 dark:text-danger-300'
                                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'"
                                class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-xs font-medium transition"
                            >
                                <x-heroicon-o-video-camera class="h-3.5 w-3.5" />
                                <span x-text="recording ? 'Dừng ghi' : 'Ghi hình'"></span>
                            </button>

                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-lg bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 transition hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                                x-on:click="window.dispatchEvent(new CustomEvent('open-camera-playback', { detail: { cameraId: {{ $camera->id }}, cameraName: @js($camera->name) } }))"
                            >
                                <x-heroicon-o-clock class="h-3.5 w-3.5" />
                                Lịch sử
                            </button>
                        </div>
                    @else
                        <div style="width:100%;aspect-ratio:16/9;background:#000;" class="flex items-center justify-center text-xs text-gray-400">
                            Chưa cấu hình địa chỉ WebSocket (Cấu hình web &gt; Camera).
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{--
            Modal xem lại lịch sử — DÙNG CHUNG 1 instance cho mọi camera (mở qua sự kiện
            "open-camera-playback" thay vì 1 modal/camera, đỡ tốn DOM). Tự viết bằng Alpine thay vì
            dùng Filament\Actions\Action (modal Action của Filament đóng lại ngay sau khi action()
            chạy xong — không hợp để "chọn giờ xong vẫn giữ modal mở phát video").

            Phát HLS bằng hls.js (vendor tại public/vendor/hlsjs — KHÔNG có sẵn trong dự án trước đó,
            cần cho Chrome/Firefox; Safari phát HLS thẳng qua thẻ <video> không cần thư viện).
        --}}
        <div
            x-data="{
                open: false,
                cameraId: null,
                cameraName: '',
                loading: false,
                error: '',
                hls: null,
                async play(after, before) {
                    this.error = '';
                    this.loading = true;
                    const url = await $wire.loadPlayback(this.cameraId, after, before);
                    this.loading = false;
                    if (! url) { this.error = 'Không lấy được video — có thể ngoài khoảng còn lưu trữ hoặc Frigate chưa cấu hình.'; return; }
                    this.attach(url);
                },
                attach(url) {
                    const video = this.$refs.video;
                    if (this.hls) { this.hls.destroy(); this.hls = null; }
                    if (video.canPlayType('application/vnd.apple.mpegurl')) {
                        video.src = url;
                        video.play();
                    } else if (window.Hls && window.Hls.isSupported()) {
                        this.hls = new window.Hls();
                        this.hls.loadSource(url);
                        this.hls.attachMedia(video);
                        this.hls.on(window.Hls.Events.MANIFEST_PARSED, () => video.play());
                        this.hls.on(window.Hls.Events.ERROR, (event, data) => {
                            if (data.fatal) this.error = 'Lỗi phát video: ' + data.type;
                        });
                    } else {
                        this.error = 'Trình duyệt này không hỗ trợ phát lại HLS.';
                    }
                },
                close() {
                    this.open = false;
                    this.error = '';
                    if (this.hls) { this.hls.destroy(); this.hls = null; }
                    this.$refs.video.pause();
                    this.$refs.video.removeAttribute('src');
                    this.$refs.video.load();
                },
            }"
            x-on:open-camera-playback.window="open = true; cameraId = $event.detail.cameraId; cameraName = $event.detail.cameraName; error = ''"
            x-show="open"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/70 p-4"
        >
            <div class="w-full max-w-2xl rounded-xl bg-white shadow-xl dark:bg-gray-900" x-on:click.outside="close()">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                    <span class="text-sm font-medium text-gray-950 dark:text-white" x-text="'Xem lại lịch sử — ' + cameraName"></span>
                    <button type="button" x-on:click="close()" class="text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-200">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="space-y-3 p-4">
                    <div class="flex flex-wrap gap-2">
                        <template x-for="m in [5, 15, 30, 60]" :key="m">
                            <button
                                type="button"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800"
                                x-on:click="play(Math.floor(Date.now() / 1000) - m * 60, Math.floor(Date.now() / 1000))"
                                x-text="m + ' phút trước'"
                            ></button>
                        </template>
                    </div>

                    <div class="flex flex-wrap items-end gap-2">
                        <label class="text-xs text-gray-500 dark:text-gray-400">
                            Từ
                            <input type="datetime-local" x-ref="customAfter" class="mt-1 block rounded-lg border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                        </label>
                        <label class="text-xs text-gray-500 dark:text-gray-400">
                            Đến
                            <input type="datetime-local" x-ref="customBefore" class="mt-1 block rounded-lg border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                        </label>
                        <button
                            type="button"
                            class="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-primary-500"
                            x-on:click="
                                const a = $refs.customAfter.value, b = $refs.customBefore.value;
                                if (! a || ! b) { error = 'Chọn đủ \'Từ\' và \'Đến\'.'; return; }
                                play(Math.floor(new Date(a).getTime() / 1000), Math.floor(new Date(b).getTime() / 1000));
                            "
                        >Xem khoảng tuỳ chọn</button>
                    </div>

                    <p x-show="error" x-text="error" class="text-xs text-danger-600"></p>
                    <p x-show="loading" x-cloak class="text-xs text-gray-500 dark:text-gray-400">Đang tải...</p>

                    <video x-ref="video" controls playsinline style="width:100%;aspect-ratio:16/9;background:#000;" class="rounded-lg"></video>
                </div>
            </div>
        </div>

        @once
            {{--
                ?v={{ filemtime(...) }} — file tĩnh này KHÔNG qua Vite nên trình duyệt tự cache dài
                hạn theo URL; mỗi lần sửa video-rtc.js (VD 2 lần chỉnh background=true/
                visibilityThreshold vừa rồi) mà không đổi URL, người dùng vẫn chạy bản JS CŨ đã cache
                dù server đã có bản mới — thêm hậu tố đổi theo giờ sửa file để trình duyệt tự tải lại
                đúng lúc có bản mới, không cần người dùng tự xoá cache tay.
            --}}
            <script src="{{ asset('vendor/hlsjs/hls.min.js') }}?v={{ filemtime(public_path('vendor/hlsjs/hls.min.js')) }}"></script>
            <script type="module">
                import { VideoRTC } from '{{ asset('vendor/go2rtc/video-rtc.js') }}?v={{ filemtime(public_path('vendor/go2rtc/video-rtc.js')) }}';
                customElements.define('video-rtc', VideoRTC);
            </script>
        @endonce
    @endif
</x-filament-panels::page>
