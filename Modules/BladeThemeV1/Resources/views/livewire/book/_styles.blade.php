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
        background-color: #111827;
        /* gray-900: tô đen */
        z-index: 5;
    }

    /* Icon khóa nhỏ ở giữa ô bị blocked */
    .selectable.blocked::before {
        content: "🔒";
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 10px;
        z-index: 10;
        pointer-events: none;
    }

    /* Override: khi blocked có kèm promo — reset ::before, dùng ::after để phủ tối + hiện khóa */
    .selectable.blocked.promo::before {
        content: "" !important;
        background: none !important;
        filter: none !important;
        animation: none !important;
        opacity: 0 !important;
    }

    .selectable.blocked.promo::after {
        content: "🔒" !important;
        position: absolute !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        background-color: #111827 !important;
        border-radius: inherit !important;
        z-index: 15 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-size: 10px !important;
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

    /* ── Selectable → pill shape ── */
    .selectable {
        border-radius: 999px !important;
        background: linear-gradient(135deg, #eef2ed, #e8f0e6) !important;
        border: 1.5px solid #a8c4a0 !important;
        color: #4e6b4c !important;
        transition: all 0.18s ease;
        height: 32px;
    }

    .selectable:hover:not([style*="pointer-events:none"]) {
        background: linear-gradient(135deg, #d4e4d2, #c8dcc6) !important;
        border-color: #6a8f68 !important;
        box-shadow: 0 4px 12px rgba(78, 107, 76, 0.3);
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

    /* ── Gradient CTA button ── */
    .book-cta-btn {
        padding: 14px 36px;
        border-radius: 999px;
        font-weight: 800;
        font-size: 1rem;
        color: white;
        background: linear-gradient(135deg, #4e6b4c, #6a8f68, #5a7d58);
        border: none;
        cursor: pointer;
        box-shadow: 0 8px 24px rgba(78, 107, 76, 0.35);
        transition: all 0.3s ease;
        letter-spacing: 0.04em;
        width: 100%;
        display: block;
    }

    .book-cta-btn:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: 0 12px 32px rgba(78, 107, 76, 0.45);
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

    /* ── Card bao ngoài — nền màu primary của trang, 2 cột bên trong mới là khối trắng ── */
    .book-card-outer {
        background: var(--color-primary, #4e6b4c);
        border-radius: 18px;
        padding: 10px 0 12px;
        box-shadow: 0 8px 28px rgba(0, 0, 0, 0.08);
    }

    /* ── Hàng trên cùng: chừa trống phía cột "Ngày", tên phòng + nút trượt chỉ nằm phía
         trên cột "Khung giờ" ── */
    .book-top-row {
        display: flex;
        gap: 12px;
        padding: 0 10px;
        margin-bottom: 12px;
    }

    .book-top-spacer {
        width: 60px;
        flex-shrink: 0;
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

    /* ── Grid header — nằm bên trong .book-mobile-scroll (dùng chung khung cuộn với phần thân
         nên độ rộng luôn khớp), dính ở trên cùng khi cuộn nhờ position:sticky. Nền trùng màu
         .book-card-outer để che phần nội dung cuộn qua bên dưới. ── */
    .book-grid-header {
        display: flex;
        gap: 12px;
        padding: 0 10px;
        background: var(--color-primary, #4e6b4c);
        position: sticky;
        top: 0;
        /* Cao hơn z-index của .selectable (10) và các lớp phủ khuyến mãi bên trong (tối đa 30)
           để hàng tiêu đề luôn nổi trên các nút chọn khung giờ khi cuộn ── */
        z-index: 50;
    }

    .book-grid-header .book-col-header {
        min-width: 60px;
        width: 60px;
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

    /* ── Mobile vertical scroll wrapper — vẫn cuộn được, chỉ ẩn thanh cuộn để không chiếm
         không gian ngang (tránh header/thân bị lệch độ rộng do thanh cuộn) ── */
    .book-mobile-scroll {
        max-height: 340px;
        overflow-y: auto;
        overflow-x: hidden;
        border-radius: 0 0 14px 14px;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    .book-mobile-scroll::-webkit-scrollbar {
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
        align-items: flex-start;
        min-height: 100%;
    }

    /* ── Left dates card — dính liền với header "Ngày" phía trên (cùng nền, chỉ bo góc dưới),
         tách hẳn khỏi khối khung giờ bên phải ── */
    .book-dates-card {
        background: #ffffff;
        border: 1px solid #f0f0f0;
        border-top: none;
        border-radius: 0 0 10px 10px;
        min-width: 60px;
        width: 60px;
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

    /* ── Slots card per room — dính liền với header khung giờ phía trên (chỉ bo góc dưới) ── */
    .book-slots-card {
        background: #ffffff;
        border: 1px solid #f0f0f0;
        border-top: none;
        border-radius: 0 0 14px 14px;
        overflow: hidden;
        max-width: 100%;
    }

    /* ── Khi chuyển phòng: chỉ khối khung giờ trượt hẳn từ mép vào như lật trang, cột Ngày
         đứng yên ── */
    @keyframes bookSlideInRight {
        from { transform: translateX(100%); opacity: 0.5; }
        to   { transform: translateX(0); opacity: 1; }
    }

    @keyframes bookSlideInLeft {
        from { transform: translateX(-100%); opacity: 0.5; }
        to   { transform: translateX(0); opacity: 1; }
    }

    .book-slide-in-right {
        animation: bookSlideInRight 1s cubic-bezier(0.22, 1, 0.36, 1);
    }

    .book-slide-in-left {
        animation: bookSlideInLeft 1s cubic-bezier(0.22, 1, 0.36, 1);
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

    /* ── Nút "Xem thêm 5 ngày" (mobile) ── */
    /* ── Hàng chứa 2 nút "Xem thêm ngày" / "Thu gọn" — chia 2 cột đều nhau, cách nhau gọn ── */
    .book-loadmore-row {
        display: flex;
        justify-content: center;
        gap: 10px;
        margin-top: 8px;
        padding: 0 10px;
    }

    .book-loadmore-btn-disabled {
        opacity: 0.45;
        cursor: not-allowed;
    }

    .book-loadmore-btn-disabled:hover {
        background: #f4f8f3;
        border-color: rgba(78, 107, 76, 0.4);
    }

    .book-loadmore-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        flex: 0 0 auto;
        min-width: 0;
        margin-top: 0;
        padding: 20px 30px;
        border-radius: 999px;
        border: 1.5px dashed rgba(78, 107, 76, 0.4);
        background: #f4f8f3;
        color: #4e6b4c;
        font-size: 0.75rem;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.15s, border-color 0.15s;
    }

    .book-loadmore-btn:hover {
        background: #e8f0e6;
        border-color: #6a8f68;
            </div>
    }

    .book-loadmore-btn span {
        font-weight: 500;
        color: #6b7280;
        font-size: 0.68rem;
    }

    .book-collapse-btn {
        border-style: solid;
        background: white;
        color: #6b7280;
        border-color: rgba(107, 114, 128, 0.35);
    }

    .book-collapse-btn:hover {
        background: white;
        border-color: rgba(107, 114, 128, 0.5);
    }

    /* ── Mobile sticky pricing bar ── */
    .book-mobile-price-bar {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 9999;
        background: white;
        border-top: 2px solid rgba(78, 107, 76, 0.2);
        box-shadow: 0 -4px 20px rgba(78, 107, 76, 0.15);
        padding: 8px 14px calc(8px + env(safe-area-inset-bottom, 0px));
    }

    @media (min-width: 768px) {
        .book-mobile-price-bar { display: none !important; }
    }

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

        .book-mobile-scroll {
            max-height: 460px;
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

        /* Nút "Xem thêm ngày" / "Thu gọn" to hơn trên desktop/tablet */
        .book-loadmore-btn {
            padding: 16px 28px;
            font-size: 1.125rem;
        }
    }

    /* ── Desktop (>=1024px): khu vực đặt phòng + bảng tính giá nằm chung 1 hàng, chia 2 cột,
         dùng hết chiều ngang màn hình. Dưới 1024px giữ nguyên xếp dọc (không set gì ở đây). ── */
    @media (min-width: 1024px) {
        .book-desktop-layout {
            display: grid;
            grid-template-columns: 1fr 420px;
            gap: 40px;
            align-items: stretch;
        }

        /* Cả 2 cột đều là grid item nên tự giãn theo align-items:stretch — thêm height:100%
           để nội dung bên trong (đặc biệt bảng giá) lấp đầy hết chiều cao đã giãn ra. */
        .book-desktop-layout .book-card-outer,
        .book-desktop-layout #book-pricing-summary {
            height: 100%;
            box-sizing: border-box;
            margin-top : 0px;
        }

        /* Bảng giá xếp dọc thay vì hàng ngang, vì giờ nó nằm trong cột phụ hẹp (sidebar).
           justify-content: space-between đẩy nút "Đặt phòng ngay" xuống sát đáy khi cột được
           giãn cao bằng cột bên trái, tránh khoảng trắng thừa ở giữa. */
        .book-desktop-layout #book-pricing-summary {
            flex-direction: column;
            align-items: stretch;
            justify-content: space-between;
        }
    }

    /* Alpine x-transition classes for slide-up bar */
    .bar-enter  { transition: transform 0.3s ease, opacity 0.3s ease; }
    .bar-enter-from { transform: translateY(100%); opacity: 0; }
    .bar-enter-to   { transform: translateY(0);    opacity: 1; }
    .bar-leave  { transition: transform 0.2s ease, opacity 0.2s ease; }
    .bar-leave-from { transform: translateY(0);    opacity: 1; }
    .bar-leave-to   { transform: translateY(100%); opacity: 0; }
</style>