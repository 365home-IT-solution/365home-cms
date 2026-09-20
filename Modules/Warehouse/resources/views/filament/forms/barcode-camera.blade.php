{{--
    Modal quét mã vạch bằng camera điện thoại/laptop — dùng thư viện JS thuần ZXing
    (@zxing/browser, tải qua CDN, KHÔNG qua build Vite của dự án) thay vì BarcodeDetector có sẵn của
    trình duyệt, vì BarcodeDetector KHÔNG chạy được trên Safari/iPhone (mọi trình duyệt trên iOS đều
    dùng chung engine Safari, kể cả Chrome — giới hạn của Apple, không sửa được bằng cấu hình). ZXing tự
    vẽ frame video lên canvas rồi giải mã bằng JS thuần, chạy được trên MỌI trình duyệt có
    getUserMedia (Safari/iPhone, Chrome/Android, laptop...).

    Sau khi quét được, JS ghi thẳng giá trị vào ô input#warehouse-barcode-scan-input (xem
    WarehouseBarcodeScan::INPUT_ID) rồi tự blur() — quy ước NÀY PHẢI KHỚP với field() trong
    WarehouseBarcodeScan để chốt giá trị lên Livewire giống hệt như gõ tay xong bấm Enter.
--}}
<div
    x-data="{
        reader: null,
        supported: true,
        // 'paused' CHẶN không cho onDetected() xử lý lặp lại NGAY chính mã vừa quét (camera vẫn
        // đang chĩa vào tem cũ trong lúc người dùng di chuyển sang vật tư tiếp theo) — không dừng
        // camera/luồng quét lúc này, chỉ tạm bỏ qua kết quả để tránh quét trùng liên tục.
        paused: false,
        lastCode: null,
        // Chặn quét TRÙNG đúng 1 mã nhiều lần khi người dùng CHƯA KỊP đưa tem ra khỏi khung hình —
        // 'paused' 900ms là chưa đủ nếu họ cầm tem lâu hơn thế (rất dễ xảy ra thực tế), lúc đó
        // camera lại quét trúng CHÍNH mã đó lần nữa ngay khi hết 900ms, bị hiểu nhầm là quét thêm 1
        // lượt → cộng dồn +1 liên tục + spam thông báo dù người dùng chỉ đưa 1 tem duy nhất. Nhớ mã
        // + thời điểm quét gần nhất, CHỈ cho quét lại đúng mã đó sau ít nhất 3 giây (đủ thời gian để
        // họ chủ động quét lại nếu thật sự muốn +1 thêm 1 đơn vị cùng loại).
        lastScannedCode: null,
        lastScannedAt: 0,
        async init() {
            try {
                await this.loadZXing();
                await this.start();
            } catch (e) {
                // Không tải được thư viện, không xin được quyền camera, hoặc không có camera — coi
                // như không dùng được đường camera, hiện thông báo hướng dẫn nhập tay thay vì màn
                // hình lỗi khó hiểu.
                this.supported = false;
            }
        },
        loadZXing() {
            return new Promise((resolve, reject) => {
                if (window.ZXingBrowser) {
                    resolve();
                    return;
                }
                const script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/@zxing/browser@0.0.2/umd/zxing-browser.min.js';
                script.onload = () => resolve();
                script.onerror = () => reject(new Error('Không tải được thư viện quét mã.'));
                document.head.appendChild(script);
            });
        },
        async start() {
            this.reader = new window.ZXingBrowser.BrowserMultiFormatReader();

            await this.reader.decodeFromConstraints(
                { video: { facingMode: 'environment' } },
                this.$refs.video,
                (result) => {
                    if (result && ! this.paused) {
                        this.onDetected(result.getText());
                    }
                }
            );
        },
        // Quét liên tục nhiều vật tư liền nhau — KHÔNG dừng camera/đóng modal sau mỗi lần quét
        // trúng (khác bản trước, phải bấm nút Đóng thủ công rồi mở lại mới quét được món tiếp theo).
        // Chỉ chớp dấu ✓ ~900ms rồi tự cho quét tiếp, camera chạy xuyên suốt.
        onDetected(value) {
            const now = Date.now();
            if (value === this.lastScannedCode && (now - this.lastScannedAt) < 3000) {
                // Vẫn đúng mã cũ, chưa đủ 3 giây — coi như camera đang chĩa vào tem cũ chưa kịp
                // dời đi, KHÔNG xử lý tiếp (không cộng dồn thêm, không gửi thông báo).
                return;
            }
            this.lastScannedCode = value;
            this.lastScannedAt = now;

            this.paused = true;
            this.lastCode = value;

            const input = document.getElementById('{{ \Modules\Warehouse\App\Filament\Support\WarehouseBarcodeScan::INPUT_ID }}');
            if (input) {
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                // .blur() (phương thức DOM) CHỈ phát sinh sự kiện blur thật nếu input đang thực sự
                // được focus — input này chưa từng được bấm vào khi quét bằng camera (mở modal qua
                // nút camera, không qua việc click vào ô), và modal của Filament có focus trap riêng
                // nên gọi .focus() trước cũng không chắc ăn. Tự tạo + bắn thẳng sự kiện 'blur' (khác
                // .blur()) đi đúng đích, không phụ thuộc trạng thái focus thật của trình duyệt —
                // thiếu bước này thì WarehouseBarcodeScan::field() (live(onBlur: true)) không nhận
                // được giá trị mới cho tới khi người dùng tự bấm Enter (mới có sự kiện thật).
                input.dispatchEvent(new Event('blur', { bubbles: true }));
            }

            setTimeout(() => {
                this.lastCode = null;
                this.paused = false;
            }, 900);
        },
        stop() {
            if (this.reader) {
                try { this.reader.reset(); } catch (e) {}
                this.reader = null;
            }
        },
    }"
    x-on:closed.stop="stop()"
>
    <template x-if="! supported">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Không mở được camera để quét (có thể do chưa cấp quyền camera, không có camera, hoặc mất
            mạng khi tải thư viện quét). Hãy nhập/dán mã vạch trực tiếp vào ô, hoặc dùng máy quét mã
            vạch — cắm/kết nối vào là gõ thẳng ra ô nhập, không cần thao tác gì thêm.
        </p>
    </template>

    <template x-if="supported">
        <div style="position: relative;">
            <video x-ref="video" autoplay playsinline muted style="width:100%;border-radius:0.5rem;background:#000;"></video>

            <div
                x-show="lastCode"
                x-transition
                style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.55);border-radius:0.5rem;"
            >
                <div class="flex items-center gap-2 text-white font-medium">
                    <x-heroicon-o-check-circle class="h-8 w-8 flex-none text-success-400" />
                    <span>Đã quét: <strong x-text="lastCode"></strong></span>
                </div>
            </div>

            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Đưa camera vào mã vạch/QR trên sản phẩm — quét xong tự cộng luôn, cứ tiếp tục đưa
                vật tư tiếp theo vào, không cần đóng cửa sổ này. Bấm "Đóng" khi quét xong hết.
            </p>
        </div>
    </template>
</div>
