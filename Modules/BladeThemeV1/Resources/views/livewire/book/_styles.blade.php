<script>
    document.querySelectorAll(".tab-link").forEach(button => {
                button.addEventListener("click", () => {
                    document.querySelectorAll(".tab-pane").forEach(tab => tab.classList.add("hidden"));
                    document.getElementById(button.dataset.tab).classList.remove("hidden");

                    document.querySelectorAll(".tab-link").forEach(btn => btn.classList.remove("active-tab"));
                    button.classList.add("active-tab");
                });
            });
</script>
<style>
    /* ★★★ BLOCKED DATE STYLE ★★★ */
    .selectable.blocked {
        cursor: not-allowed !important;
        pointer-events: none;
    }

    .selectable.blocked::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background-color: #4e6b4c;
        z-index: 5;
    }

    /* Override: khi blocked có kèm promo — reset ::before, dùng ::after để phủ */
    .selectable.blocked.promo::before {
        content: "" !important;
        background: none !important;
        filter: none !important;
        animation: none !important;
        opacity: 0 !important;
    }

    .selectable.blocked.promo::after {
        content: "" !important;
        position: absolute !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        background-color: #4e6b4c !important;
        border-radius: inherit !important;
        z-index: 15 !important;
        display: block !important;
        pointer-events: none !important;
    }

    /* ★★★ END BLOCKED DATE STYLE ★★★ */

    /* ★★★ HELD (đang bị admin giữ chỗ real-time, xem TimeslotHoldService) ★★★
       Trước đây chỉ dựa vào opacity:0.6 chung (không có nền riêng) nên gần như không phân biệt
       được với ô bình thường — thêm nền màu cam riêng giống hệt cách .blocked/.booked đã làm. */
    .selectable.held {
        cursor: not-allowed !important;
        pointer-events: none;
    }

    .selectable.held::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background-color: #f59e0b;
        z-index: 5;
    }

    .selectable.held.promo::before {
        content: "" !important;
        background: none !important;
        filter: none !important;
        animation: none !important;
        opacity: 0 !important;
    }

    .selectable.held.promo::after {
        content: "" !important;
        position: absolute !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        background-color: #f59e0b !important;
        border-radius: inherit !important;
        z-index: 15 !important;
        display: block !important;
        pointer-events: none !important;
    }

    .lock-icon {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        z-index: 20;
        width: 16px;
        height: 16px;
        color: #fff;
        pointer-events: none;
    }

    .lock-icon svg {
        width: 100%;
        height: 100%;
    }

    /* ★★★ END HELD STYLE ★★★ */

    .promo-badge-btn {
        position: relative;
        z-index: 1;
        background: #fff;
    }

    .promo-badge-btn::before {
        content: "";
        position: absolute;
        top: -2px;
        left: -2px;
        right: -2px;
        bottom: -2px;
        border-radius: 9999px;
        background: linear-gradient(270deg,
                #ff0000,
                #ff9900,
                #33ff00,
                #00ffff,
                #3300ff,
                #ff00cc,
                #ff0000);
        background-size: 300% 300%;
        animation: borderFlow 10s linear infinite;
        z-index: -1;
        filter: blur(5px);
    }

    .promo-badge-btn::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: 9999px;
        background: #fff;
        z-index: -1;
    }

    .promotion-corner-image {
        position: absolute;
        top: -7px;
        right: -7px;
        z-index: 30;
        width: 18px;
        height: 18px;
        overflow: hidden;
        animation: bounce-corner 2s ease-in-out infinite;
    }

    .corner-img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        transition: transform 0.3s ease;
    }

    .selectable:hover .corner-img {
        transform: scale(1.15);
    }

    @keyframes bounce-corner {

        0%,
        100% {
            transform: translateY(0) scale(1);
        }

        50% {
            transform: translateY(-3px) scale(1.05);
        }
    }

    /* ========== PROMOTION CENTER LABEL ========== */
    .promotion-center-label {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        z-index: 25;

        font-size: 10px;
        font-weight: 700;
        color: #4c1d95;

        white-space: nowrap;
        text-overflow: ellipsis;
        overflow: hidden;
        max-width: 90%;

        text-shadow: 0 1px 2px rgba(255, 255, 255, 0.8);

        animation: pulse-label 2.5s ease-in-out infinite;
    }

    @keyframes pulse-label {

        0%,
        100% {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
        }

        50% {
            opacity: 0.9;
            transform: translate(-50%, -50%) scale(1.05);
        }
    }

    #default-styled-tab button[aria-selected="true"] {
        border-bottom-width: 2px !important;
    }

    .selectable.past-time::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background-color: #f3f4f6;
    }

    .selectable.past-time {
        border: 1px solid #d1d5db;
    }

    .selectable.past-time::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background-color: #f3f4f6;
    }

    /* Ghi đè viền hồng mặc định cho ô quá giờ */
    .selectable.past-time {
        border: 1px solid #d1d5db;
        /* Tailwind gray-300 */
    }

    .animated-img {
        z-index: 20;
        animation: zoomInOut 1s infinite;
    }

    /* Style cho ô "Đang chọn" (active) */
    .selectable.active::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background-color: var(--color-tickGray);
    }

    /* Style cho ô "Đã Đặt" (logic mới) */
    .selectable.booked::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background-color: var(--order-color, var(--color-primary));
    }

    /* Style cho ô "Đang chọn" (logic mới) */
    .selectable.pending::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background-color: var(--order-color, var(--color-tickGray));
    }

    .selectable {
        position: relative;
        z-index: 10;
        height: 36px;
        padding: 0 0.5rem;
        font-weight: bold;
        color: #333;
        background-color: #fff;
        border-radius: 8px;
        border: 1px solid var(--color-primary);

        display: flex;
        align-items: center;
        justify-content: center;
        overflow: visible;
        /* Cho phép hiệu ứng lóe ra ngoài */
    }

    /* Chỉ có hiệu ứng khi có khuyến mãi */
    .selectable.promo::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background: linear-gradient(270deg,
                #ff0000,
                #ff9900,
                #33ff00,
                #00ffff,
                #3300ff,
                #ff00cc,
                #ff0000);
        background-size: 300% 300%;
        animation: borderFlow 10s linear infinite;

        z-index: -10;
        transform: scale(1, 01);
        filter: blur(5px);
        opacity: 1;
    }

    .selectable.promo::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background: #fff;
    }

    .selectable.booked::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background-color: var(--order-color, var(--color-primary));
        /* Màu "Đã Đặt" */
    }

    .selectable.pending::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background-color: var(--order-color, var(--color-tickGray));
    }

    .selectable-mini {
        position: relative;
        z-index: 1;
        background-color: #fff;
        border: 2px solid var(--color-tickGray);
        overflow: visible;
    }

    .promo-mini::before {
        content: "";
        position: absolute;
        top: -2px;
        left: -2px;
        right: -2px;
        bottom: -2px;
        border-radius: inherit;
        background: linear-gradient(270deg,
                #ff4d4d,
                #ffb84d,
                #70ff70,
                #70ffff,
                #7070ff,
                #ff70e0,
                #ff4d4d);
        background-size: 200% 200%;
        animation: borderFlow 15s linear infinite;

        z-index: -1;
        filter: blur(3px);
        opacity: 1;
    }

    .promo-mini::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background: #fff;
        z-index: 0;
    }



    .selectable.active::after {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: inherit;
        background-color: var(--color-tickGray);
    }



    .active-tab {
        border: #ff566b 2px solid;
        color: #ff566b;
    }

    .active-tab:hover {
        border: #ff566b 2px solid;
        color: #ff566b;
    }

    @keyframes zoomInOut {
        0% {
            transform: scale(1);
        }

        50% {
            transform: scale(1.2);
        }

        100% {
            transform: scale(1);
        }
    }

    @keyframes borderFlow {
        0% {
            background-position: 0% 50%;
        }

        100% {
            background-position: 300% 50%;
        }
    }

    .rainbow-border-btn {
        display: inline-block;
        padding: 6px 18px;
        border-radius: 999px;
        background:
            linear-gradient(#fff, #fff) padding-box,
            linear-gradient(90deg, #ff0000, #ff7700, #ffff00, #00ff00, #0099ff, #6600ff, #ff00ff, #ff0000) border-box;
        background-size: auto, 200% auto;
        border: 2px solid transparent;
        animation: borderShimmer 3s linear infinite;
        font-size: 0.85rem;
        /* Không dùng white-space:nowrap nữa — text khá dài ("Tặng 2 nước ngọt và 1 snack khi đặt
           từ 2+ khung giờ"), nowrap khiến pill tràn ra ngoài khung chứa ở màn hình hẹp thay vì
           xuống dòng. box-sizing + max-width đảm bảo không vượt quá bề rộng card cha dù ở
           context nào (bottom sheet mobile lẫn card desktop). */
        white-space: normal;
        box-sizing: border-box;
        max-width: 100%;
    }

    @keyframes borderShimmer {
        0% {
            background-position: auto, 0% center;
        }

        100% {
            background-position: auto, 200% center;
        }
    }
</style>

<style id="book-redesign">
    /* ═══════════════════════════════════════════════
           BOOKING UI — Forest Green Theme (#4e6b4c)
           ═══════════════════════════════════════════════ */

    /* ── Tab buttons ── */
    .book-tab-active {
        background: linear-gradient(135deg, #4e6b4c, #6a8f68) !important;
        color: white !important;
        box-shadow: 0 4px 14px rgba(78, 107, 76, 0.35);
    }

    .book-tab-inactive {
        color: #4e6b4c;
    }

    .book-tab-inactive:hover {
        background: rgba(255, 255, 255, 0.55);
        color: #3a5239;
    }

    /* ── Legend pills ── */
    .book-legend-pill {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 5px 14px;
        background: white;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 500;
        color: #6b7280;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        border: 1px solid #d4e4d2;
    }

    .book-legend-dot {
        display: inline-block;
        width: 14px;
        height: 14px;
        flex-shrink: 0;
    }

    /* ── Table card wrapper ── */
    .book-card-wrap {
        background: white;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 8px 32px rgba(78, 107, 76, 0.12), 0 2px 8px rgba(0, 0, 0, 0.04);
        border: 1px solid #d4e4d2;
    }

    /* ── Selectable → pill shape, 1 màu xám nhạt trắng duy nhất (không đổi theo sản phẩm),
         box-shadow inset kiểu Tailwind (shadow-inner) để trông "chìm" nhẹ, dễ nhận biết là
         có thể bấm chọn ── */
    .selectable {
        border-radius: 999px !important;
        background: #f9fafb !important;
        border: 1px solid #e5e7eb !important;
        box-shadow: inset 0 2px 4px 0 rgb(0 0 0 / 0.05);
        color: #374151 !important;
        transition: all 0.18s ease;
        height: 32px;
    }

    .selectable:hover:not([style*="pointer-events:none"]) {
        background: #f3f4f6 !important;
        border-color: var(--color-primary, #4e6b4c) !important;
        box-shadow: inset 0 2px 4px 0 rgb(0 0 0 / 0.05), 0 4px 12px rgba(0, 0, 0, 0.08);
        transform: translateY(-1px);
    }

    /* ── Slot states with pill radius ── */
    .selectable.active::after {
        border-radius: 999px !important;
        background-color: var(--color-tickGray) !important;
        background-image: none !important;
    }

    .selectable.booked::after {
        border-radius: 999px !important;
        background: var(--order-color, #4e6b4c) !important;
    }

    .selectable.pending::after {
        border-radius: 999px !important;
        background: var(--order-color, #9ca3af) !important;
        opacity: 0.75;
    }

    .selectable.past-time::after {
        border-radius: 999px !important;
    }

    .selectable.blocked::after {
        border-radius: 999px !important;
    }

    /* ── Override selectable-mini legend dot ── */
    .selectable-mini {
        background: linear-gradient(135deg, #eef2ed, #e8f0e6) !important;
        border: 1.5px solid #a8c4a0 !important;
        border-radius: 999px !important;
    }

    /* ── Pricing card ── */
    .book-pricing-card {
        background: white;
        border-radius: 20px;
        padding: 22px 24px;
        box-shadow: 0 8px 32px rgba(78, 107, 76, 0.12), 0 2px 8px rgba(0, 0, 0, 0.04);
        border: 1px solid #d4e4d2;
    }

    /* ── CTA button — dùng đúng màu primary của theme (trước đây cứng màu xanh lá, sai với
         theme thực tế của trang) ── */
    .book-cta-btn {
        padding: 14px 36px;
        border-radius: 999px;
        font-weight: 800;
        font-size: 1rem;
        color: white;
        background: var(--color-primary, #4e6b4c);
        border: none;
        cursor: pointer;
        box-shadow: 0 8px 24px rgba(var(--color-primary-rgb, 78, 107, 76), 0.35);
        transition: all 0.3s ease;
        letter-spacing: 0.04em;
        width: 100%;
        display: block;
    }

    .book-cta-btn:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: 0 12px 32px rgba(var(--color-primary-rgb, 78, 107, 76), 0.45);
        filter: brightness(1.08);
    }

    .book-cta-btn:disabled {
        opacity: 0.45;
        cursor: not-allowed;
        transform: none;
    }

    /* ── Row hover tint ── */
    tbody tr:hover td {
        background-color: rgba(238, 242, 237, 0.5) !important;
    }

    /* ═══════════════════════════════════════════════
           TWO-PANEL CARD LAYOUT  (mobile)
           ═══════════════════════════════════════════════ */

    /* ── Hàng trên cùng: tên phòng + nút trượt trải rộng hết chiều ngang (w-full) ── */
    .book-top-row {
        display: flex;
        gap: 12px;
        padding: 0 10px;
        margin-bottom: 12px;
    }

    /* ── Room navigation header — tách riêng, nằm trên nền màu của .book-card-outer,
         không dùng chung nền trắng với khối "Khung giờ" bên dưới. Tên phòng bên trái, cụm nút
         trượt bên phải (không còn box-shadow nổi khối). ── */
    .book-room-nav-wrap {
        flex: 1;
        min-width: 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 0px 6px;
        background: #ffffff;
        color: #111827;
        border-radius: 14px;
    }

    /* ── Cụm 2 nút trượt gộp gần nhau (thay vì mỗi nút 1 đầu hàng) ── */
    .book-nav-btn-group {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-shrink: 0;
    }

    .book-nav-btn {
        background: #f9fafb;
        border: 1.5px solid #e5e7eb;
        color: inherit;
        width: 26px;
        height: 26px;
        /* Có rule chung "button { min-width/min-height: 44px }" (chuẩn kích thước chạm tối
           thiểu) áp cho mọi <button> trên site — .book-nav-btn không khai báo min-width/height
           nên vẫn bị rule đó thắng riêng ở 2 thuộc tính này dù width/height đã set 26px. Đặt lại
           về 0 để nút thực sự nhỏ như đã chọn. */
        min-width: 0;
        min-height: 0;
        border-radius: 50%;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: background 0.2s, transform 0.15s;
    }

    .book-nav-btn svg {
        width: 14px;
        height: 14px;
        flex-shrink: 0;
    }

    /* Nút prev/next mobile có thêm chữ sau icon ("Trước"/"Sau") — chỉ áp cho .book-nav-btn-group
       ở book/_mobile.blade.php, không đụng tới .book-nav-btn gốc (vẫn hình tròn chỉ icon, dùng ở
       carousel head của book/_desktop-grid.blade.php). */
    .book-nav-btn.book-nav-btn-labeled {
        width: auto;
        height: 26px;
        padding: 0 7px;
        border-radius: 999px;
        gap: 3px;
    }

    .book-nav-btn-labeled span {
        font-size: 0.62rem;
        font-weight: 600;
        white-space: nowrap;
    }

    .book-nav-btn-labeled svg {
        width: 11px;
        height: 11px;
    }

    .book-nav-btn:hover {
        background: #f3f4f6;
        transform: scale(1.08);
    }

    .book-room-titles-wrap {
        flex: 1;
        text-align: left;
        min-height: 36px;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        min-width: 0;
    }

    .book-room-title-block {
        text-align: left;
        width: 100%;
    }

    .book-room-name {
        font-family: 'Georgia', 'Times New Roman', serif;
        font-style: italic;
        font-size: 1.15rem;
        font-weight: 700;
        color: inherit;
        line-height: 1.1;
        margin: 0;
    }

    /* Icon flash-sale.gif bên phải tên phòng — chỉ hiện khi phòng có khung giờ đang khuyến mãi
       giảm giá (xem $roomHasDiscount trong book/_mobile.blade.php và book/_desktop-grid.blade.php).
       inline-block + vertical-align (thay vì đổi .book-room-name sang flex) để không phá
       text-align:center đang tự canh giữa chính h3 đó ở bản desktop (.book-dt-room-name). */
    .book-room-flash-icon {
        display: inline-block;
        width: 28px;
        height: 28px;
        object-fit: contain;
        vertical-align: middle;
        margin-left: 6px;
        margin-top: -3px;
    }

    /* ── Grid header — nằm NGOÀI khung cuộn (không còn position:sticky vì không cần thiết
         nữa: phần cuộn giờ nằm bên trong từng card thân, header đứng yên tự nhiên phía trên). ── */
    .book-grid-header {
        display: flex;
        gap: 12px;
        padding: 0 10px;
    }

    .book-grid-header .book-col-header {
        min-width: 75px;
        width: 75px;
        flex-shrink: 0;
        background: #ffffff;
        border: 1px solid #f0f0f0;
        border-bottom: none;
        border-radius: 10px 10px 0 0;
    }

    .book-slots-headers-wrap {
        flex: 1;
        min-width: 0;
        overflow: hidden;
        border-radius: 10px 10px 0 0;
    }

    .book-slots-headers-wrap .book-slots-header-row {
        background: #ffffff;
        border: 1px solid #f0f0f0;
        border-bottom: none;
        border-radius: 10px 10px 0 0;
    }

    /* ── Hiển thị đầy đủ cột Ngày/khung giờ trên mobile (không giới hạn chiều cao + cuộn nội bộ
         như trước) — nội dung tự nhiên cao bao nhiêu thì card cao bấy nhiêu, người dùng cuộn
         cả trang thay vì cuộn riêng bên trong từng card. ── */
    .book-dates-scroll,
    .book-slots-scroll {
        overflow-x: hidden;
    }

    .book-dates-scroll::-webkit-scrollbar,
    .book-slots-scroll::-webkit-scrollbar {
        display: none;
        width: 0;
        height: 0;
    }

    /* ── Outer wrapper (bottom) — nền trong suốt (lộ màu .book-card-outer ở khoảng gap giữa
         2 cột), không có padding-top để dính liền với header phía trên (.book-grid-header) ── */
    .book-grid-outer {
        display: flex;
        gap: 12px;
        background: transparent;
        padding: 0 10px 12px;
        border-radius: 0;
        align-items: stretch;
    }

    /* ── Left dates card — dính liền với header "Ngày" phía trên (cùng nền, chỉ bo góc dưới),
         tách hẳn khỏi khối khung giờ bên phải. box-shadow inset kiểu Tailwind (shadow-inner)
         để trông "chìm" nhẹ so với 2 khối trắng nổi bên cạnh. Không giới hạn chiều cao — hiện
         đầy đủ toàn bộ ngày, không cuộn nội bộ (xem .book-dates-scroll ở trên). ── */
    .book-dates-card {
        background: #f9fafb;
        border: 1px solid #f0f0f0;
        border-top: none;
        border-radius: 0 0 10px 10px;
        box-shadow: inset 0 2px 4px 0 rgb(0 0 0 / 0.05);
        min-width: 75px;
        width: 75px;
        flex-shrink: 0;
        overflow: hidden;
    }

    .book-col-header {
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.65rem;
        font-weight: 700;
        color: #374151;
        letter-spacing: 0.06em;
        text-transform: uppercase;
    }

    .book-date-row {
        height: 30px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        border-bottom: 1px solid #f3f4f6;
        gap: 1px;
        padding: 0 4px;
    }

    .book-date-row:last-child {
        border-bottom: none;
    }

    .book-date-day {
        font-size: 0.62rem;
        color: #4b5563;
        opacity: 1;
        font-weight: 500;
        line-height: 1;
    }

    .book-date-num {
        font-size: 0.78rem;
        font-weight: 700;
        color: #111827;
        line-height: 1;
    }

    .book-date-row.is-today .book-date-day,
    .book-date-row.is-today .book-date-num {
        color: #374151;
        font-weight: 800;
        opacity: 1;
    }

    /* ── Right slots outer (flex:1) — overflow ẩn để hiệu ứng trượt phòng không lộ ra ngoài ── */
    .book-slots-outer {
        flex: 1;
        min-width: 0;
        overflow: hidden;
    }

    /* ── Slots card per room — dính liền với header khung giờ phía trên (chỉ bo góc dưới).
         Không giới hạn chiều cao — hiện đầy đủ toàn bộ ngày, khớp .book-dates-card. ── */
    .book-slots-card {
        background: #ffffff;
        border: 1px solid #f0f0f0;
        border-top: none;
        border-radius: 0 0 14px 14px;
        overflow: hidden;
        max-width: 100%;
    }

    /* ── Slot column headers ── */
    .book-slots-header-row {
        display: flex;
        gap: 3px;
        padding: 0 4px;
        height: 40px;
        align-items: center;
    }

    .book-slot-th {
        flex: 1;
        text-align: center;
        font-size: 0.72rem;
        font-weight: 700;
        color: #111827;
        letter-spacing: -0.02em;
        line-height: 1.2;
        min-width: 0;
    }

    /* Mobile mặc định: giờ bắt đầu/kết thúc xếp 2 dòng (dùng <br>), ẩn dấu gạch nối */
    .book-slot-time-sep {
        display: none;
    }

    /* Icon mặt trời (khung giờ thường)/mặt trăng (qua đêm) thay cho chữ "Qua đêm" — mobile chật
       chỗ nên chỉ dùng icon, không kèm chữ (khác desktop-grid vẫn giữ icon + "(Qua đêm)"). */
    .book-slot-icon {
        display: block;
        width: 12px;
        height: 12px;
        margin: 2px auto 0;
    }

    /* ── Slot data rows ── */
    .book-slots-row {
        display: flex;
        gap: 3px;
        padding: 3px 4px;
        height: 30px;
        align-items: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.12);
    }

    .book-slots-row:last-child {
        border-bottom: none;
    }

    .book-slot-cell {
        flex: 1;
        min-width: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    /* Override .selectable inside the new grid */
    .book-slot-cell .selectable {
        width: 100%;
        height: 22px !important;
        border-radius: 999px !important;
    }

    /* .selectable.promo::before (glow khuyến mãi, xem định nghĩa gốc phía trên) dùng blur(5px) —
       ô lưới mobile ở đây quá nhỏ/sát nhau (cell 22px trong hàng 30px, gap chỉ 3px) nên glow to
       bị .book-slots-scroll (cần overflow-y:auto để cuộn dọc, kéo theo overflow-x cũng bị kẹp
       cứng theo, không thể để "visible" lộ ra ngoài như ở product-detail) cắt cụt — thu blur lại
       cho vừa khoảng hở sẵn có giữa các ô, để hiệu ứng vẫn tròn trịa/rõ thay vì bị cắt phẳng cạnh. */
    .book-slot-cell .selectable.promo::before {
        filter: blur(2px);
    }

    /* ── Slot page pagination strip ── */
    .slot-page-strip {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding: 5px 10px;
        background: linear-gradient(135deg, #3a5239 0%, #4e6b4c 100%);
    }

    .slot-pg-btn {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        height: 24px;
        padding: 0 10px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.18);
        border: 1.5px solid rgba(255, 255, 255, 0.45);
        color: white;
        font-size: 0.68rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.15s;
        white-space: nowrap;
    }

    .slot-pg-btn:hover:not(:disabled) {
        background: rgba(255, 255, 255, 0.32);
    }

    .slot-pg-btn:disabled {
        opacity: 0.3;
        cursor: default;
    }

    .slot-pg-info {
        color: rgba(255, 255, 255, 0.9);
        font-size: 0.72rem;
        font-weight: 600;
        text-align: center;
        flex: 1;
    }

    /* x-cloak support */
    [x-cloak] {
        display: none !important;
    }

    /* ── Nút "Xem thêm ngày" (mobile) — 1 nút duy nhất, tự ẩn khi đã hiện hết ngày ── */
    .book-loadmore-row {
        display: flex;
        justify-content: center;
        margin-top: 8px;
        padding: 0 10px;
    }

    .book-loadmore-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        flex: 0 0 auto;
        min-width: 0;
        margin-top: 0;
        padding: 12px 30px;
        border-radius: 999px;
        border: 1.5px dashed rgba(78, 107, 76, 0.4);
        background: #f4f8f3;
        color: #4e6b4c;
        font-size: 0.75rem;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.15s, border-color 0.15s, transform 0.15s;
    }

    .book-loadmore-btn:hover {
        background: #e8f0e6;
        border-color: #6a8f68;
        transform: translateY(1px);
    }

    .book-loadmore-btn svg {
        transition: transform 0.15s;
    }

    .book-loadmore-btn:hover svg {
        transform: translateY(1px);
    }

    /* ── Desktop (≥lg): cột "Thời gian" (ngày) cố định dùng chung + carousel Center Mode/Peek
         cho khối khung giờ của từng phòng — book/_desktop-grid.blade.php. Phòng đang active hiển
         thị đầy đủ, rõ; 2 phòng liền kề hé lộ 1 phần, thu nhỏ + mờ, KHÔNG chọn được khung giờ
         (pointer-events:none) cho tới khi được bấm/trượt vào giữa. Phong cách/kích thước ô giữ
         giống khối .pd-dt-* ở trang chi tiết sản phẩm (card bo góc trắng, ô giờ cao 48px). ── */
    .book-dt-wrap { position: relative; }

    .book-dt-carousel-head {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 14px;
        margin-bottom: 14px;
    }

    .book-dt-pagination.swiper-pagination {
        position: static;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .book-dt-pagination .swiper-pagination-bullet {
        background: #d1d5db;
        opacity: 1;
        width: 10px;
        height: 10px;
    }

    .book-dt-pagination .swiper-pagination-bullet-active {
        background: var(--color-primary, #4e6b4c);
        width: 26px;
        border-radius: 5px;
    }

    /* ── Nút Trước/Sau to hơn ở carousel head desktop (khác bản gọn .book-nav-btn-labeled dùng
         cho carousel phòng ở mobile) — có chữ kèm icon, kích thước đủ lớn để bấm thoải mái. ── */
    .book-nav-btn.book-dt-nav-btn-lg {
        width: auto;
        height: 40px;
        padding: 0 18px;
        gap: 8px;
        min-width: 0;
        min-height: 0;
    }

    .book-dt-nav-btn-lg svg {
        width: 18px;
        height: 18px;
    }

    .book-dt-nav-btn-lg span {
        font-size: 0.9rem;
        font-weight: 600;
        white-space: nowrap;
    }

    /* ── Layout: cột ngày cố định bên trái | carousel phòng chiếm phần còn lại (không viền/nền
         khung ngoài — chỉ .book-dt-carousel-col có overflow:hidden để kẹp gọn peek carousel,
         tránh tràn đè lên cột ngày hoặc tràn ra ngoài trang, xem ghi chú bên dưới). ── */
    .book-dt-body {
        display: flex;
        gap: 12px;
        align-items: stretch;
    }

    .book-dt-dates-col {
        width: 92px;
        flex-shrink: 0;
    }

    .book-dt-col-header {
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.7rem;
        font-weight: 700;
        color: #374151;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 8px;
    }

    .book-dt-dates-card {
        height: 360px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        overflow: hidden;
    }

    .book-dt-dates-scroll,
    .book-dt-slots-scroll {
        height: 100%;
        overflow-y: auto;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    .book-dt-dates-scroll::-webkit-scrollbar,
    .book-dt-slots-scroll::-webkit-scrollbar {
        display: none;
        width: 0;
        height: 0;
    }

    /* Khi phòng có nhiều khung giờ hơn chiều rộng slide có thể chứa (cột giờ dùng chiều rộng cố
       định min 78px, xem .book-dt-slot-th/.book-dt-slot-cell bên dưới) — kéo ngang thay vì tràn
       ra ngoài khung. Header giờ (.book-dt-slots-header-row) và khối ô chọn khung giờ
       (.book-dt-slots-scroll) đồng bộ scrollLeft với nhau qua @scroll (xem _desktop-grid.blade.php). */
    .book-dt-slots-scroll { overflow-x: auto; }

    .book-dt-date-row {
        height: 40px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        border-bottom: 1px solid #f3f4f6;
        text-align: center;
        font-size: 0.85rem;
    }

    .book-dt-date-row:last-child { border-bottom: none; }

    .book-dt-date-row.is-today { background: #f9fafb; }

    .book-dt-date-row.is-today > div:first-child {
        color: var(--color-primary, #0f766e);
    }

    /* ── Cột carousel: overflow HIDDEN — kẹp gọn slide hé lộ (peek) trong đúng khung của cột
         này, không cho tràn đè lên cột Ngày bên trái hay tràn ra ngoài trang (từng để
         overflow:visible gây 2 lỗi: cột Ngày bị slide bên trái đè lên nhìn mờ đục, và trang bị
         thanh cuộn ngang do slide bên phải tràn qua khung max-w-11xl). ── */
    .book-dt-carousel-col {
        flex: 1;
        min-width: 0;
        overflow: hidden;
        border-radius: 12px;
    }

    .book-dt-swiper {
        overflow: visible;
        padding: 4px 2px;
    }

    .book-dt-room-name {
        text-align: center;
        font-size: 1.1rem;
        margin-bottom: 8px;
        transition: opacity .3s ease;
    }

    .book-dt-slots-header-row {
        display: flex;
        gap: 6px;
        padding: 4px 8px;
        min-height: 40px;
        align-items: center;
        background: #fff;
        border: 1px solid var(--room-border-color, #e5e7eb);
        border-radius: 12px;
        margin-bottom: 8px;
        transition: opacity .3s ease;
        overflow-x: auto;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    .book-dt-slots-header-row::-webkit-scrollbar {
        display: none;
        width: 0;
        height: 0;
    }

    .book-dt-slot-th {
        flex: 1 0 78px;
        text-align: center;
        font-size: 10px;
        font-weight: 700;
        color: #111827;
    }

    .book-dt-slots-card {
        height: 360px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        overflow: hidden;
        transition: opacity .3s ease, transform .3s ease;
    }

    .book-dt-slots-row {
        display: flex;
        gap: 6px;
        padding: 4px 8px;
        height: 40px;
        align-items: center;
        border-bottom: 1px solid #f3f4f6;
    }

    .book-dt-slots-row:last-child { border-bottom: none; }

    .book-dt-slots-row:hover { background-color: #f9fafb; }

    .book-dt-slot-cell {
        flex: 1 0 78px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .book-dt-slot-cell .selectable { width: 100%; height: 28px; }

    /* .selectable.promo::before (glow khuyến mãi, blur(5px) gốc) bị hàng lưới desktop 40px cao,
       ô 78px rộng cắt cụt cạnh tương tự .book-slot-cell ở trên — thu blur lại cho vừa. */
    .book-dt-slot-cell .selectable.promo::before {
        filter: blur(2px);
    }

    /* ── Bảng tính giá đầy đủ (Giá cơ bản, phụ thu, khuyến mãi, tổng tiền tạm tính): ẩn hẳn
         cả mobile lẫn desktop — mobile hiện trong bottom sheet riêng (book.blade.php), desktop
         thay bằng nút "Đặt phòng ngay" + tổng tiền tạm tính gọn trên hàng chú thích trạng thái
         (xem book/_legend.blade.php) để phần này trống/gọn hơn thay vì chiếm cả khối bên dưới. ── */
    .book-pricing-desktop {
        display: none;
    }

    /* ── Mobile bottom sheet — hiện sau khi user chọn khung giờ, chứa đầy đủ bảng tính giá.
         Không còn backdrop toàn màn hình (đã bỏ — chặn mất click chọn thêm khung giờ),
         chỉ còn box-shadow của chính sheet để tách biệt về mặt thị giác. ── */
    .book-bottom-sheet {
        position: fixed;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 10999;
        background: white;
        border-radius: 20px 20px 0 0;
        box-shadow: 0 -8px 32px rgba(0, 0, 0, 0.18);
        max-height: 85vh;
        display: flex;
        flex-direction: column;
        padding-bottom: env(safe-area-inset-bottom, 0px);
    }

    .book-sheet-handle-row {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        /* Padding ngang khớp với .book-sheet-scroll (18px) để nút đóng + nội dung bên dưới
           thẳng hàng cùng 1 mốc lề — trước đây 14px vs 18px khiến nút đóng lệch vào trong so
           với "Giá cơ bản"/"Tổng tiền tạm tính" phía dưới. */
        padding: 12px 18px 4px;
    }

    .book-sheet-handle {
        width: 40px;
        height: 4px;
        border-radius: 999px;
        background: #d1d5db;
    }

    .book-sheet-close {
        position: absolute;
        right: 5px;
        top: 15px;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        border: none;
        background: #f3f4f6;
        color: #6b7280;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }

    .book-sheet-scroll {
        overflow-y: auto;
        padding: 0 18px 18px;
    }

    .book-sheet-scroll .book-pricing-card {
        box-shadow: none;
        border: none;
        padding: 4px 0 0;
    }

    @media (min-width: 1024px) {
        .book-bottom-sheet {
            display: none !important;
        }
    }

    /* Alpine x-transition classes for the bottom sheet */
    .sheet-enter { transition: transform 0.3s ease; }
    .sheet-enter-from { transform: translateY(100%); }
    .sheet-enter-to { transform: translateY(0); }
    .sheet-leave { transition: transform 0.25s ease; }
    .sheet-leave-from { transform: translateY(0); }
    .sheet-leave-to { transform: translateY(100%); }

    /* ── Desktop/tablet: nút chọn khung giờ cao hơn, đỡ dẹt/dài như bản mobile ── */
    @media (min-width: 768px) {
        .book-date-row {
            height: 44px;
        }

        .book-slots-row {
            height: 44px;
        }

        .book-slot-cell .selectable {
            height: 34px !important;
        }

        /* Giờ bắt đầu/kết thúc nằm ngang trên 1 dòng (không xuống dòng như mobile), chữ to hơn */
        .book-slot-th {
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .book-slot-time-br {
            display: none;
        }

        .book-slot-time-sep {
            display: inline;
        }

        /* Icon mặt trời/mặt trăng to hơn trên desktop/tablet */
        .book-slot-icon {
            width: 15px;
            height: 15px;
            margin-top: 3px;
        }

        /* Nút "Xem thêm ngày" to hơn trên desktop/tablet */
        .book-loadmore-btn {
            padding: 12px 28px;
            font-size: 0.95rem;
        }
    }

    /* ── Nút "Đặt phòng ngay" (giá + chữ gộp làm 1 nút) gọn trên hàng chú thích trạng thái
         (chỉ desktop — mobile vẫn dùng bottom sheet đầy đủ như cũ). ── */
    /* .book-cta-btn gốc to/full-width (dùng cho bottom sheet mobile) — bản này gọn lại vừa
       chữ, không chiếm hết chiều ngang, hợp với chỗ đặt chung hàng chú thích trạng thái.
       Chọn .book-cta-btn.book-legend-cta-btn (2 class) để độ đặc hiệu thắng rule
       ".book-cta-btn { box-shadow ... !important }" ở khối #book-sync-product-detail phía
       dưới (1 class, !important) bất kể thứ tự khai báo trong file. */
    /* display:none mặc định rồi mới bật lại ở @media (>=1024px, khớp breakpoint lg của Tailwind) —
       .book-cta-btn gốc tự đặt display:block (không !important) nhưng độ đặc hiệu 2-class của
       .book-cta-btn.book-legend-cta-btn vẫn thắng class "hidden" của Tailwind (cũng 1-class) ở
       MỌI kích thước màn hình nếu không tự khai báo display ở đây — khiến nút lẽ ra chỉ hiện ở
       desktop lại lộ ra cả ở mobile dù có "hidden lg:inline-block" trên thẻ button. */
    .book-cta-btn.book-legend-cta-btn {
        display: none;
        width: auto;
        padding: 9px 22px;
        font-size: 0.85rem;
        box-shadow: none !important;
    }

    @media (min-width: 1024px) {
        .book-cta-btn.book-legend-cta-btn {
            display: inline-block;
        }
    }

    .book-cta-btn.book-legend-cta-btn:hover:not(:disabled) {
        transform: none;
        box-shadow: 0 4px 12px rgba(var(--color-primary-rgb, 78, 107, 76), 0.3) !important;
    }
</style>

{{-- ═══════════════════════════════════════════════════════════════════════════
     Đồng bộ giao diện lịch đặt phòng với trang chi tiết sản phẩm (product-detail):
     box bo góc vuông nhẹ (không còn pill tròn), nền/viền trung tính, "đã đặt" = màu
     primary (không icon), "đang chờ" (pending) = vàng (không icon), "đang chọn" = đen
     thật, "bị khóa" (blocked, admin khóa ngày) = giống hệt "đã đặt" (màu primary, không
     icon) — khách không cần phân biệt lý do không đặt được. Đặt ở cuối cùng
     (sau #book-redesign) và dùng !important để thắng các rule màu/pill cũ phía trên,
     kể cả 2 rule 3-class ".selectable.blocked.promo::before/::after" có độ đặc hiệu
     cao hơn (0,0,3,0) so với các rule 2-class còn lại. ═══════════════════════════ --}}
<style id="book-sync-product-detail">
    .selectable {
        border-radius: 8px !important;
        background: #fff !important;
        border: 1.5px solid #d1d5db !important;
        color: #374151 !important;
        box-shadow: inset 0 1px 3px rgba(0, 0, 0, .08) !important;
    }

    .selectable:hover:not([style*="pointer-events:none"]) {
        background: #f3f4f6 !important;
        border-color: #9ca3af !important;
        box-shadow: inset 0 1px 3px rgba(0, 0, 0, .08), 0 4px 12px rgba(0, 0, 0, .08) !important;
    }

    .book-slot-cell .selectable,
    .selectable-mini {
        border-radius: 6px !important;
    }

    /* Tắt hẳn các lớp phủ ::before/::after màu cũ (order-color, tickGray…) để nền/màu đặt trực
       tiếp trên .selectable phía dưới được hiển thị đúng. Không tắt .selectable.promo (không
       kèm trạng thái khác) — giữ nguyên hiệu ứng lóe màu cầu vồng "Khuyến mãi" giống trang chi
       tiết phòng. */
    .selectable.active::after,
    .selectable.booked::after,
    .selectable.pending::after,
    .selectable.blocked::after,
    .selectable.past-time::after,
    .selectable.past-date::after,
    /* Ô vừa ở trạng thái khác (đã đặt/đang chờ/bị khóa/đang chọn/quá giờ/quá ngày) vừa có
       khuyến mãi: trạng thái đó phải thắng, tắt CẢ gradient lẫn lớp phủ trắng của promo — nếu
       chỉ tắt ::after (lớp phủ trắng) mà để ::before (gradient) sống thì gradient hiện trần ra
       không có gì che, đúng lỗi đã gặp với past-date.promo. */
    .selectable.active.promo::before,
    .selectable.active.promo::after,
    .selectable.booked.promo::before,
    .selectable.booked.promo::after,
    .selectable.pending.promo::before,
    .selectable.pending.promo::after,
    .selectable.blocked.promo::before,
    .selectable.blocked.promo::after,
    .selectable.past-time.promo::before,
    .selectable.past-time.promo::after,
    .selectable.past-date.promo::before,
    .selectable.past-date.promo::after {
        content: none !important;
    }

    .selectable.booked,
    .selectable.blocked {
        background: var(--color-primary) !important;
        border-color: var(--color-primary) !important;
    }

    .selectable.pending {
        background: #CFDC74 !important;
        border-color: #CFDC74 !important;
        opacity: 1 !important;
    }

    .selectable.past-time,
    .selectable.past-date {
        background: #f3f4f6 !important;
        border: 1px solid #e5e7eb !important;
    }

    /* Đặt sau cùng để "đang chọn" luôn thắng dù ô đó cũng đang booked/pending (trường hợp
       khách quay lại chọn lại đúng slot thuộc đơn đang chờ của họ). opacity:1 !important để
       thắng luôn style inline opacity gắn theo !isSelectable, tránh đen bị pha loãng thành xám. */
    .selectable.active {
        background: #111827 !important;
        border-color: #111827 !important;
        opacity: 1 !important;
    }

    /* Hover vào ô đang chọn (active) phải vẫn giữ đen — không rơi về style hover trắng/xám
       chung của .selectable, nếu không trông như bị bỏ chọn khi rê chuột qua. */
    .selectable.active:hover {
        background: #111827 !important;
        border-color: #111827 !important;
        box-shadow: none !important;
        transform: none !important;
    }

    /* Ô vừa "đang chọn" (active, đen) vừa bị ADMIN giữ chỗ real-time (held, cam) — held PHẢI LUÔN
       thắng, không được để lộ màu đen "đang chọn" của khách khi thực ra slot đó vừa bị người khác
       giữ (echo-client.js cố tự click() bỏ chọn khi nhận sự kiện .held, nhưng vẫn có khe hở thời
       điểm 2 class tồn tại cùng lúc). Set lại CẢ nền trực tiếp lẫn lớp phủ ::after (kỹ thuật
       .held dùng) để chắc chắn thắng bất kể đường nào đang thua. */
    .selectable.active.held {
        background: #f59e0b !important;
        border-color: #f59e0b !important;
    }

    .selectable.active.held::after {
        background-color: #f59e0b !important;
        z-index: 5 !important;
    }

    /* ── Legend ── */
    .selectable-mini {
        background: #fff !important;
        border: 1.5px solid #dddddd !important;
    }

    /* ── Tách riêng cột "Ngày" và khối khung giờ thành 2 card độc lập, có khoảng cách ở
       giữa theo chiều ngang (theo ảnh mẫu) — header + thân của MỖI cột vẫn liền nhau theo
       chiều dọc (header bo góc trên, thân bo góc dưới, không viền giữa) để trông như 1 card
       duy nhất cho từng cột; chỉ tách rời 2 cột với nhau bằng khoảng cách + bóng đổ riêng. */
    .book-grid-header,
    .book-grid-outer {
        gap: 12px !important;
    }

    .book-grid-header .book-col-header {
        border: 1px solid var(--room-border-color, #e5e7eb) !important;
        border-bottom: none !important;
        border-radius: 14px 14px 0 0 !important;
        box-shadow: 0 4px 14px rgba(0, 0, 0, .06) !important;
    }

    .book-dates-card {
        border: 1px solid #e5e7eb !important;
        border-top: none !important;
        border-radius: 0 0 14px 14px !important;
        box-shadow: 0 4px 14px rgba(0, 0, 0, .06) !important;
    }

    .book-slots-headers-wrap {
        border-radius: 14px 14px 0 0 !important;
        overflow: hidden !important;
    }

    .book-slots-headers-wrap .book-slots-header-row {
        border: 1px solid var(--room-border-color, #e5e7eb) !important;
        border-bottom: none !important;
        border-radius: 14px 14px 0 0 !important;
        box-shadow: 0 4px 14px rgba(0, 0, 0, .06) !important;
    }

    .book-slots-card {
        border: 1px solid #e5e7eb !important;
        border-top: none !important;
        border-radius: 0 0 14px 14px !important;
        box-shadow: 0 4px 14px rgba(0, 0, 0, .06) !important;
    }

    /* ── Đường kẻ phân cách dọc giữa 2 phòng hiển thị cạnh nhau trong carousel desktop (mỗi
       .swiper-slide = 1 phòng, book/_desktop-grid.blade.php) — dùng pseudo-element (không phải
       border) để kẻ NGẮN lại, bo tròn 2 đầu, nằm lửng lơ giữa gap (top/bottom: 38% → tự động
       canh giữa theo chiều cao slide, chỉ chiếm ~24% ở giữa), không chạm vào tên phòng hay card
       khung giờ ở trên/dưới. Mọi slide đều cùng kích thước (không còn peek thu nhỏ) nên chỉ cần
       1 offset cố định, canh giữa khoảng gap (spaceBetween: 16px, xem mountBookDtSwiper()). ── */
    .book-dt-swiper .swiper-slide { position: relative; }
    .book-dt-swiper .swiper-slide:not(:last-child)::after {
        content: '';
        position: absolute;
        top: 38%;
        bottom: 38%;
        right: -10px;
        width: 4px;
        border-radius: 999px;
        background: var(--color-primary, #4e6b4c);
    }

    /* ── Phần còn lại vẫn dùng tông "Forest Green" cũ (#4e6b4c) — trung tính hoá nốt cho
       khớp bảng màu trắng/xám/đen của trang chi tiết. ── */
    .slot-page-strip {
        background: #f3f4f6 !important;
        border-bottom: 1px solid #e5e7eb !important;
    }

    .slot-pg-btn {
        background: #fff !important;
        border-color: #d1d5db !important;
        color: #374151 !important;
    }

    .slot-pg-btn:hover:not(:disabled) {
        background: #e5e7eb !important;
    }

    .slot-pg-info {
        color: #6b7280 !important;
    }

    .book-loadmore-btn {
        border: 1.5px dashed #d1d5db !important;
        background: #f9fafb !important;
        color: #374151 !important;
    }

    .book-loadmore-btn:hover {
        background: #f3f4f6 !important;
        border-color: #9ca3af !important;
    }

    .book-pricing-card {
        box-shadow: 0 8px 32px rgba(0, 0, 0, .08), 0 2px 8px rgba(0, 0, 0, .04) !important;
        border-color: #e5e7eb !important;
    }

    /* Gọn lại kích thước khung Summary cho vừa nội dung (bớt đệm/bo góc quá lớn), full
       chiều rộng cột thay vì co hẹp một khoảng như trước. */
    @media (min-width: 1024px) {
        .book-pricing-card {
            padding: 18px 20px !important;
            border-radius: 16px !important;
        }
    }

    .book-cta-btn {
        box-shadow: 0 8px 24px rgba(0, 0, 0, .18) !important;
    }

    .book-cta-btn:hover:not(:disabled) {
        box-shadow: 0 12px 32px rgba(0, 0, 0, .22) !important;
    }

    tbody tr:hover td {
        background-color: #f9fafb !important;
    }

    /* Header tên chi nhánh — chỉ hiện khi $showBranchHeader (nhúng ở trang chủ, nơi không có
       ngữ cảnh URL /branch/{slug} để biết đang xem lịch của chi nhánh nào). */
    .book-branch-header {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 16px;
        margin-bottom: 16px;
        border-radius: 14px;
        background: #f9fafb;
        border: 1px solid #f0f0f0;
    }

    .book-branch-header-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #fff;
        color: var(--color-primary, #111827);
        box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
        flex-shrink: 0;
    }

    .book-branch-header-icon svg {
        width: 20px;
        height: 20px;
    }

    .book-branch-header-title {
        margin: 0;
        font-size: 15px;
        font-weight: 800;
        color: #111827;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .book-branch-header-sub {
        margin: 2px 0 0;
        font-size: 12px;
        color: #6b7280;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* Trang chủ (home-booking-board.blade.php đánh dấu ancestor .home-book-scope): chỉ riêng
       khung lịch mobile (.book-panel, chứa card của book/_mobile.blade.php) tràn sát mép trái/phải
       màn hình bằng margin âm bù lại padding của ancestor — header/legend/bảng giá vẫn giữ padding
       bình thường. Không đụng tới các trang khác dùng chung Book.php (branch/{slug}, ?view=branches)
       vì các trang đó không có class .home-book-scope trên ancestor. */
    @media (max-width: 639.98px) {
        .home-book-scope .book-panel { margin-left: -16px; margin-right: -16px; }
    }
    @media (min-width: 640px) and (max-width: 1023.98px) {
        .home-book-scope .book-panel { margin-left: -24px; margin-right: -24px; }
    }
</style>

{{-- ═══════════════════════════════════════════════════════════════════════════
     Skeleton loading (book/_skeleton.blade.php) — hiện thay chỗ nội dung thật lúc
     Livewire đang tải dữ liệu (đổi chi nhánh/tab danh mục), gọn nhẹ hơn opacity mờ cũ.
     ═══════════════════════════════════════════════════════════════════════════ --}}
<style>
    {{-- Same fix as hs-skel-shimmer in flash-sale.blade.php — transform-based sweep instead of
         animating background-position, so the animation runs on the GPU compositor instead of
         forcing a main-thread repaint every frame. --}}
    @keyframes book-skel-shimmer {
        100% { transform: translateX(100%); }
    }

    .book-skel {
        position: relative;
        overflow: hidden;
        background: #eef0f2;
        border-radius: 8px;
        display: block;
    }
    .book-skel::after {
        content: '';
        position: absolute;
        inset: 0;
        transform: translateX(-100%);
        background: linear-gradient(90deg, transparent, #f7f8f9, transparent);
        animation: book-skel-shimmer 1.4s ease-in-out infinite;
    }

    .book-skeleton-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        margin-bottom: 16px;
    }

    .book-skeleton-pill {
        width: 84px;
        height: 18px;
        border-radius: 999px;
    }

    /* ── Mobile skeleton ── */
    .book-skeleton-mobile-header {
        height: 44px;
        border-radius: 14px;
        margin-bottom: 12px;
    }

    .book-skeleton-mobile-body {
        display: flex;
        gap: 10px;
    }

    .book-skeleton-dates-col {
        width: 75px;
        flex-shrink: 0;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .book-skeleton-slots-col {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .book-skeleton-row {
        height: 30px;
        border-radius: 8px;
    }

    /* ── Desktop skeleton ── */
    .book-skeleton-desktop {
        gap: 12px;
        align-items: stretch;
    }

    .book-skeleton-dates-col-lg {
        width: 92px;
        flex-shrink: 0;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .book-skeleton-col-header-lg {
        height: 24px;
        width: 60%;
        margin: 0 auto 8px;
    }

    .book-skeleton-room-col {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .book-skeleton-room-title {
        height: 22px;
        width: 40%;
        margin: 0 auto 8px;
    }

    .book-skeleton-row-lg {
        height: 40px;
        border-radius: 10px;
    }

    /* ── Theme lễ hội (ManageGeneral::holiday_theme_active) ──
       Ô "đang chọn" đổi từ đen sang đỏ + sao trắng giữa, thay cho nền đen mặc định (định nghĩa
       trong #book-sync-product-detail phía trên). Selector luôn thêm .holiday-theme (3 lớp thay
       vì 2) nên thắng các rule .selectable.active gốc bất kể thứ tự khai báo trong file. */
    .holiday-theme .selectable.active {
        background: #dc2626 !important;
        border-color: #dc2626 !important;
        opacity: 1 !important;
    }

    .holiday-theme .selectable.active:hover {
        background: #dc2626 !important;
        border-color: #dc2626 !important;
    }

    .holiday-theme .selectable.active::after {
        content: "\2605" !important;
        position: absolute;
        inset: 0;
        display: flex !important;
        align-items: center;
        justify-content: center;
        color: #ffd60a;
        text-shadow: 0 1px 2px rgba(0, 0, 0, .35);
        font-size: 14px;
        line-height: 1;
        background: none !important;
    }

    /* Ô vừa active vừa bị admin giữ chỗ (held) phải giữ nguyên cam+icon khoá, không bị đỏ+sao đè
       lên — 4 lớp (holiday-theme + selectable + active + held) nên thắng cả rule ở trên. */
    .holiday-theme .selectable.active.held {
        background: #f59e0b !important;
        border-color: #f59e0b !important;
    }

    .holiday-theme .selectable.active.held::after {
        content: "" !important;
        background-color: #f59e0b !important;
    }
</style>
