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

    thead {
        position: sticky;
        top: 0;
        z-index: 30;
        background: #f9fafb;
    }

    .sticky-col-header {
        position: sticky;
        left: 0;
        z-index: 40;
        background: #f9fafb;
    }

    .sticky-col-thu {
        left: 0 !important;
        min-width: 60px;
    }

    .sticky-col-ngay {
        left: 60px !important;
        min-width: 80px;
    }

    .sticky-col {
        position: sticky;
        z-index: 20;
        background: #fff;
    }

    tbody .sticky-col-thu {
        left: 0 !important;
    }

    tbody .sticky-col-ngay {
        left: 60px !important;
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

    /* ── Override thead & sticky columns to green tint ── */
    thead {
        background: linear-gradient(135deg, #eef2ed, #dce8da) !important;
    }

    .sticky-col-header {
        background: linear-gradient(135deg, #eef2ed, #dce8da) !important;
    }

    .sticky-col {
        background: linear-gradient(135deg, #f8fbf7, #f4f8f3) !important;
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

    /* ── Room navigation header ── */
    .book-room-nav-wrap {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 16px;
        padding: 22px 16px 18px;
        background: #4e6b4c;
        /* fallback; overridden per-room via Alpine :style */
        border-radius: 20px 20px 0 0;
        transition: background 0.4s ease, color 0.3s ease;
    }

    .book-nav-btn {
        background: rgba(255, 255, 255, 0.25);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        border: 1.5px solid rgba(255, 255, 255, 0.5);
        color: inherit;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: background 0.2s, transform 0.15s;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    }

    .book-nav-btn:hover {
        background: rgba(255, 255, 255, 0.45);
        transform: scale(1.08);
    }

    .book-room-titles-wrap {
        flex: 1;
        text-align: center;
        min-height: 62px;
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
        font-size: 2rem;
        font-weight: 700;
        color: inherit;
        text-shadow: 0 2px 14px rgba(0, 0, 0, 0.25);
        line-height: 1.1;
        margin: 0;
    }

    .book-room-sub {
        color: inherit;
        opacity: 0.85;
        font-size: 0.8rem;
        font-weight: 600;
        margin-top: 5px;
        letter-spacing: 0.03em;
    }

    /* ── Fixed grid header (outside scroll) ── */
    .book-grid-header {
        display: flex;
        gap: 10px;
        padding: 0 14px;
        background: color-mix(in srgb, var(--room-color, #4e6b4c) 70%, black);
    }

    .book-grid-header .book-col-header {
        min-width: 82px;
        width: 82px;
        flex-shrink: 0;
        background: transparent;
        border-bottom: 1px solid rgba(128, 128, 128, 0.25);
    }

    .book-slots-headers-wrap {
        flex: 1;
        min-width: 0;
    }

    .book-slots-headers-wrap .book-slots-header-row {
        background: transparent;
        border-bottom: 1px solid rgba(255, 255, 255, 0.15);
    }

    /* ── Mobile vertical scroll wrapper ── */
    .book-mobile-scroll {
        max-height: 380px;
        overflow-y: auto;
        overflow-x: hidden;
        border-radius: 0 0 20px 20px;
    }

    .book-mobile-scroll::-webkit-scrollbar {
        width: 4px;
    }

    .book-mobile-scroll::-webkit-scrollbar-track {
        background: color-mix(in srgb, var(--room-color, #4e6b4c) 20%, transparent);
        border-radius: 4px;
    }

    .book-mobile-scroll::-webkit-scrollbar-thumb {
        background: var(--room-color, #4e6b4c);
        border-radius: 4px;
        opacity: 0.7;
    }

    /* ── Outer gradient wrapper (bottom) ── */
    .book-grid-outer {
        display: flex;
        gap: 10px;
        background: #4e6b4c;
        /* fallback; overridden per-room via Alpine :style */
        padding: 12px 14px 20px;
        border-radius: 0;
        align-items: flex-start;
        min-height: 100%;
        transition: background 0.4s ease;
    }

    /* ── Left dates card ── */
    .book-dates-card {
        background: var(--room-color, #4e6b4c);
        border-radius: 14px;
        min-width: 82px;
        width: 82px;
        flex-shrink: 0;
        box-shadow: 0 6px 24px rgba(0, 0, 0, 0.2);
        overflow: hidden;
    }

    .book-col-header {
        height: 46px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.7rem;
        font-weight: 700;
        color: var(--room-text-color, #ffffff);
        letter-spacing: 0.06em;
        text-transform: uppercase;
    }

    .book-date-row {
        height: 38px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        gap: 2px;
        padding: 0 4px;
    }

    .book-date-row:last-child {
        border-bottom: none;
    }

    .book-date-day {
        font-size: 0.62rem;
        color: var(--room-text-color, #ffffff);
        opacity: 0.7;
        font-weight: 500;
        line-height: 1;
    }

    .book-date-num {
        font-size: 0.78rem;
        font-weight: 700;
        color: var(--room-text-color, #ffffff);
        line-height: 1;
    }

    .book-date-row.is-today .book-date-day,
    .book-date-row.is-today .book-date-num {
        color: var(--room-text-color, #ffffff);
        font-weight: 800;
        opacity: 1;
    }

    /* ── Right slots outer (flex:1) ── */
    .book-slots-outer {
        flex: 1;
        min-width: 0;
    }

    /* ── Slots card per room ── */
    .book-slots-card {
        background: var(--room-color, #4e6b4c);
        border-radius: 14px;
        box-shadow: 0 6px 24px rgba(0, 0, 0, 0.2);
        overflow: hidden;
        max-width: 100%;
    }

    /* ── Slot column headers ── */
    .book-slots-header-row {
        display: flex;
        gap: 3px;
        padding: 0 4px;
        height: 46px;
        align-items: center;
    }

    .book-slot-th {
        flex: 1;
        text-align: center;
        font-size: 0.55rem;
        font-weight: 700;
        color: inherit;
        letter-spacing: -0.03em;
        line-height: 1.15;
        min-width: 0;
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
        padding: 4px 4px;
        height: 38px;
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
        padding: 8px 14px;
        background: linear-gradient(135deg, #3a5239 0%, #4e6b4c 100%);
    }

    .slot-pg-btn {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        height: 30px;
        padding: 0 12px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.18);
        border: 1.5px solid rgba(255, 255, 255, 0.45);
        color: white;
        font-size: 0.72rem;
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
        padding: 12px 16px calc(12px + env(safe-area-inset-bottom, 0px));
    }

    @media (min-width: 768px) {
        .book-mobile-price-bar { display: none !important; }
    }

    /* Alpine x-transition classes for slide-up bar */
    .bar-enter  { transition: transform 0.3s ease, opacity 0.3s ease; }
    .bar-enter-from { transform: translateY(100%); opacity: 0; }
    .bar-enter-to   { transform: translateY(0);    opacity: 1; }
    .bar-leave  { transition: transform 0.2s ease, opacity 0.2s ease; }
    .bar-leave-from { transform: translateY(0);    opacity: 1; }
    .bar-leave-to   { transform: translateY(100%); opacity: 0; }
</style>