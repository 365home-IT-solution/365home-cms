<x-filament-panels::page>
    @php($cameras = $this->getCameras())
    @php($configured = $this->getGo2rtcConfigured())

    @if (! $configured)
        <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm text-warning-700 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-300">
            Chưa cấu hình server go2rtc/Frigate. Vào menu "Cấu hình web &gt; Camera" điền địa chỉ
            server rồi tải lại trang này.
        </div>
    @elseif ($cameras->isEmpty())
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
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
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
                        <video-rtc
                            x-data
                            x-init="$el.mode = 'mse'; setTimeout(() => { $el.src = @js($url) }, {{ $loop->index * 350 }})"
                            style="display:block;width:100%;aspect-ratio:16/9;background:#000;"
                        ></video-rtc>
                    @else
                        <div style="width:100%;aspect-ratio:16/9;background:#000;" class="flex items-center justify-center text-xs text-gray-400">
                            Chưa cấu hình địa chỉ WebSocket (Cấu hình web &gt; Camera).
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        @once
            {{--
                ?v={{ filemtime(...) }} — file tĩnh này KHÔNG qua Vite nên trình duyệt tự cache dài
                hạn theo URL; mỗi lần sửa video-rtc.js (VD 2 lần chỉnh background=true/
                visibilityThreshold vừa rồi) mà không đổi URL, người dùng vẫn chạy bản JS CŨ đã cache
                dù server đã có bản mới — thêm hậu tố đổi theo giờ sửa file để trình duyệt tự tải lại
                đúng lúc có bản mới, không cần người dùng tự xoá cache tay.
            --}}
            <script type="module">
                import { VideoRTC } from '{{ asset('vendor/go2rtc/video-rtc.js') }}?v={{ filemtime(public_path('vendor/go2rtc/video-rtc.js')) }}';
                customElements.define('video-rtc', VideoRTC);
            </script>
        @endonce
    @endif
</x-filament-panels::page>
