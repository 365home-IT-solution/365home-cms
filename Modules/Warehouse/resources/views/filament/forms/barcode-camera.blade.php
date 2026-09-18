{{--
    Modal quét mã vạch bằng camera điện thoại/laptop — dùng BarcodeDetector CÓ SẴN của trình duyệt
    (Chrome/Edge/Android WebView), KHÔNG cần cài thêm thư viện JS ngoài. Hạn chế thật cần biết: Safari
    (iPhone/iPad) hiện CHƯA hỗ trợ BarcodeDetector — khi đó modal tự hiện thông báo hướng dẫn nhập tay/
    dùng máy quét vật lý thay vì camera, không có gì bị vỡ, chỉ là không dùng được đường camera này.

    Sau khi quét được, JS ghi thẳng giá trị vào ô input#warehouse-barcode-scan-input (xem
    WarehouseBarcodeScan::INPUT_ID) rồi tự blur() — quy ước NÀY PHẢI KHỚP với field() trong
    WarehouseBarcodeScan để chốt giá trị lên Livewire giống hệt như gõ tay xong bấm Enter.
--}}
<div
    x-data="{
        stream: null,
        detector: null,
        supported: 'BarcodeDetector' in window,
        detectedCode: null,
        init() {
            if (! this.supported) return;
            this.start();
        },
        async start() {
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                this.$refs.video.srcObject = this.stream;
                this.detector = new BarcodeDetector({
                    formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'qr_code'],
                });
                this.loop();
            } catch (e) {
                // Không xin được quyền camera (từ chối/không có thiết bị) — coi như không hỗ trợ,
                // hiện luôn thông báo hướng dẫn thay vì màn hình camera trống/lỗi khó hiểu.
                this.supported = false;
            }
        },
        async loop() {
            if (! this.stream || this.detectedCode) return;
            try {
                const codes = await this.detector.detect(this.$refs.video);
                if (codes.length) {
                    this.onDetected(codes[0].rawValue);
                    return;
                }
            } catch (e) {}
            requestAnimationFrame(() => this.loop());
        },
        onDetected(value) {
            this.detectedCode = value;
            this.stop();
            const input = document.getElementById('{{ \Modules\Warehouse\App\Filament\Support\WarehouseBarcodeScan::INPUT_ID }}');
            if (input) {
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.blur();
            }
        },
        stop() {
            if (this.stream) {
                this.stream.getTracks().forEach((t) => t.stop());
                this.stream = null;
            }
        },
    }"
    x-on:closed.stop="stop()"
>
    <template x-if="! supported">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Trình duyệt này chưa hỗ trợ quét mã vạch bằng camera (thường gặp trên Safari/iPhone, hoặc
            camera chưa được cấp quyền). Hãy nhập/dán mã vạch trực tiếp vào ô, hoặc dùng máy quét mã
            vạch — cắm/kết nối vào là gõ thẳng ra ô nhập, không cần thao tác gì thêm.
        </p>
    </template>

    <template x-if="supported && ! detectedCode">
        <div>
            <video x-ref="video" autoplay playsinline muted style="width:100%;border-radius:0.5rem;background:#000;"></video>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Đưa camera vào mã vạch/QR trên sản phẩm...</p>
        </div>
    </template>

    <template x-if="detectedCode">
        <div class="flex items-center gap-2 text-success-600 dark:text-success-400">
            <x-heroicon-o-check-circle class="h-6 w-6 flex-none" />
            <span>Đã quét được mã: <strong x-text="detectedCode"></strong> — đóng cửa sổ này để tiếp tục.</span>
        </div>
    </template>
</div>
