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
        padding: 3px 18px;
        border-radius: 999px;
        background:
            linear-gradient(#fff, #fff) padding-box,
            linear-gradient(90deg, #ff0000, #ff7700, #ffff00, #00ff00, #0099ff, #6600ff, #ff00ff, #ff0000) border-box;
        background-size: auto, 200% auto;
        border: 2px solid transparent;
        animation: borderShimmer 3s linear infinite;
        font-size: 0.85rem;
        white-space: nowrap;
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
         không dùng chung nền trắng với khối "Khung giờ" bên dưới ── */
    .book-room-nav-wrap {
        flex: 1;
        min-width: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 10px 12px;
        background: #ffffff;
        color: #111827;
        border-radius: 14px;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
    }

    .book-nav-btn {
        background: #f9fafb;
        border: 1.5px solid #e5e7eb;
        color: inherit;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: background 0.2s, transform 0.15s;
    }

    .book-nav-btn svg {
        width: 16px;
        height: 16px;
    }

    .book-nav-btn:hover {
        background: #f3f4f6;
        transform: scale(1.08);
    }

    .book-room-titles-wrap {
        flex: 1;
        text-align: center;
        min-height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .book-room-title-block {
        text-align: center;
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

    /* ── Khung (viền/bo góc) của mỗi card đứng yên cố định — chỉ phần NỘI DUNG bên trong
         (.book-dates-scroll / .book-slots-scroll) cuộn dọc, đồng bộ 2 chiều bằng Alpine
         (@scroll) để cột Ngày và khung giờ luôn khớp hàng nhau khi cuộn. ── */
    .book-dates-scroll,
    .book-slots-scroll {
        height: 100%;
        overflow-y: auto;
        overflow-x: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
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
         để trông "chìm" nhẹ so với 2 khối trắng nổi bên cạnh. Chiều cao cố định (340px, xem
         thêm ở @media 768px) để khung không di chuyển theo khi cuộn nội dung bên trong. ── */
    .book-dates-card {
        height: 340px;
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
        color: #6b7280;
        opacity: 0.85;
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
        color: var(--color-primary, #0f766e);
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
         Chiều cao cố định khớp .book-dates-card để khung không di chuyển khi cuộn. ── */
    .book-slots-card {
        height: 340px;
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

    .book-overnight-tag {
        display: block;
        font-size: 0.48rem;
        font-weight: 600;
        background: rgba(0, 0, 0, 0.25);
        color: #fff;
        border-radius: 3px;
        padding: 1px 2px;
        margin-top: 1px;
        letter-spacing: 0;
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

    /* ── Bảng tính giá (Giá cơ bản, tổng tiền tạm tính): ẩn trên mobile (chỉ hiện trong
         bottom sheet), luôn hiện bên dưới bảng đặt phòng trên desktop. ── */
    .book-pricing-desktop {
        display: none;
    }

    @media (min-width: 1024px) {
        .book-pricing-desktop {
            display: block;
        }
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
        padding: 10px 14px 2px;
    }

    .book-sheet-handle {
        width: 40px;
        height: 4px;
        border-radius: 999px;
        background: #d1d5db;
    }

    .book-sheet-close {
        position: absolute;
        right: 14px;
        top: 8px;
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

        .book-dates-card,
        .book-slots-card {
            height: 460px;
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

        /* Chữ "Qua đêm" to hơn trên desktop/tablet */
        .book-overnight-tag {
            font-size: 1rem;
            padding: 2px 5px;
            margin-top: 3px;
        }

        /* Nút "Xem thêm ngày" to hơn trên desktop/tablet */
        .book-loadmore-btn {
            padding: 12px 28px;
            font-size: 0.95rem;
        }
    }

    /* ── Desktop (>=1024px): bảng tính giá nằm bên dưới bảng đặt phòng, dùng hết chiều
         ngang cột đặt phòng (không còn chia 2 cột trong nội bộ book component nữa — việc chia
         cột "danh sách phòng" / "bảng đặt lịch" giờ do trang chi nhánh đảm nhiệm). Bỏ
         max-width cũ (520px) để chiếm full chiều rộng cột thay vì co lại một khoảng hẹp. ── */
    @media (min-width: 1024px) {
        .book-pricing-desktop {
            margin-top: 24px;
            width: 100%;
        }
    }
</style>

{{-- ═══════════════════════════════════════════════════════════════════════════
     Đồng bộ giao diện lịch đặt phòng với trang chi tiết sản phẩm (product-detail):
     box bo góc vuông nhẹ (không còn pill tròn), nền/viền trung tính, "đã đặt" = màu
     primary (không icon), "đang chờ" (pending) = vàng (không icon), "đang chọn" = đen
     thật, "bị khóa" (blocked, admin khóa ngày) = xám + icon ổ khóa. Đặt ở cuối cùng
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

    .selectable.booked {
        background: var(--color-primary) !important;
        border-color: var(--color-primary) !important;
    }

    .selectable.pending {
        background: #CFDC74 !important;
        border-color: #CFDC74 !important;
        opacity: 1 !important;
    }

    .selectable.blocked {
        background: #e5e7eb !important;
        border-color: #d1d5db !important;
    }

    .selectable.past-time,
    .selectable.past-date {
        background: #f3f4f6 !important;
        border: 1px solid #e5e7eb !important;
    }

    .lock-icon {
        width: 14px;
        height: 14px;
        color: #9ca3af;
        position: relative;
        z-index: 15;
    }

    .book-slot-cell .lock-icon {
        width: 10px;
        height: 10px;
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
        border: 1px solid #e5e7eb !important;
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
        border: 1px solid #e5e7eb !important;
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

    /* Icon ổ khóa trên nền màu primary (ô "Đã đặt") cần màu trắng mới đủ tương phản,
       khác với nền xám nhạt của ô "blocked" (giữ màu xám #9ca3af mặc định). */
    .selectable.booked .lock-icon {
        color: #fff;
        opacity: .9;
    }

</style>