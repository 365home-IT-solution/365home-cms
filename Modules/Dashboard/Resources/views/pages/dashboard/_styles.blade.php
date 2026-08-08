<style>
    /* ================================================================
   DASHBOARD – off-white, minimal color, premium
   ================================================================ */
    /* Filament bọc mọi trang trong <section class="... gap-y-8 py-8"> và 1 div
       "grid ... gap-y-8" (xem vendor/filament/filament/resources/views/components/page/index.blade.php) —
       khoảng gap-y-8 này tạo khoảng trắng thừa phía trên khung .ta-wrap (giữa các
       livewire modal ẩn và card dashboard). Chỉ tắt trên trang Dashboard (:has(.ta-wrap)),
       không đụng tới các trang Filament khác. */
    .fi-page:has(.ta-wrap) [class~="gap-y-8"] {
        gap: 0;
    }

    .ta-wrap {
        --ta-bg: #FAFAFA;
        --ta-panel: #FFFFFF;
        --ta-line: #EAEAEA;
        --ta-line-soft: #F3F4F6;
        --ta-ink: #171717;
        --ta-ink-title: #0A0A0A;
        --ta-ink-mute: #737373;
        --ta-ink-faint: #A3A3A3;
        --ta-accent: #059669;
        /* Primary color THẬT của hệ thống (khớp .tsgrid-cell.selected trong
           timeslot-grid-table.blade.php/OrderForm) — dùng riêng cho view "Lịch" + popup thông
           tin đơn (carousel/ô đã đặt/nút lưu ghi chú), KHÔNG đổi --ta-accent chung (đang dùng ở
           rất nhiều chỗ khác của Dashboard, đổi sẽ ảnh hưởng ngoài phạm vi được yêu cầu). */
        --ta-cal-primary: #2B5257;
        --ta-green: #10B981;
        --ta-red: #EF4444;
        --ta-blue: #3B82F6;

        background: var(--ta-bg);
        color: var(--ta-ink);
        font-family: 'Manrope', 'Inter', ui-sans-serif, sans-serif;
        border-radius: 12px;
        padding: 28px 32px 36px;
    }

    .ta-inner {
        position: relative;
    }

    /* HEADER */
    .ta-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        margin-bottom: 22px;
        flex-wrap: wrap;
        gap: 12px;
    }

    .ta-sub-label {
        font-size: 10px;
        color: var(--ta-ink-mute);
        text-transform: uppercase;
        letter-spacing: 2px;
        margin-bottom: 6px;
        font-weight: 600;
    }

    .ta-title {
        font-size: 32px;
        line-height: 1.1;
        font-weight: 700;
        color: var(--ta-ink-title);
        letter-spacing: -0.5px;
    }

    .ta-title em {
        color: var(--ta-green);
        font-style: normal;
    }

    .ta-tabs {
        display: flex;
        gap: 2px;
        background: var(--ta-panel);
        padding: 3px;
        border-radius: 20px;
        border: 1px solid var(--ta-line);
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    }

    .ta-tab {
        padding: 6px 14px;
        border-radius: 16px;
        font-size: 10px;
        letter-spacing: 0.8px;
        color: var(--ta-ink-mute);
        cursor: pointer;
        border: none;
        background: transparent;
        text-transform: uppercase;
        font-weight: 600;
        transition: all 0.18s;
        font-family: 'Inter', ui-sans-serif, sans-serif;
    }

    .ta-tab.active {
        background: var(--ta-ink-title);
        color: #fff;
    }

    .ta-tab:not(.active):hover {
        background: var(--ta-line-soft);
        color: var(--ta-ink);
    }

    .ta-tab-custom {
        border-left: 1px solid var(--ta-line);
        margin-left: 3px;
        padding-left: 14px;
        border-radius: 16px;
    }

    .ta-tab-custom.active {
        background: var(--ta-ink-title);
    }

    /* Header right — tabs + branch toggle */
    .ta-header-right {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    /* Branch toggle button */
    .ta-branch-toggle {
        display: flex;
        align-items: center;
        gap: 5px;
        padding: 7px 14px;
        border-radius: 20px;
        border: 1px solid var(--ta-line);
        background: var(--ta-panel);
        color: var(--ta-ink-mute);
        font-size: 10px;
        font-weight: 600;
        letter-spacing: 0.8px;
        text-transform: uppercase;
        cursor: pointer;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        transition: all 0.18s;
        font-family: 'Inter', ui-sans-serif, sans-serif;
        white-space: nowrap;
    }

    .ta-branch-toggle:hover,
    .ta-branch-toggle.active {
        background: var(--ta-ink-title);
        color: #fff;
        border-color: var(--ta-ink-title);
    }

    .ta-branch-toggle.active .ta-branch-caret {
        transform: rotate(180deg);
    }

    .ta-branch-caret { transition: transform 0.18s; }

    /* Branch revenue panel */
    .ta-branch-panel {
        background: var(--ta-panel);
        border: 1px solid var(--ta-line);
        border-radius: 12px;
        margin-bottom: 20px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }

    .ta-branch-panel-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 18px;
        border-bottom: 1px solid var(--ta-line-soft);
        background: var(--ta-line-soft);
    }

    .ta-branch-panel-title {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.5px;
        color: var(--ta-ink);
        text-transform: uppercase;
    }

    .ta-branch-close {
        background: none;
        border: none;
        cursor: pointer;
        color: var(--ta-ink-faint);
        font-size: 14px;
        padding: 2px 6px;
        border-radius: 6px;
        transition: all 0.15s;
    }

    .ta-branch-close:hover { background: var(--ta-line); color: var(--ta-ink); }

    /* Branch filter list */
    .ta-branch-list-wrap {
        padding: 8px 12px;
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .ta-branch-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 9px 10px;
        border-radius: 8px;
        cursor: pointer;
        border: 1.5px solid transparent;
        transition: all 0.15s;
        gap: 12px;
    }

    .ta-branch-item:hover {
        background: var(--ta-line-soft);
        border-color: var(--ta-line);
    }

    .ta-branch-item.active {
        background: #f0fdf4;
        border-color: #10b981;
    }

    .ta-branch-item-left {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
    }

    .ta-branch-dot {
        width: 8px; height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .ta-branch-item-name {
        font-size: 13px;
        font-weight: 600;
        color: var(--ta-ink);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .ta-branch-item.active .ta-branch-item-name {
        color: #059669;
    }

    .ta-branch-item-stats {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
    }

    .ta-bfi-count {
        font-size: 11px;
        color: var(--ta-ink-mute);
        min-width: 40px;
        text-align: right;
    }

    .ta-bfi-rev {
        font-size: 12px;
        font-weight: 700;
        color: var(--ta-ink);
        min-width: 110px;
        text-align: right;
    }

    .ta-branch-panel-foot {
        padding: 8px 22px 12px;
        border-top: 1px solid var(--ta-line-soft);
    }

    #ta-branch-foot-hint {
        font-size: 10px;
        color: var(--ta-ink-faint);
        letter-spacing: 0.3px;
    }

    .ta-branch-loading {
        padding: 20px 0;
        text-align: center;
        color: var(--ta-ink-faint);
        font-size: 12px;
    }

    /* Custom date range picker */
    .ta-custom-range {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 18px;
        background: var(--ta-panel);
        border: 1px solid var(--ta-line);
        border-radius: 10px;
        padding: 10px 14px;
    }

    .ta-custom-label {
        font-size: 11px;
        font-weight: 600;
        color: var(--ta-ink-mute);
    }

    .ta-custom-sep {
        font-size: 13px;
        color: var(--ta-ink);
        font-weight: 700;
    }

    .ta-date-input {
        padding: 5px 10px;
        border-radius: 7px;
        border: 1px solid var(--ta-line);
        background: var(--ta-bg);
        font-size: 12px;
        font-weight: 600;
        color: var(--ta-ink-title);
        font-family: 'Inter', ui-sans-serif, sans-serif;
        cursor: pointer;
        outline: none;
        transition: border-color 0.15s;
    }

    .ta-date-input:focus {
        border-color: var(--ta-ink-title);
        box-shadow: 0 0 0 3px rgba(23, 23, 23, 0.08);
    }

    .ta-custom-note {
        flex: 1;
        min-width: 220px;
        font-size: 10px;
        color: var(--ta-ink-faint);
        border-left: 1px solid var(--ta-line);
        padding-left: 10px;
        line-height: 1.5;
    }

    .ta-custom-note em {
        font-style: normal;
        font-weight: 700;
    }

    /* KPI */
    .ta-kpi-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 10px;
        margin-bottom: 24px;
    }

    .ta-kpi {
        background: var(--ta-panel);
        padding: 20px 20px 16px;
        border-radius: 12px;
        position: relative;
        border: 1px solid var(--ta-line);
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
    }

    .ta-kpi-label {
        font-size: 10px;
        color: var(--ta-ink-mute);
        text-transform: uppercase;
        letter-spacing: 1.4px;
        margin-bottom: 10px;
        font-weight: 600;
    }

    .ta-kpi-value {
        font-size: 34px;
        font-weight: 700;
        line-height: 1;
        letter-spacing: -0.5px;
        color: var(--ta-ink-title);
    }

    .ta-kpi-value .unit {
        font-size: 13px;
        color: var(--ta-ink-mute);
        margin-left: 3px;
        font-weight: 400;
    }

    .ta-kpi-delta {
        margin-top: 10px;
        font-size: 11px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .ta-kpi-delta.up {
        color: var(--ta-green);
    }

    .ta-kpi-delta.down {
        color: var(--ta-red);
    }

    .ta-kpi-delta .dbar {
        flex: 1;
        height: 2px;
        background: var(--ta-line);
        border-radius: 2px;
        position: relative;
        overflow: hidden;
    }

    .ta-kpi-delta .dbar::after {
        content: "";
        position: absolute;
        left: 0;
        top: 0;
        height: 100%;
        background: currentColor;
        width: var(--w, 50%);
        border-radius: 2px;
    }

    .ta-kpi-hint {
        margin-top: 6px;
        font-size: 9px;
        color: var(--ta-ink-faint);
        letter-spacing: 0.2px;
        line-height: 1.3;
    }

    .ta-kpi-hint-range {
        font-family: 'Inter', ui-sans-serif, sans-serif;
        font-weight: 600;
    }

    /* ROOM CARDS */
    .ta-room-section {}

    .ta-room-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        margin-bottom: 14px;
        flex-wrap: wrap;
        gap: 10px;
    }

    .ta-panel-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--ta-ink-title);
        line-height: 1.2;
    }

    .ta-room-badge {
        background: #ECFDF5;
        border: 1px solid #A7F3D0;
        color: #065F46;
        font-size: 11px;
        font-weight: 700;
        padding: 5px 14px;
        border-radius: 999px;
    }

    .ta-room-pulse {
        font-size: 10px;
        color: var(--ta-ink-faint);
        display: flex;
        align-items: center;
        gap: 5px;
        font-family: 'Inter', ui-sans-serif, sans-serif;
    }

    .ta-room-pulse-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: var(--ta-green);
        animation: rcPulseDot 1s ease-in-out infinite;
    }

    @keyframes rcPulseDot {

        0%,
        100% {
            opacity: 1;
            transform: scale(1);
        }

        50% {
            opacity: 0.4;
            transform: scale(0.7);
        }
    }

    /* Time filter tabs */
    .ta-rc-time-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 8px;
    }

    .ta-rc-time-tab {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 8px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        border: 1px solid var(--ta-line);
        background: var(--ta-panel);
        color: var(--ta-ink-mute);
        transition: all 0.18s;
    }

    .ta-rc-time-tab:hover {
        border-color: #9CA3AF;
        color: var(--ta-ink);
    }

    .ta-rc-time-tab.active {
        background: var(--ta-ink-title);
        color: #fff;
        border-color: var(--ta-ink-title);
    }

    .ta-rc-time-tab.seg-active.active {
        background: var(--ta-green);
        border-color: var(--ta-green);
        color: #fff;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.25);
    }

    .ta-rc-time-tab.seg-today.active {
        background: var(--ta-blue);
        border-color: var(--ta-blue);
        color: #fff;
        box-shadow: 0 2px 8px rgba(59, 130, 246, 0.25);
    }

    .ta-rc-time-tab.seg-upcoming.active {
        background: #6B7280;
        border-color: #6B7280;
        color: #fff;
    }

    .ta-rc-time-tab.seg-overdue.active {
        background: #DC2626;
        border-color: #DC2626;
        color: #fff;
        box-shadow: 0 2px 8px rgba(220, 38, 38, 0.22);
    }

    .ta-rct-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .ta-rct-dot.active-dot {
        background: var(--ta-green);
    }

    .ta-rct-dot.today-dot {
        background: var(--ta-blue);
    }

    .ta-rct-dot.upcoming-dot {
        background: #9CA3AF;
    }

    .ta-rct-dot.overdue-dot {
        background: #DC2626;
    }

    /* Branch tabs */
    .ta-rc-tabs-wrap {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
        margin-bottom: 14px;
    }

    .ta-rc-tab {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 12px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
        cursor: pointer;
        border: 1px solid var(--ta-line);
        background: var(--ta-panel);
        color: var(--ta-ink-mute);
        transition: all 0.15s;
    }

    .ta-rc-tab.active {
        background: var(--ta-ink-title);
        color: #fff;
        border-color: var(--ta-ink-title);
    }

    .ta-rc-tab:not(.active):hover {
        background: var(--ta-line-soft);
        color: var(--ta-ink);
        border-color: var(--ta-line);
    }

    .ta-rc-tab-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 18px;
        height: 18px;
        padding: 0 5px;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 700;
        background: rgba(0, 0, 0, 0.07);
        color: inherit;
        line-height: 1;
    }

    .ta-rc-tab.active .ta-rc-tab-badge {
        background: rgba(255, 255, 255, 0.2);
        color: #fff;
    }

    .ta-rc-time-tab .ta-rc-tab-badge {
        background: rgba(0, 0, 0, 0.07);
        min-width: 16px;
        height: 16px;
        font-size: 10px;
    }

    .ta-rc-time-tab.active .ta-rc-tab-badge {
        background: rgba(255, 255, 255, 0.25);
        color: #fff;
    }

    .ta-rc-tab-badge.new {
        background: var(--ta-red);
        color: #fff;
        animation: rcBadgePulse 2s ease-in-out infinite;
    }

    @keyframes rcBadgePulse {

        0%,
        100% {
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4);
        }

        50% {
            box-shadow: 0 0 0 4px rgba(239, 68, 68, 0);
        }
    }

    /* Room grid */
    .ta-room-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(288px, 1fr));
        gap: 12px;
    }

    .ta-room-card {
        background: var(--ta-panel);
        border: 1px solid var(--ta-line);
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        transition: transform 0.2s, box-shadow 0.2s;
        cursor: pointer;
    }

    .ta-room-card.has-new {
        border-color: #FCA5A5;
    }

    .ta-room-card.has-active {
        border-color: #6EE7B7;
        box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.08), 0 4px 12px rgba(0, 0, 0, 0.04);
    }

    /* ===== Chế độ xem "Lịch" — carousel chi nhánh + lưới ngày×khung giờ nhiều phòng cạnh nhau,
       cùng ngôn ngữ thị giác với lịch đặt phòng phía khách (book/_desktop-grid.blade.php) nhưng
       chỉ xem, không chọn ô. Toàn bộ nội dung do JS dựng — xem rcCalInit()/rcCalRenderGrid()
       trong _scripts.blade.php. ===== */
    .ta-rc-cal-nav-btn {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        flex-shrink: 0;
        font-size: 12px;
        font-weight: 600;
        font-family: 'Inter', ui-sans-serif, sans-serif;
        color: var(--ta-ink-mute);
        background: var(--ta-panel);
        border: 1px solid var(--ta-line);
        border-radius: 999px;
        padding: 6px 12px;
        cursor: pointer;
        transition: all 0.15s;
    }

    .ta-rc-cal-nav-btn:hover:not(:disabled) {
        color: var(--ta-ink-title);
        border-color: var(--ta-cal-primary);
    }

    .ta-rc-cal-nav-btn:disabled {
        opacity: 0.35;
        cursor: not-allowed;
    }

    /* Trước/Sau chi nhánh — hàng RIÊNG phía TRÊN danh sách chi nhánh (không chung hàng với track),
       2 nút nằm SÁT NHAU, có chữ "Trước"/"Sau" — CÙNG kiểu với .ta-rc-cal-rooms-nav (carousel
       phòng) để nhất quán trong toàn view "Lịch". */
    .ta-rc-cal-branches-nav {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
    }

    .ta-rc-cal-branches {
        margin-bottom: 14px;
    }

    /* Danh sách chi nhánh — nút gọn, KHÔNG bo tròn hẳn (pill) như trước, cuộn ngang 1 hàng bằng
       Trước/Sau thay vì tự xuống hàng — không giới hạn số chi nhánh vì luôn cuộn được. */
    .ta-rc-cal-branch-track {
        display: flex;
        gap: 6px;
        overflow-x: auto;
        scroll-snap-type: x mandatory;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        padding-bottom: 2px;
    }

    .ta-rc-cal-branch-track::-webkit-scrollbar {
        display: none;
    }

    .ta-rc-cal-branch-card {
        flex: 0 0 auto;
        scroll-snap-align: start;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 8px;
        font-size: 12px;
        font-weight: 600;
        font-family: 'Inter', ui-sans-serif, sans-serif;
        color: var(--ta-ink-mute);
        background: var(--ta-panel);
        border: 1px solid var(--ta-line);
        cursor: pointer;
        transition: all 0.15s;
    }

    .ta-rc-cal-branch-card:hover {
        color: var(--ta-ink);
        border-color: var(--ta-cal-primary);
    }

    .ta-rc-cal-branch-card.active {
        background: var(--ta-ink-title);
        color: #fff;
        border-color: var(--ta-ink-title);
    }

    /* Chi nhánh CÓ đơn — viền màu primary để báo hiệu, không dùng hiệu ứng lan toả nữa.
       .has-orders.active gộp 2 class để thắng specificity của riêng .active (giữ viền primary
       kể cả khi đang được chọn). */
    .ta-rc-cal-branch-card.has-orders,
    .ta-rc-cal-branch-card.has-orders.active {
        border-color: var(--ta-cal-primary);
    }

    .ta-rc-cal-branch-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 16px;
        height: 16px;
        padding: 0 5px;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 700;
        background: rgba(0, 0, 0, 0.07);
        color: inherit;
        line-height: 1;
    }

    .ta-rc-cal-branch-card.active .ta-rc-cal-branch-badge {
        background: rgba(255, 255, 255, 0.2);
        color: #fff;
    }

    .ta-rc-cal-legend-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 12px;
    }

    .ta-rc-cal-rooms-nav {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* "N phòng" — hiện giữa 2 nút Trước/Sau của carousel phòng, nowrap để gọn 1 hàng. */
    .ta-rc-cal-rooms-page-info {
        font-size: 12px;
        font-weight: 600;
        color: var(--ta-ink-mute);
        font-family: 'Inter', ui-sans-serif, sans-serif;
        white-space: nowrap;
        text-align: center;
    }

    .ta-rc-cal-days-control {
        display: flex;
        align-items: center;
        gap: 8px;
        font-family: 'Inter', ui-sans-serif, sans-serif;
    }

    .ta-rc-cal-days-control label {
        font-size: 12px;
        font-weight: 600;
        color: var(--ta-ink-mute);
        white-space: nowrap;
    }

    .ta-rc-cal-days-input {
        -webkit-appearance: none;
        appearance: none;
        font-size: 12px;
        font-weight: 600;
        color: var(--ta-ink-title);
        background: var(--ta-panel) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23667085' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") no-repeat right 8px center / 12px 12px;
        border: 1px solid var(--ta-line);
        border-radius: 8px;
        padding: 5px 24px 5px 10px;
        cursor: pointer;
    }

    .ta-rc-cal-days-input:focus {
        outline: none;
        border-color: var(--ta-cal-primary);
    }

    .ta-rc-cal-legend {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .ta-rc-cal-legend-item {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        color: var(--ta-ink-mute);
        font-family: 'Inter', ui-sans-serif, sans-serif;
    }

    .ta-rc-cal-dot {
        display: inline-block;
        width: 10px;
        height: 10px;
        border-radius: 3px;
    }

    .ta-rc-cal-dot.booked {
        background: var(--ta-cal-primary);
    }

    .ta-rc-cal-dot.pending {
        background: #f59e0b;
    }

    .ta-rc-cal-dot.overdue {
        background: #DC2626;
    }

    .ta-rc-cal-dot.free {
        background: var(--ta-line-soft);
        border: 1px solid var(--ta-line);
    }

    /* Chú thích ô "Có giảm giá" — cùng hiệu ứng viền cầu vồng chạy với .ta-rc-cal-cell.is-discounted
       bên dưới, thu nhỏ lại cho vừa ô chấm 10x10. */
    .ta-rc-cal-dot.discounted {
        position: relative;
        z-index: 1;
        background: #fff;
        border: 1px solid #fff;
    }

    .ta-rc-cal-dot.discounted::before {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: inherit;
        background: linear-gradient(270deg, #ff0000, #ff9900, #33ff00, #00ffff, #3300ff, #ff00cc, #ff0000);
        background-size: 300% 300%;
        animation: ta-rc-cal-borderflow 10s linear infinite;
        z-index: -10;
        filter: blur(1px);
    }

    .ta-rc-cal-dot.discounted::after {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: inherit;
        background: #fff;
    }

    /* Icon chuông trong chú thích — giải thích badge chuông trắng nổi giữa ô đã đặt có ghi chú
       (xem .ta-rc-cal-cell-note-badge, rcCalRenderGrid()) — màu trung tính (không phải trắng)
       vì ở đây icon đứng trên nền panel sáng, không phải trên ô màu. */
    .ta-rc-cal-legend-note-icon {
        width: 13px;
        height: 13px;
        color: #b45309;
    }

    /* Khung ngoài — cột "Thời gian" (cố định trái) + carousel phòng cuộn NGANG cạnh nhau, cùng cuộn
       DỌC như 1 khối khi tăng số ngày hiển thị (5/10/15) thay vì đẩy cả trang cao dần — tham khảo
       .book-dt-dates-card/.book-dt-slots-scroll (book/_desktop-grid.blade.php, cuộn dọc trong
       khung chiều cao cố định). Nhãn "Thời gian" đứng yên (position:sticky) khi cuộn dọc — xem
       .ta-rc-cal-dates-spacer. */
    .ta-rc-cal-grid-wrap {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        max-height: 520px;
        overflow-y: auto;
        border: 1px solid var(--ta-line);
        border-radius: 12px;
        padding: 10px;
        background: var(--ta-panel);
        /* Ẩn thanh cuộn (vẫn cuộn được bằng lăn chuột/kéo/chạm) — ĐÚNG kiểu
           .book-dt-dates-scroll/.book-dt-slots-scroll bên book.blade.php. */
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    .ta-rc-cal-grid-wrap::-webkit-scrollbar {
        display: none;
        width: 0;
        height: 0;
    }

    .ta-rc-cal-empty {
        padding: 40px 16px;
        text-align: center;
        color: var(--ta-ink-mute);
        font-size: 13px;
    }

    /* Cột "Thời gian" — đứng cố định bên trái, KHÔNG trượt theo carousel phòng bên phải (khác
       carousel chi nhánh ở trên, không cuộn được). Spacer bù đúng chiều cao 3 khối tiêu đề (tên
       phòng 30px + lưới 2x2 nút thao tác 54px + hàng khung giờ 46px = 130px) bên phía mỗi phòng
       để hàng ngày khớp hàng khung giờ — cùng ý tưởng book-dt-room-name ẩn ở
       book/_desktop-grid.blade.php, nhưng dùng chiều cao cố định bằng CSS thay vì đo động vì số
       hàng tiêu đề ở đây luôn cố định. */
    .ta-rc-cal-dates-col {
        flex: 0 0 62px;
    }

    .ta-rc-cal-dates-spacer {
        height: 130px;
        display: flex;
        align-items: flex-end;
        padding-bottom: 6px;
        font-size: 10.5px;
        font-weight: 700;
        color: var(--ta-ink-mute);
        text-transform: uppercase;
        /* Đứng yên khi .ta-rc-cal-grid-wrap cuộn dọc — nhãn "Thời gian" luôn thấy được dù đang
           cuộn xem ngày xa hơn. */
        position: sticky;
        top: 0;
        z-index: 3;
        background: var(--ta-panel);
    }

    .ta-rc-cal-date-row {
        height: 46px;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        justify-content: center;
        gap: 1px;
        border-top: 1px solid var(--ta-line-soft);
        padding-right: 6px;
    }

    .ta-rc-cal-dow {
        font-size: 10px;
        font-weight: 700;
        color: var(--ta-ink-mute);
    }

    .ta-rc-cal-dnum {
        font-size: 10.5px;
        color: var(--ta-ink-faint);
        font-variant-numeric: tabular-nums;
    }

    .ta-rc-cal-date-row.is-today .ta-rc-cal-dow,
    .ta-rc-cal-date-row.is-today .ta-rc-cal-dnum {
        color: var(--ta-ink-title);
        font-weight: 800;
    }

    /* Carousel PHÒNG — vài phòng/lần (~3 trên desktop), trượt MƯỢT bằng rcCalScrollRooms()
       (scrollBy() smooth) trong _scripts.blade.php — trước đây tính toán lại "trang"/"cửa sổ
       trượt" bằng tay rồi vẽ lại TOÀN BỘ lưới mỗi lần chuyển, nặng và giật; giờ dựng lưới 1 LẦN
       DUY NHẤT, chỉ cuộn ngang thuần CSS — trình duyệt tự kẹp vị trí cuộn ở 2 đầu nên kéo tới cuối
       vẫn luôn thấy đủ phòng cuối cùng (không có "trang" lẻ loi thiếu phòng), CÙNG kỹ thuật với
       carousel chi nhánh ở trên. Tự dựng bằng scroll-snap thuần (KHÔNG dùng Swiper.js như bên
       khách, tránh thêm thư viện ngoài vào Filament admin). */
    .ta-rc-cal-rooms-track {
        flex: 1;
        min-width: 0;
        display: flex;
        gap: 16px;
        overflow-x: auto;
        scroll-snap-type: x mandatory;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
    }

    .ta-rc-cal-rooms-track::-webkit-scrollbar {
        display: none;
    }

    .ta-rc-cal-room-block {
        /* 3 phòng/lần theo mặc định (track gap 16px, 2 khoảng gap cho 3 khối) — bấm Trước/Sau
           (rcCalScrollRooms()) trượt sang bộ 3 phòng kế tiếp. */
        flex: 0 0 calc((100% - 32px) / 3);
        min-width: 260px;
        scroll-snap-align: start;
    }

    .ta-rc-cal-room-name {
        height: 24px;
        margin: 0 0 6px;
        font-size: 14px;
        font-weight: 800;
        font-style: italic;
        text-align: center;
        color: var(--ta-ink-title);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Hàng thao tác nhanh dưới tên phòng — Xem phòng/Giá phòng/Khoá phòng/Đặt phòng, icon-only
       (tooltip qua title) để vừa gọn trong 1 khối phòng hẹp (~260px, xem .ta-rc-cal-room-block). */
    /* 2 hàng (thay vì 1 hàng ngang) — nút dạng CHỮ cần nhiều chỗ hơn icon, ép 1 hàng sẽ vỡ trong
       khối phòng hẹp (~260px, xem .ta-rc-cal-room-block). Hàng 1: Xem phòng/Giá phòng/Thống kê (3
       cột). Hàng 2: Khoá phòng/Đặt phòng (2 cột). Chiều cao cố định 2 hàng để
       .ta-rc-cal-dates-spacer bù đúng (xem ghi chú ở đó) — vẫn 48px vì luôn đúng 2 hàng dù số cột
       mỗi hàng khác nhau. */
    .ta-rc-cal-room-actions {
        height: 48px;
        margin: 0 0 6px;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .ta-rc-cal-room-actions-row {
        display: grid;
        gap: 4px;
        flex: 1;
        min-height: 0;
    }

    .ta-rc-cal-room-actions-row.cols-3 {
        grid-template-columns: repeat(3, 1fr);
    }

    .ta-rc-cal-room-actions-row.cols-2 {
        grid-template-columns: repeat(2, 1fr);
    }

    .ta-rc-cal-room-action-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 22px;
        padding: 0 4px;
        border-radius: 6px;
        border: 1px solid var(--ta-line);
        background: var(--ta-panel);
        color: var(--ta-ink-mute);
        font-size: 10px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        transition: all 0.15s;
    }

    .ta-rc-cal-room-action-btn:hover {
        color: var(--ta-ink-title);
        border-color: var(--ta-cal-primary);
    }

    .ta-rc-cal-room-action-btn.is-primary {
        background: var(--ta-cal-primary);
        border-color: var(--ta-cal-primary);
        color: #fff;
    }

    .ta-rc-cal-room-action-btn.is-primary:hover {
        opacity: 0.88;
    }

    /* "Mở khoá (N)" — "Khoá phòng" tự đổi thành nút này khi có ≥1 ô đã khoá đang được chọn để mở
       hàng loạt (xem rcCalConfirmUnblockSlots()) — màu đỏ cảnh báo, khác hẳn "Khoá phòng"/"Đặt
       phòng" để admin không bấm nhầm. */
    .ta-rc-cal-room-action-btn.is-unlock {
        background: #fef2f2;
        border-color: #fecaca;
        color: #dc2626;
    }

    .ta-rc-cal-room-action-btn.is-unlock:hover {
        background: #dc2626;
        border-color: #dc2626;
        color: #fff;
    }

    /* "Khoá phòng"/"Đặt phòng" mặc định khoá (disabled) — chỉ bật khi đang chọn ít nhất 1 khung
       giờ trên lưới bên dưới (xem rcCalUpdateRoomActionState() trong _scripts.blade.php). */
    .ta-rc-cal-room-action-btn:disabled {
        opacity: 0.4;
        cursor: not-allowed;
        border-color: var(--ta-line);
        background: var(--ta-panel);
        color: var(--ta-ink-faint);
    }

    .ta-rc-cal-room-action-btn:disabled:hover {
        color: var(--ta-ink-faint);
        border-color: var(--ta-line);
    }

    .ta-rc-cal-slot-head-row {
        height: 46px;
        display: flex;
        gap: 6px;
    }

    .ta-rc-cal-slot-head {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        font-size: 10.5px;
        font-weight: 700;
        color: var(--ta-ink-mute);
        text-align: center;
        line-height: 1.3;
    }

    .ta-rc-cal-slots-row {
        height: 46px;
        display: flex;
        gap: 6px;
        border-top: 1px solid var(--ta-line-soft);
    }

    .ta-rc-cal-cell {
        position: relative;
        flex: 1;
        min-width: 0;
        align-self: center;
        height: 34px;
        border-radius: 9px;
        border: 1px solid var(--ta-line);
        box-shadow: inset 0 1px 3px 0 rgb(0 0 0 / .1);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        font-weight: 600;
        text-align: center;
        text-decoration: none;
        padding: 0 3px;
        overflow: visible;
    }

    /* Badge chuông (đơn có ghi chú — Order.description, xem RoomCardsService::buildTimeslotGrid()
       'has_note') — nằm GIỮA ô, màu trắng nổi trực tiếp trên nền màu ô (primary/vàng), không cần
       khoanh nền tròn riêng nữa. pointer-events:none để click vẫn rơi xuống <button> bên dưới (mở
       popup) chứ không bị chặn bởi icon. */
    .ta-rc-cal-cell-note-badge {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 15px;
        height: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        filter: drop-shadow(0 1px 2px rgba(0, 0, 0, .35));
        pointer-events: none;
    }

    .ta-rc-cal-cell-note-badge svg {
        width: 15px;
        height: 15px;
    }

    .ta-rc-cal-cell.is-free {
        /* Ô trống là <button> (bấm chọn — rcCalToggleSlot()), reset style mặc định của trình
           duyệt giống hệt .is-booked bên dưới. */
        background: var(--ta-panel);
        color: var(--ta-ink-faint);
        font-family: inherit;
        cursor: pointer;
        -webkit-appearance: none;
        appearance: none;
    }

    .ta-rc-cal-cell.is-free:hover:not(:disabled) {
        border-color: var(--ta-cal-primary);
    }

    /* Khung giờ ĐANG có khuyến mãi hiệu lực (RoomCardsService::buildTimeslotGrid() has_discount) —
       CÙNG kỹ thuật viền cầu vồng chạy với .tsgrid-cell.has-promo của OrderForm (xem
       Modules/Payment/Resources/views/components/timeslot-grid-table.blade.php): ::before là
       gradient cầu vồng full ô, filter:blur(1px) + z-index rất âm để viền mờ loang ra ngoài
       border-radius; ::after phủ lại trắng ĐẶC (không blur) ở giữa nên chỉ viền ngoài còn màu. */
    .ta-rc-cal-cell.is-free.is-discounted {
        position: relative;
        z-index: 1;
        background: #fff;
        border-color: #fff;
        box-shadow: inset 0 1px 3px 0 rgb(0 0 0 / .12);
    }

    .ta-rc-cal-cell.is-free.is-discounted::before {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: inherit;
        background: linear-gradient(270deg, #ff0000, #ff9900, #33ff00, #00ffff, #3300ff, #ff00cc, #ff0000);
        background-size: 300% 300%;
        animation: ta-rc-cal-borderflow 10s linear infinite;
        z-index: -10;
        filter: blur(1px);
    }

    .ta-rc-cal-cell.is-free.is-discounted::after {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: inherit;
        background: #fff;
    }

    /* Nâng chữ "Trống"/"Đã chọn" lên trên ::after (phủ trắng đặc của .is-discounted) — thiếu
       z-index riêng, ::after paint SAU chữ trong stacking context nên sẽ che mất chữ. */
    .ta-rc-cal-cell-label {
        position: relative;
        z-index: 1;
    }

    @keyframes ta-rc-cal-borderflow {
        0%   { background-position: 0% 50%; }
        100% { background-position: 300% 50%; }
    }

    /* Đang được CHÍNH admin này chọn (chưa Khoá/Đặt phòng) — tô ĐẶC màu primary, CÙNG kiểu với ô
       đã đặt (.is-booked) theo đúng yêu cầu, thay vì viền phân biệt như trước. */
    .ta-rc-cal-cell.is-selected {
        background: var(--ta-cal-primary);
        border-color: transparent;
        color: #fff;
    }

    /* Đã khoá dài hạn (settings['blocked_dates'] — BlockTimeslotModal/rcCalConfirmBlockSlots()) —
       kẻ sọc chéo, không bấm chọn được. */
    .ta-rc-cal-cell.is-blocked {
        /* Bấm lại để MỞ khoá (rcCalUnblockSlot()) — vẫn là <button>, reset appearance giống
           .is-free/.is-booked. */
        background: repeating-linear-gradient(135deg, var(--ta-line-soft) 0 4px, var(--ta-panel) 4px 8px);
        border-color: var(--ta-line);
        color: var(--ta-ink-faint);
        font-family: inherit;
        cursor: pointer;
        -webkit-appearance: none;
        appearance: none;
    }

    .ta-rc-cal-cell.is-blocked:hover:not(:disabled) {
        border-color: #dc2626;
        color: #dc2626;
    }

    .ta-rc-cal-cell.is-blocked svg {
        width: 13px;
        height: 13px;
    }

    /* Ô đã khoá ĐANG ĐƯỢC CHỌN để mở khoá hàng loạt (rcCalToggleBlockedSlot()) — đè màu đỏ cảnh
       báo lên trên nền sọc chéo, khác dấu ✓ với icon khoá bình thường. */
    .ta-rc-cal-cell.is-blocked.is-unblock-selected {
        background: #fef2f2;
        border-color: #dc2626;
        color: #dc2626;
    }

    /* Admin KHÁC đang chọn dở (TimeslotHold real-time) — viền đứt màu vàng, không bấm chọn được. */
    .ta-rc-cal-cell.is-held {
        background: var(--ta-panel);
        border: 1px dashed #f59e0b;
        color: #b45309;
        cursor: not-allowed;
    }

    /* KHÁCH đang chọn dở ở trang đặt phòng (TimeSlotHoldController, chưa xác nhận đặt) — viền
       đứt màu xanh dương, phân biệt trực quan với is-held (vàng, admin khác đang giữ). */
    .ta-rc-cal-cell.is-held-customer {
        background: var(--ta-panel);
        border: 1px dashed #3b82f6;
        color: #1d4ed8;
        cursor: not-allowed;
    }

    .ta-rc-cal-cell.is-booked {
        /* Ô đã đặt là <button> (bấm mở popup — rcCalOpenOrderPopup()), reset style mặc định
           của trình duyệt để trông giống hệt ô trống (<span>) bên cạnh, chỉ khác màu nền. Mặc
           định dùng --ta-cal-primary (#2B5257 — ĐÚNG primary color hệ thống, khớp
           .tsgrid-cell.selected của OrderForm) — riêng 'pending' (chờ xác nhận) đè vàng bằng
           .is-pending bên dưới (cần nổi bật để admin xử lý sớm), các trạng thái đã đặt khác
           (paid/deposit/...) gộp chung màu primary, không còn tô theo cell.color như trước —
           popup chi tiết (rcCalOpenOrderPopup()) mới là nơi phân biệt đủ mọi trạng thái. */
        background: var(--ta-cal-primary);
        color: #fff;
        border-color: transparent;
        font-family: inherit;
        cursor: pointer;
        -webkit-appearance: none;
        appearance: none;
    }

    .ta-rc-cal-cell.is-booked.is-pending {
        background: #f59e0b;
    }

    /* Quá giờ checkout (RoomCardsService::buildTimeslotGrid() 'is_overdue') — đè đỏ #DC2626, ĐÚNG
       màu "Quá hạn" đã dùng xuyên suốt Dashboard (.ta-rct-dot.overdue-dot, .seg-overdue...), kể cả
       khi đơn đang 'pending' (is-overdue viết SAU is-pending trong CSS nên thắng, quá giờ là tín
       hiệu cấp bách hơn trạng thái thanh toán). */
    .ta-rc-cal-cell.is-booked.is-overdue {
        background: #DC2626;
    }

    .ta-rc-head {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 11px 12px 10px;
        border-bottom: 1px solid var(--ta-line);
        background: var(--ta-bg);
    }

    .ta-rc-icon {
        width: 30px;
        height: 30px;
        border-radius: 8px;
        background: #F3F4F6;
        border: 1px solid #E5E7EB;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #6B7280;
        flex-shrink: 0;
    }

    .ta-rc-icon.active {
        background: #ECFDF5;
        border-color: #A7F3D0;
        color: var(--ta-accent);
        animation: rcActivePulse 2.5s ease-in-out infinite;
    }

    @keyframes rcActivePulse {

        0%,
        100% {
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.3);
        }

        50% {
            box-shadow: 0 0 0 5px rgba(16, 185, 129, 0);
        }
    }

    .ta-rc-info {
        flex: 1;
        min-width: 0;
    }

    .ta-rc-name {
        font-size: 13px;
        font-weight: 600;
        color: var(--ta-ink-title);
        display: flex;
        align-items: center;
        gap: 5px;
        min-width: 0;
    }

    .ta-rc-name-text {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        min-width: 0;
    }

    /* Icon nhỏ báo hiệu ngay trên thẻ phòng — đang chờ hoàn tiền — để nhìn lướt qua "Lịch phòng"
       là biết ngay phòng nào cần xử lý, không phải mở menu ⋮ mới thấy. */
    .ta-rc-flag {
        flex-shrink: 0;
        width: 18px;
        height: 18px;
        border-radius: 5px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: default;
    }

    .ta-rc-flag.refund {
        background: #FEE2E2;
        color: #B91C1C;
    }

    .ta-rc-count {
        font-size: 11px;
        font-weight: 700;
        background: #ECFDF5;
        color: #065F46;
        padding: 3px 10px;
        border-radius: 999px;
        border: 1px solid #A7F3D0;
        white-space: nowrap;
    }

    .ta-rc-count.empty {
        background: var(--ta-line-soft);
        color: var(--ta-ink-faint);
        border-color: var(--ta-line);
    }

    /* Menu thao tác nhanh (⋮) trên thẻ phòng */
    .ta-rc-menu-btn {
        flex-shrink: 0;
        width: 26px;
        height: 26px;
        border-radius: 7px;
        border: 1px solid var(--ta-line);
        background: var(--ta-bg);
        color: var(--ta-ink-mute);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background .15s, color .15s;
    }

    .ta-rc-menu-btn:hover {
        background: var(--ta-line-soft);
        color: var(--ta-ink-title);
    }

    /* CHÚ Ý: panel/catcher bên dưới bị JS (rcOpenRoomMenu, xem _scripts.blade.php) đưa ra làm
       con trực tiếp của <body> để position:fixed không bị "nhốt" bởi ancestor có transform —
       nghĩa là chúng nằm NGOÀI phạm vi .ta-wrap, nên KHÔNG được dùng var(--ta-xxx) (chỉ khai báo
       bên trong .ta-wrap, không kế thừa ra ngoài được) — dùng thẳng mã màu cố định. */
    .ta-rc-menu-catcher {
        position: fixed;
        inset: 0;
        z-index: 9998;
        background: transparent;
    }

    .ta-rc-menu-panel {
        position: fixed;
        z-index: 9999;
        min-width: 220px;
        max-width: 280px;
        background: #FFFFFF;
        border: 1px solid #EAEAEA;
        border-radius: 10px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, .12);
        padding: 6px;
        font-size: 12.5px;
        font-family: inherit;
    }

    .ta-rc-menu-panel a.ta-rc-menu-item,
    .ta-rc-menu-panel button.ta-rc-menu-item {
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
        text-align: left;
        padding: 8px 9px;
        border-radius: 7px;
        color: #0A0A0A;
        text-decoration: none;
        background: none;
        border: none;
        cursor: pointer;
        font-size: inherit;
        font-family: inherit;
    }

    .ta-rc-menu-panel a.ta-rc-menu-item:hover,
    .ta-rc-menu-panel button.ta-rc-menu-item:hover {
        background: #F3F4F6;
    }

    .ta-rc-menu-sep {
        height: 1px;
        background: #EAEAEA;
        margin: 5px 2px;
    }

    .ta-rc-menu-status-block {
        padding: 8px 9px;
    }

    .ta-rc-menu-status-label {
        font-size: 10.5px;
        text-transform: uppercase;
        letter-spacing: .3px;
        color: #A3A3A3;
        margin-bottom: 5px;
    }

    .ta-rc-menu-status-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        font-size: 12px;
        color: #0A0A0A;
        margin-bottom: 6px;
    }

    .ta-rc-menu-pill {
        font-size: 10.5px;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 999px;
        white-space: nowrap;
    }

    .ta-rc-menu-pill.ok {
        background: #ECFDF5;
        color: #065F46;
        border: 1px solid #A7F3D0;
    }

    .ta-rc-menu-pill.warn {
        background: #FEF3C7;
        color: #92400E;
        border: 1px solid #FDE68A;
    }

    .ta-rc-menu-confirm-btn {
        width: 100%;
        margin-top: 4px;
        padding: 6px 9px;
        border-radius: 7px;
        border: 1px solid #059669;
        background: #059669;
        color: #ffffff;
        font-weight: 700;
        font-size: 12px;
        cursor: pointer;
    }

    .ta-rc-menu-confirm-btn.secondary {
        background: #FFFFFF;
        color: #059669;
    }

    .ta-rc-orders {
        padding: 8px 10px 10px;
        display: flex;
        flex-direction: column;
        gap: 6px;
        max-height: 320px;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: var(--ta-line) transparent;
    }

    .ta-rc-orders::-webkit-scrollbar {
        width: 3px;
    }

    .ta-rc-orders::-webkit-scrollbar-thumb {
        background: var(--ta-line);
        border-radius: 3px;
    }

    .ta-rc-empty,
    .ta-rc-no-match {
        font-size: 11px;
        color: var(--ta-ink-faint);
        text-align: center;
        padding: 12px 0;
        font-style: italic;
    }

    /* Order items */
    .ta-rc-order-item {
        position: relative;
        background: var(--ta-panel);
        border: 1px solid var(--ta-line);
        border-left: 3px solid var(--ta-line);
        border-radius: 8px;
        padding: 8px 10px;
        transition: border-color 0.2s;
        cursor: pointer;
    }

    .ta-rc-order-item.is-new {
        background: #FFF5F5;
        border-color: #FECACA;
        border-left-color: #EF4444;
    }

    .ta-rc-new-badge {
        display: inline-flex;
        align-items: center;
        background: #EF4444;
        color: #fff;
        font-size: 9px;
        font-weight: 800;
        letter-spacing: 0.5px;
        padding: 1px 6px;
        border-radius: 4px;
        text-transform: uppercase;
        animation: rcNewBadgePulse 1.6s ease-in-out infinite;
        flex-shrink: 0;
    }

    @keyframes rcNewBadgePulse {

        0%,
        100% {
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.45);
        }

        50% {
            box-shadow: 0 0 0 4px rgba(239, 68, 68, 0);
        }
    }

    .ta-rc-order-item.seg-active {
        border-left-color: var(--ta-green);
    }

    .ta-rc-order-item.seg-today {
        border-left-color: var(--ta-line);
    }

    .ta-rc-order-item.seg-upcoming {
        border-left-color: var(--ta-line);
    }

    .ta-rc-order-item.seg-overdue {
        border-left-color: #DC2626;
        background: #FFF5F5;
    }

    .ta-rc-order-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 6px;
        margin-bottom: 5px;
    }

    .ta-rc-code {
        font-size: 11px;
        font-weight: 700;
        color: var(--ta-ink);
        font-family: 'Inter', ui-sans-serif, sans-serif;
    }

    /* Segment badges */
    .ta-seg-badge {
        font-size: 9px;
        font-weight: 700;
        padding: 1px 6px;
        border-radius: 4px;
        letter-spacing: 0.3px;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .ta-seg-badge.active {
        background: #ECFDF5;
        color: #065F46;
    }

    .ta-seg-badge.today {
        background: #EFF6FF;
        color: #1D4ED8;
    }

    .ta-seg-badge.upcoming {
        background: #F3F4F6;
        color: #6B7280;
    }

    .ta-seg-badge.overdue {
        background: #FEE2E2;
        color: #991B1B;
    }

    .ta-rc-status-pill {
        font-size: 10px;
        font-weight: 600;
        padding: 2px 8px;
        border-radius: 999px;
        border: 1px solid;
        white-space: nowrap;
    }

    .ta-rc-order-bot {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 6px;
        flex-wrap: wrap;
        margin-bottom: 4px;
    }

    .ta-rc-guest {
        font-size: 11px;
        color: var(--ta-ink-mute);
    }

    .ta-rc-time {
        font-size: 10px;
        color: var(--ta-ink-title);
        font-weight: 600;
        font-family: 'Inter', ui-sans-serif, sans-serif;
    }

    .ta-rc-order-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 6px;
    }

    .ta-rc-amount {
        font-size: 12px;
        font-weight: 700;
        color: var(--ta-ink-title);
    }

    .ta-rc-ago {
        font-size: 10px;
        color: var(--ta-ink-faint);
        font-style: italic;
    }

    .ta-rc-btn-detail {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        font-size: 10px;
        font-weight: 600;
        color: var(--ta-ink-mute);
        background: var(--ta-line-soft);
        border: 1px solid var(--ta-line);
        border-radius: 5px;
        padding: 2px 7px;
        text-decoration: none;
        transition: all 0.15s;
    }

    .ta-rc-btn-detail:hover {
        background: var(--ta-ink-title);
        color: #fff;
        border-color: var(--ta-ink-title);
    }

    /* ===== View toggle (Danh sách / Dải giờ) ===== */
    .ta-rc-view-toggle {
        display: inline-flex;
        background: var(--ta-line-soft);
        border: 1px solid var(--ta-line);
        border-radius: 8px;
        padding: 2px;
        gap: 2px;
    }

    .ta-rc-view-toggle-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 11.5px;
        font-weight: 600;
        font-family: 'Inter', ui-sans-serif, sans-serif;
        color: var(--ta-ink-mute);
        background: transparent;
        border: none;
        border-radius: 6px;
        padding: 5px 10px;
        cursor: pointer;
        transition: all 0.15s;
    }

    .ta-rc-view-toggle-btn:hover {
        color: var(--ta-ink);
    }

    .ta-rc-view-toggle-btn.active {
        background: var(--ta-panel);
        color: var(--ta-ink-title);
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
    }

    .ta-rc-view-toggle-btn:focus-visible {
        outline: 2px solid var(--ta-accent);
        outline-offset: 1px;
    }

    /* ===== View "Danh sách gọn" — 2 dòng, không icon phụ ===== */
    /* Giữ viền màu bên trái (theo trạng thái/segment, xem .ta-rc-order-item.seg-* bên trên) —
       chỉ tô nền trung tính, không lặp lại màu nền is-new/seg-overdue cho gọn.
       CHỈ áp dụng cho view Danh sách — view "Chi tiết (cũ)" vẫn giữ nguyên để so sánh. */
    .ta-rc-orders .ta-rc-order-item {
        padding: 7px 9px 6px;
        background: var(--ta-panel);
    }

    .ta-rc-orders .ta-rc-order-item.is-new,
    .ta-rc-orders .ta-rc-order-item.seg-overdue {
        background: var(--ta-panel);
        border-color: var(--ta-line);
    }

    .ta-rc-line1 {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        row-gap: 2px;
        column-gap: 6px;
        margin-bottom: 3px;
    }

    .ta-rc-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .ta-rc-guest-compact {
        font-size: 11.5px;
        font-weight: 600;
        color: var(--ta-ink);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 130px;
    }

    .ta-rc-phone-compact {
        font-size: 10px;
        color: var(--ta-ink-faint);
        white-space: nowrap;
        flex-shrink: 0;
    }

    .ta-rc-spacer {
        flex: 1 1 auto;
    }

    /* Trạng thái thanh toán — cho phép rớt xuống dòng riêng (flex-wrap ở .ta-rc-line1) thay vì
       tràn ra ngoài card khi tên khách + SĐT + trạng thái cộng lại dài hơn bề ngang thẻ. */
    .ta-rc-status-compact {
        font-size: 10px;
        font-weight: 700;
        white-space: nowrap;
        flex-shrink: 0;
        padding: 2px 7px;
        border-radius: 999px;
    }

    .ta-rc-line2 {
        display: flex;
        align-items: center;
        gap: 6px;
        padding-left: 20px;
        font-size: 10.5px;
        color: var(--ta-ink-mute);
        font-family: 'Inter', ui-sans-serif, sans-serif;
    }

    /* Đơn nhiều khung giờ (vd "08:30 - 11:20, 11:50 - 14:40, ...") có thể rất dài — PHẢI cho phép
       co lại và cắt bớt bằng "…" ngay trong thẻ, không được đẩy tràn ra ngoài card (đẩy luôn cả
       giá tiền/nút "Chi tiết" ra khỏi vùng nhìn thấy). min-width:0 bắt buộc phải có vì mặc định
       flex item không co được nhỏ hơn kích thước nội dung của chính nó (dù có flex-shrink). Xem
       đầy đủ TẤT CẢ khung giờ qua tooltip khi hover (đã có sẵn, không bị cắt).
       */
    .ta-rc-time-compact {
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        min-width: 0;
        flex: 1 1 auto;
    }

    .ta-rc-slot-compact {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .ta-rc-amount-compact {
        font-weight: 700;
        color: var(--ta-ink-title);
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .ta-rc-detail-compact {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 18px;
        height: 18px;
        color: var(--ta-ink-faint);
        border-radius: 5px;
        flex-shrink: 0;
        transition: all 0.15s;
    }

    .ta-rc-detail-compact:hover {
        background: var(--ta-ink-title);
        color: #fff;
    }

    /* ===== View "Chi tiết (cũ)" — giao diện đầy đủ trước khi tối giản, để so sánh ===== */
    .ta-rc-orders-detail {
        display: none;
    }

    #ta-room-grid.rc-view-detail .ta-rc-orders {
        display: none;
    }

    #ta-room-grid.rc-view-detail .ta-rc-orders-detail {
        display: block;
    }

    /* ===== Tooltip dùng chung (view Danh sách + Dải giờ) =====
       1 phần tử duy nhất, JS định vị bằng getBoundingClientRect() + position:fixed — không bị
       overflow:hidden của thẻ phòng cắt mất, và không xảy ra tình trạng chỉ hover được 1 đơn khi
       thẻ có nhiều đơn (do nhiều tooltip tuyệt đối trước đây chồng lên nhau). */
    .rc-tooltip {
        position: fixed;
        z-index: 9999;
        max-width: 260px;
        background: var(--ta-ink-title);
        color: var(--ta-panel);
        border-radius: 10px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.22), 0 2px 8px rgba(0, 0, 0, 0.12);
        padding: 10px 12px;
        font-size: 11.5px;
        line-height: 1.6;
        font-family: 'Inter', ui-sans-serif, sans-serif;
        opacity: 0;
        transform: translateY(4px) scale(0.98);
        pointer-events: none;
        transition: opacity 0.1s, transform 0.1s;
    }

    .rc-tooltip.show {
        opacity: 1;
        transform: translateY(0) scale(1);
    }

    .rc-tooltip-row {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .rc-tooltip-name {
        font-weight: 700;
        font-size: 12.5px;
        color: #fff;
    }

    .rc-tooltip-phone {
        opacity: 0.7;
        font-size: 10.5px;
    }

    .rc-tooltip-status {
        margin-left: auto;
        font-size: 9.5px;
        font-weight: 700;
        padding: 1px 7px;
        border-radius: 999px;
        white-space: nowrap;
    }

    .rc-tooltip-divider {
        height: 1px;
        background: rgba(255, 255, 255, 0.14);
        margin: 7px 0;
    }

    .rc-tooltip-line {
        display: flex;
        align-items: flex-start;
        gap: 6px;
        color: rgba(255, 255, 255, 0.9);
        margin-top: 3px;
    }

    .rc-tooltip-line svg {
        flex-shrink: 0;
        margin-top: 1px;
        opacity: 0.75;
    }

    .rc-tooltip-amount {
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        color: #fff;
    }

    .rc-tooltip-muted {
        opacity: 0.6;
        font-size: 10px;
        margin-top: 5px;
    }

    .ta-rc-slot-row {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
        margin-bottom: 4px;
    }

    .ta-rc-slot-badge {
        display: inline-flex;
        align-items: center;
        font-size: 10px;
        font-weight: 700;
        color: var(--ta-ink);
        background: var(--ta-line-soft);
        border: 1px solid var(--ta-line);
        border-radius: 5px;
        padding: 2px 7px;
        white-space: nowrap;
    }

    .ta-rc-slot-labels {
        font-size: 10px;
        color: var(--ta-ink-mute);
        font-family: 'Inter', ui-sans-serif, sans-serif;
    }

    @media (max-width: 1200px) {
        .ta-kpi-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    @media (max-width: 900px) {
        .ta-kpi-grid {
            grid-template-columns: repeat(3, 1fr);
        }

        .ta-title {
            font-size: 24px;
        }

        .ta-room-grid {
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        }

        .ta-rc-time-tabs,
        .ta-rc-tabs-wrap {
            gap: 4px;
        }

        .ta-rc-time-tab {
            padding: 5px 10px;
            font-size: 11px;
        }

        .ta-rc-tab {
            padding: 4px 9px;
            font-size: 10px;
        }
    }

    @media (max-width: 600px) {
        .ta-kpi-grid {
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
        }

        .ta-title {
            font-size: 20px;
        }
    }

    @media (max-width: 640px) {

        /* Layout */
        .ta-wrap {
            padding: 16px 14px 24px;
            border-radius: 0;
        }

        .ta-header {
            margin-bottom: 14px;
        }

        .ta-title {
            font-size: 18px;
        }

        /* Period tabs */
        .ta-tabs {
            flex-wrap: wrap;
            gap: 2px;
        }

        .ta-tab {
            font-size: 9px;
            padding: 5px 9px;
        }

        /* KPI */
        .ta-kpi-grid {
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            gap: 8px;
            margin-bottom: 16px;
        }

        .ta-kpi {
            padding: 12px 10px 10px;
        }

        .ta-kpi-value {
            font-size: clamp(12px, 3.8vw, 20px);
        }

        #ta-kpi-revenue,
        #ta-kpi-revenue-payos,
        #ta-kpi-revenue-cod {
            font-size: clamp(11px, 3.2vw, 16px) !important;
        }

        .ta-kpi-label {
            font-size: 9px;
            margin-bottom: 6px;
        }

        /* Room filter tabs — wrap on mobile */
        .ta-rc-time-tabs {
            flex-wrap: wrap;
        }

        .ta-rc-tabs-wrap {
            flex-wrap: wrap;
        }

        /* Room grid → horizontal snap carousel */
        .ta-room-grid {
            display: flex;
            flex-direction: row;
            overflow-x: auto;
            scroll-snap-type: x mandatory;
            -webkit-overflow-scrolling: touch;
            gap: 10px;
            padding-bottom: 12px;
            scrollbar-width: none;
            /* bleed to wrap edges so first/last card aligns with content */
            margin-left: -14px;
            margin-right: -14px;
            padding-left: 14px;
            padding-right: 14px;
        }

        .ta-room-grid::-webkit-scrollbar {
            display: none;
        }

        .ta-room-card {
            flex: 0 0 84vw;
            max-width: 300px;
            scroll-snap-align: start;
        }

        .ta-room-card:hover {
            transform: none;
        }

        /* Bottom charts */
        .ta-rr-chart-wrap {
            width: 160px;
            height: 160px;
        }

        .ta-mr-chart {
            height: 220px;
        }

        /* Custom date range — stack vertically */
        .ta-custom-range {
            flex-direction: column;
            align-items: flex-start;
            gap: 6px;
        }

        .ta-custom-note {
            border-left: none;
            padding-left: 0;
            border-top: 1px solid var(--ta-line);
            padding-top: 6px;
            min-width: 0;
        }
    }

    /* ================================================================
   BOTTOM ANALYTICS GRID (section 02 + 03)
   ================================================================ */
    .ta-bottom-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
        margin-top: 20px;
    }

    /* Card shell — matches .ta-kpi style */
    .ta-bg-card {
        background: var(--ta-panel);
        border: 1px solid var(--ta-line);
        border-radius: 12px;
        padding: 20px 20px 16px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
    }

    /* Full-width card spanning both columns */
    .ta-bg-card-full {
        grid-column: 1 / -1;
    }

    /* Branch month filter tabs */
    .ta-br-months {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 14px;
    }

    .ta-br-m-btn {
        padding: 4px 10px;
        border-radius: 6px;
        border: 1px solid var(--ta-line);
        background: transparent;
        font-size: 11px;
        color: #737373;
        cursor: pointer;
        transition: background .15s, color .15s, border-color .15s;
        line-height: 1.4;
    }

    .ta-br-m-btn:hover:not(:disabled) {
        background: #f4f4f5;
        border-color: #d4d4d4;
    }

    .ta-br-m-btn.active {
        background: #0f172a;
        color: #fff;
        border-color: #0f172a;
    }

    .ta-br-m-btn:disabled {
        opacity: 0.35;
        cursor: default;
    }

    .ta-bg-card-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 16px;
    }

    /* ── Room Revenue col ── */
    .ta-rr-body {
        display: grid;
        grid-template-columns: 1fr 200px;
        gap: 16px;
        align-items: start;
    }

    .ta-rr-th {
        display: grid;
        grid-template-columns: 1fr auto auto;
        gap: 0 8px;
        padding: 0 4px 7px;
        border-bottom: 1px solid var(--ta-line);
        font-size: 10.5px;
        color: var(--ta-ink-mute);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .5px;
    }

    .ta-rr-th-r {
        text-align: right;
        min-width: 48px;
    }

    .ta-rr-tr {
        display: grid;
        grid-template-columns: 1fr auto auto;
        gap: 0 8px;
        align-items: center;
        padding: 9px 4px;
        border-bottom: 1px solid var(--ta-line);
        cursor: pointer;
        transition: background .12s;
        border-radius: 4px;
    }

    .ta-rr-tr:last-child {
        border-bottom: none;
    }

    .ta-rr-tr:hover {
        background: var(--ta-line-soft);
    }

    .ta-rr-svc {
        display: flex;
        align-items: center;
        gap: 7px;
        min-width: 0;
    }

    .ta-rr-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .ta-rr-name {
        font-size: 12.5px;
        font-weight: 500;
        color: var(--ta-ink);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ta-rr-pct {
        font-size: 10px;
        color: var(--ta-ink-faint);
        font-weight: 500;
        flex-shrink: 0;
    }

    .ta-rr-num {
        text-align: right;
        font-size: 12.5px;
        color: var(--ta-ink-mute);
        font-variant-numeric: tabular-nums;
        min-width: 48px;
    }

    .ta-rr-bold {
        font-weight: 600;
        color: var(--ta-ink);
    }

    .ta-rr-empty {
        font-size: 12px;
        color: var(--ta-ink-faint);
        text-align: center;
        padding: 24px 0;
        font-style: italic;
    }

    /* Donut + summary */
    .ta-rr-right {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .ta-rr-chart-wrap {
        position: relative;
        width: 200px;
        height: 200px;
    }

    #ta-rr-donut {
        width: 100%;
        height: 100%;
    }

    .ta-rr-center {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        pointer-events: none;
        text-align: center;
        padding: 0 18px;
    }

    .ta-rr-center-label {
        font-size: 11px;
        font-weight: 600;
        color: var(--ta-ink);
        line-height: 1.3;
        overflow: hidden;
        text-overflow: ellipsis;
        width: 100%;
        text-align: center;
    }

    .ta-rr-center-val {
        font-size: 11.5px;
        color: var(--ta-ink-mute);
        margin-top: 3px;
    }

    .ta-rr-summary {
        background: var(--ta-line-soft);
        border: 1px solid var(--ta-line);
        border-radius: 8px;
        padding: 10px 12px;
    }

    .ta-rr-sum-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 3px 0;
        font-size: 11.5px;
    }

    .ta-rr-sum-row+.ta-rr-sum-row {
        border-top: 1px dashed var(--ta-line);
    }

    .ta-rr-sum-lbl {
        color: var(--ta-ink-mute);
        font-weight: 500;
    }

    .ta-rr-sum-val {
        color: var(--ta-ink);
        font-weight: 600;
        text-align: right;
    }

    .ta-rr-sum-best .ta-rr-sum-lbl {
        color: var(--ta-green);
    }

    .ta-rr-sum-worst .ta-rr-sum-lbl {
        color: var(--ta-red);
    }

    /* ── Monthly Revenue col ── */
    .ta-mr-chart {
        width: 100%;
        height: 270px;
    }

    .ta-br-chart {
        width: 100%;
        height: 300px;
    }

    .ta-mr-footer {
        text-align: right;
        font-size: 11px;
        color: var(--ta-ink-mute);
        margin-top: 6px;
        padding-top: 8px;
        border-top: 1px solid var(--ta-line);
    }

    .ta-mr-footer strong {
        color: var(--ta-ink-title);
    }

    /* ── Top Customers card ── */
    .ta-cust-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 10px 20px;
        margin-bottom: 10px;
    }

    .ta-cust-filter-group {
        display: flex;
        align-items: center;
        gap: 5px;
        flex-wrap: wrap;
    }

    .ta-cust-filter-lbl {
        font-size: 10.5px;
        color: var(--ta-ink-mute);
        font-weight: 500;
        white-space: nowrap;
    }

    .ta-cust-filter-btn {
        padding: 3px 9px;
        border-radius: 6px;
        border: 1px solid var(--ta-line);
        background: transparent;
        font-size: 11px;
        color: var(--ta-ink-mute);
        cursor: pointer;
        transition: background .12s, color .12s, border-color .12s;
        line-height: 1.5;
    }

    .ta-cust-filter-btn:hover {
        border-color: #4ade80;
        color: #16a34a;
    }

    .ta-cust-filter-btn.active {
        background: #dcfce7;
        border-color: #4ade80;
        color: #15803d;
        font-weight: 600;
    }

    @media (prefers-color-scheme: dark) {
        .ta-cust-filter-btn.active {
            background: #14532d;
            color: #86efac;
            border-color: #22c55e;
        }
    }

    .ta-cust-th,
    .ta-cust-tr {
        grid-template-columns: minmax(0, 1fr) 36px 96px 28px !important;
    }

    .ta-cust-svc {
        display: flex;
        flex-direction: column;
        gap: 4px;
        min-width: 0;
    }

    .ta-cust-svc-top {
        display: flex;
        align-items: center;
        gap: 7px;
    }

    .ta-cust-rank-ico {
        display: inline-flex;
        align-items: center;
        flex-shrink: 0;
        width: 18px;
    }

    .ta-cust-rank-num {
        font-size: 10.5px;
        font-weight: 600;
        color: var(--ta-ink-faint);
        width: 18px;
        text-align: center;
        flex-shrink: 0;
    }

    .ta-cust-name {
        font-size: 10.5px;
        color: var(--ta-ink-faint);
        font-style: normal;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Coupon button */
    .ta-cust-coupon-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        border-radius: 6px;
        border: 1px solid var(--ta-line);
        color: var(--ta-ink-mute);
        background: transparent;
        transition: background .12s, color .12s, border-color .12s;
        flex-shrink: 0;
    }

    .ta-cust-coupon-btn:hover {
        background: #dcfce7;
        color: #15803d;
        border-color: #4ade80;
    }

    /* Skeleton loading */
    .ta-cust-skel-row {
        display: grid;
        grid-template-columns: 1fr 36px 96px 28px;
        gap: 10px;
        align-items: center;
        padding: 10px 4px;
        border-bottom: 1px solid var(--ta-line);
    }

    .ta-cust-skel-row:last-child {
        border-bottom: none;
    }

    .ta-skel {
        background: var(--ta-line);
        border-radius: 4px;
        height: 10px;
        animation: ta-skel-pulse 1.4s ease-in-out infinite;
    }

    .ta-skel-lg {
        width: 80%;
    }

    .ta-skel-sm {
        width: 70px;
    }

    .ta-skel-xs {
        width: 40px;
    }

    @keyframes ta-skel-pulse {

        0%,
        100% {
            opacity: 1;
        }

        50% {
            opacity: .4;
        }
    }

    .ta-cust-scroll {
        max-height: 440px;
        overflow-y: auto;
        overflow-x: visible;
        scrollbar-width: thin;
        scrollbar-color: var(--ta-line) transparent;
    }

    .ta-cust-scroll::-webkit-scrollbar {
        width: 4px;
    }

    .ta-cust-scroll::-webkit-scrollbar-track {
        background: transparent;
    }

    .ta-cust-scroll::-webkit-scrollbar-thumb {
        background: var(--ta-line);
        border-radius: 99px;
    }

    .ta-cust-prog {
        height: 3px;
        background: var(--ta-line);
        border-radius: 99px;
        overflow: hidden;
        margin-left: 25px;
    }

    .ta-cust-prog-bar {
        height: 3px;
        border-radius: 99px;
        background: linear-gradient(90deg, #4ade80, #16a34a);
        transition: width .4s ease;
    }

    /* Responsive */
    @media (max-width: 960px) {
        .ta-bottom-grid {
            grid-template-columns: 1fr;
        }

        .ta-rr-body {
            grid-template-columns: 1fr;
        }

        .ta-rr-chart-wrap {
            width: 180px;
            height: 180px;
        }

        .ta-mr-chart {
            height: 240px;
        }

    }

    @media (max-width: 640px) {

        .ta-cust-th,
        .ta-cust-tr {
            grid-template-columns: minmax(0, 1fr) 30px 86px 26px !important;
        }

        .ta-cust-toolbar {
            gap: 6px 12px;
        }

        .ta-cust-filter-btn {
            padding: 2px 7px;
            font-size: 10.5px;
        }
    }

    @media (max-width: 420px) {

        /* Rất nhỏ: ẩn doanh thu */
        .ta-cust-th,
        .ta-cust-tr {
            grid-template-columns: minmax(0, 1fr) 30px 26px !important;
        }

        .ta-cust-th>*:nth-child(3),
        .ta-cust-tr>*:nth-child(3) {
            display: none;
        }
    }

    /* Custom year picker */
    .ta-yr-picker {
        position: relative;
        display: inline-flex;
        user-select: none;
    }

    .ta-yr-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 10px;
        background: var(--ta-panel);
        border: 1.5px solid var(--ta-line);
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        color: var(--ta-ink);
        cursor: pointer;
        outline: none;
        white-space: nowrap;
        transition: border-color .15s, box-shadow .15s, background .15s;
    }

    .ta-yr-btn:hover {
        border-color: var(--ta-green);
    }

    .ta-yr-picker.open .ta-yr-btn {
        border-color: var(--ta-green);
        background: rgba(16, 185, 129, .05);
        box-shadow: 0 0 0 3px rgba(16, 185, 129, .12);
    }

    .ta-yr-ico {
        color: var(--ta-green);
        flex-shrink: 0;
    }

    .ta-yr-chev {
        color: var(--ta-ink-faint);
        flex-shrink: 0;
        transition: transform .2s;
    }

    .ta-yr-picker.open .ta-yr-chev {
        transform: rotate(180deg);
    }

    /* Dropdown panel */
    .ta-yr-drop {
        position: absolute;
        top: calc(100% + 6px);
        right: 0;
        min-width: 86px;
        background: var(--ta-panel);
        border: 1px solid var(--ta-line);
        border-radius: 10px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, .10), 0 2px 6px rgba(0, 0, 0, .06);
        padding: 4px;
        z-index: 200;
        opacity: 0;
        transform: translateY(-6px) scale(.97);
        pointer-events: none;
        transition: opacity .15s, transform .15s;
    }

    .ta-yr-picker.open .ta-yr-drop {
        opacity: 1;
        transform: none;
        pointer-events: auto;
    }

    /* Option */
    .ta-yr-opt {
        display: block;
        padding: 7px 14px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        color: var(--ta-ink);
        cursor: pointer;
        text-align: center;
        transition: background .1s, color .1s;
    }

    .ta-yr-opt:hover {
        background: var(--ta-line-soft);
    }

    .ta-yr-opt.active {
        background: var(--ta-green);
        color: #fff;
        font-weight: 700;
    }

    .ta-yr-opt.active:hover {
        background: #059669;
    }

    /* ── Flatpickr calendar overrides ── */
    .flatpickr-calendar {
        font-family: 'Inter', ui-sans-serif, sans-serif;
        border-radius: 10px;
        border: 1px solid #EAEAEA;
        box-shadow: 0 8px 24px rgba(0, 0, 0, .10), 0 2px 6px rgba(0, 0, 0, .06);
    }

    .flatpickr-months .flatpickr-month {
        color: #171717;
        height: 38px;
    }

    .flatpickr-current-month {
        font-size: 13px;
        font-weight: 700;
        padding-top: 8px;
    }

    .flatpickr-current-month .flatpickr-monthDropdown-months {
        font-weight: 700;
    }

    .flatpickr-weekdays {
        background: #FAFAFA;
        border-bottom: 1px solid #EAEAEA;
    }

    span.flatpickr-weekday {
        font-size: 10px;
        font-weight: 700;
        color: #737373;
        text-transform: uppercase;
    }

    .flatpickr-day {
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        color: #171717;
    }

    .flatpickr-day:hover {
        background: #F3F4F6;
        border-color: transparent;
    }

    .flatpickr-day.today {
        border-color: #059669;
        color: #059669;
        font-weight: 700;
    }

    .flatpickr-day.today:hover {
        background: #ECFDF5;
        border-color: #059669;
    }

    .flatpickr-day.selected,
    .flatpickr-day.selected:hover {
        background: #171717;
        border-color: #171717;
        color: #fff;
    }

    .flatpickr-day.flatpickr-disabled,
    .flatpickr-day.flatpickr-disabled:hover {
        color: #D1D5DB;
    }

    .flatpickr-innerContainer {
        padding: 4px 6px 8px;
    }

    /* ── Order checkbox ───────────────────────────────────────── */
    .ta-rc-check-wrap {
        display: inline-flex;
        align-items: center;
        cursor: pointer;
        flex-shrink: 0;
    }

    .ta-rc-check-wrap input[type="checkbox"] {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
        pointer-events: none;
    }

    .ta-rc-check-box {
        width: 14px;
        height: 14px;
        border-radius: 3px;
        border: 1.5px solid #D1D5DB;
        background: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.15s;
        flex-shrink: 0;
    }

    .ta-rc-check-wrap input:checked+.ta-rc-check-box {
        background: var(--ta-accent);
        border-color: var(--ta-accent);
    }

    .ta-rc-check-wrap input:checked+.ta-rc-check-box::after {
        content: '';
        width: 8px;
        height: 5px;
        border-left: 1.5px solid #fff;
        border-bottom: 1.5px solid #fff;
        transform: rotate(-45deg) translate(1px, -1px);
        display: block;
    }

    .ta-rc-check-wrap:hover .ta-rc-check-box {
        border-color: var(--ta-accent);
    }

    .ta-rc-order-item.rc-sel {
        background: #F0FDF4;
        border-color: #A7F3D0;
        border-left-color: var(--ta-accent);
    }

    /* ── Segment quick-view button (Đang ở + Hôm nay combined) ── */
    .ta-rc-seg-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 13px;
        border: none;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.15s;
        font-family: inherit;
        color: #fff;
    }

    .ta-rc-seg-btn.seg-now {
        background: linear-gradient(120deg, var(--ta-green) 0%, var(--ta-blue) 100%);
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.22);
    }

    .ta-rc-seg-btn.seg-now:hover {
        background: linear-gradient(120deg, #059669 0%, #2563EB 100%);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.32);
    }

    /* ── Xem đơn button ───────────────────────────────────────── */
    .ta-rc-view-sel-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 14px;
        background: var(--ta-accent);
        color: #fff;
        border: none;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.15s;
        font-family: inherit;
        box-shadow: 0 2px 6px rgba(5, 150, 105, 0.25);
    }

    .ta-rc-view-sel-btn:hover {
        background: #047857;
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(5, 150, 105, 0.35);
    }

    /* ── Selected orders popup ────────────────────────────────── */
    .ta-rc-popup-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.45);
        z-index: 9999;
        overflow-y: auto;
        padding: 32px 20px 48px;
        backdrop-filter: blur(2px);
        animation: rcPopFadeIn 0.18s ease;
    }

    @keyframes rcPopFadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    .ta-rc-popup-modal {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        width: 100%;
        max-width: min(1100px, calc(100vw - 40px));
        margin: 0 auto;
        display: flex;
        flex-direction: column;
        animation: rcPopSlideUp 0.2s ease;
    }

    @keyframes rcPopSlideUp {
        from {
            transform: translateY(20px);
            opacity: 0;
        }

        to {
            transform: none;
            opacity: 1;
        }
    }

    .ta-rc-popup-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 20px;
        border-bottom: 1px solid var(--ta-line);
        flex-shrink: 0;
        position: sticky;
        top: 0;
        background: #fff;
        z-index: 1;
        border-radius: 16px 16px 0 0;
    }

    .ta-rc-popup-title {
        font-size: 15px;
        font-weight: 700;
        color: var(--ta-ink-title);
    }

    .ta-rc-popup-sub {
        font-size: 11px;
        color: var(--ta-ink-mute);
        margin-top: 2px;
    }

    .ta-rc-popup-clear {
        font-size: 11px;
        font-weight: 600;
        color: var(--ta-ink-mute);
        background: var(--ta-line-soft);
        border: 1px solid var(--ta-line);
        border-radius: 999px;
        padding: 4px 10px;
        cursor: pointer;
        transition: all 0.15s;
        font-family: inherit;
    }

    .ta-rc-popup-clear:hover {
        background: var(--ta-line);
        color: var(--ta-ink);
    }

    .ta-rc-popup-close-btn {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: var(--ta-line-soft);
        border: 1px solid var(--ta-line);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: var(--ta-ink-mute);
        transition: all 0.15s;
    }

    .ta-rc-popup-close-btn:hover {
        background: #FEE2E2;
        border-color: #FECACA;
        color: #EF4444;
    }

    .ta-rc-popup-body {
        padding: 12px 16px 16px;
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 10px;
        align-items: stretch;
    }

    .ta-rc-popup-body .ta-room-card {
        display: flex;
        flex-direction: column;
    }

    .ta-rc-popup-body .ta-pop-room-orders {
        flex: 1;
    }

    /* Đồng bộ màu chủ đạo với view "Lịch" (--ta-cal-primary #2B5257) CHỈ trong phạm vi popup này
       — icon nhà/badge "N đơn"/nút "+ Ghi chú"+"Lưu" ở đây trước nay dùng chung --ta-accent (xanh
       lá) với view "Danh sách", giờ tách riêng để không đụng --ta-accent đang dùng ở nhiều nơi
       khác (KPI, checkbox chọn đơn...). CHỈ đổi màu "trung tính/thương hiệu" — giữ nguyên màu
       trạng thái có ý nghĩa riêng (.ta-seg-badge.active/today/overdue...) vì đó là mã màu phân
       biệt trạng thái, không phải màu trang trí. */
    .ta-rc-popup-body .ta-rc-icon.active {
        background: rgba(43, 82, 87, .1);
        border-color: rgba(43, 82, 87, .35);
        color: var(--ta-cal-primary);
        animation: rcActivePulseTeal 2.5s ease-in-out infinite;
    }

    @keyframes rcActivePulseTeal {
        0%, 100% { box-shadow: 0 0 0 0 rgba(43, 82, 87, .3); }
        50% { box-shadow: 0 0 0 5px rgba(43, 82, 87, 0); }
    }

    .ta-rc-popup-body .ta-rc-count:not(.empty) {
        background: rgba(43, 82, 87, .1);
        color: var(--ta-cal-primary);
        border-color: rgba(43, 82, 87, .35);
    }

    .ta-rc-popup-body .ta-pop-deposit-btn {
        color: var(--ta-cal-primary);
    }

    .ta-rc-popup-body .ta-pop-deposit-save {
        background: var(--ta-cal-primary);
    }

    .ta-rc-popup-body .ta-pop-deposit-save:hover:not(:disabled) {
        opacity: .88;
        background: var(--ta-cal-primary);
    }

    /* ===== Popup thông tin nhanh 1 đơn (bấm ô đã đặt ở view "Lịch") — tái dùng
       .ta-rc-popup-overlay/.ta-rc-popup-close-btn (đã định nghĩa ở trên) cho phần khung/nút đóng
       chung, chỉ định nghĩa riêng modal (nhỏ gọn, không phải grid nhiều thẻ) + nội dung. ===== */
    .ta-rc-order-popup-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.45);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        backdrop-filter: blur(2px);
        animation: rcPopFadeIn 0.18s ease;
    }

    .ta-rc-order-popup-modal {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        width: 100%;
        max-width: 760px;
        max-height: calc(100vh - 40px);
        overflow-y: auto;
        animation: rcPopSlideUp 0.2s ease;
    }

    /* 2 cột: trái = thông tin khách + ghi chú (sửa được) + nút điều hướng, phải = khối "Tổng
       thanh toán" (payment::components.total-amount-card render sẵn). Dồn về 1 cột khi modal hẹp
       hơn 2 cột thật sự cần (mobile, hoặc màn hình thấp co lại) — .amount-wrap tự cuộn dọc riêng
       nếu nội dung "Xem chi tiết từng khung giờ" dài hơn cột trái. */
    .ta-rc-order-popup-columns {
        display: grid;
        grid-template-columns: 230px 1fr;
        gap: 20px;
        align-items: start;
    }

    @media (max-width: 620px) {
        .ta-rc-order-popup-columns {
            grid-template-columns: 1fr;
        }
    }

    .ta-rc-order-popup-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 14px 16px;
        border-bottom: 1px solid var(--ta-line);
        position: sticky;
        top: 0;
        background: #fff;
        z-index: 1;
        border-radius: 16px 16px 0 0;
    }

    /* Tên popup + badge trạng thái/ghi chú cùng 1 hàng (title trước, badge nối ngay sau, cuối
       hàng tiêu đề) — nút đóng (X) luôn đứng riêng, đẩy hẳn về cuối nhờ justify-content:
       space-between của .ta-rc-order-popup-head. */
    .ta-rc-order-popup-head-left {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        min-width: 0;
    }

    .ta-rc-order-popup-title {
        font-size: 14px;
        font-weight: 700;
        color: var(--ta-ink-title);
        white-space: nowrap;
    }

    .ta-rc-order-popup-head-badges {
        display: inline-flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 6px;
    }

    .ta-rc-order-popup-body {
        padding: 14px 16px 16px;
    }

    .ta-rc-order-popup-loading,
    .ta-rc-order-popup-error {
        padding: 30px 10px;
        text-align: center;
        color: var(--ta-ink-mute);
        font-size: 12.5px;
    }

    .ta-rc-order-popup-status {
        display: inline-flex;
        align-items: center;
        font-size: 11px;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 999px;
        white-space: nowrap;
    }

    /* Badge "Có ghi chú" cạnh trạng thái đơn — cùng ý nghĩa với badge chuông trên ô khung giờ
       (rcCalRenderGrid()), tự ẩn/hiện theo cờ has_note khi lưu ghi chú (rcCalSaveOrderNote()). */
    .ta-rc-order-popup-note-flag {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 11px;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 999px;
        white-space: nowrap;
        background: #fef3c7;
        color: #b45309;
    }

    .ta-rc-order-popup-note-flag svg {
        width: 12px;
        height: 12px;
    }

    .ta-rc-order-popup-field {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        padding: 7px 0;
        border-bottom: 1px solid var(--ta-line-soft);
        font-size: 12.5px;
    }

    .ta-rc-order-popup-field:last-of-type {
        border-bottom: none;
    }

    .ta-rc-order-popup-field-label {
        flex: 0 0 84px;
        color: var(--ta-ink-mute);
    }

    .ta-rc-order-popup-field-value {
        flex: 1;
        min-width: 0;
        color: var(--ta-ink-title);
        font-weight: 600;
        word-break: break-word;
    }

    /* Khối "Tổng thanh toán" (cột phải) — render bằng component thật
       payment::components.total-amount-card (server-side, xem routes/web.php
       orders/{id}/quick-info) nên tự mang đủ style inline riêng (var(--boulder-XX, #hex), luôn
       có fallback hex), không cần định nghĩa lại màu/layout bên trong. */
    .ta-rc-order-popup-amount-wrap {
        min-width: 0;
    }

    /* Mã cổng — bản GỌN riêng cho popup (window._rcBuildAccessCodeHtml() trong _scripts.blade.php),
       KHÔNG tái dùng access-code-info.blade.php/manual-lock-info.blade.php nguyên bản (thiết kế
       cho khung rộng của orderform, vỡ layout khi nhồi vào cột trái hẹp ~230px ở đây). Đặt đầu
       cột trái, trước cả "Khách hàng". */
    .ta-rc-order-popup-access {
        background: var(--ta-bg);
        border: 1px solid var(--ta-line);
        border-radius: 10px;
        padding: 9px 11px;
        margin-bottom: 10px;
    }

    .ta-rc-order-popup-access-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 6px;
    }

    /* Trạng thái mã cổng — chỉ 1 chấm tròn màu (không còn badge chữ), hover đọc nhãn đầy đủ qua
       title. */
    .ta-rc-order-popup-access-dot {
        flex: 0 0 auto;
        width: 9px;
        height: 9px;
        border-radius: 50%;
    }

    .ta-rc-order-popup-access-code-row {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 3px 0;
    }

    .ta-rc-order-popup-access-code-tag {
        flex: 0 0 auto;
        font-size: 10px;
        font-weight: 600;
        color: var(--ta-ink-mute);
        background: var(--ta-line-soft);
        border-radius: 5px;
        padding: 1px 6px;
    }

    .ta-rc-order-popup-access-code {
        flex: 1;
        min-width: 0;
        font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
        font-size: 18px;
        font-weight: 800;
        letter-spacing: 0.12em;
        color: var(--ta-cal-primary);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .ta-rc-order-popup-access-copy-btn {
        flex: 0 0 auto;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        border-radius: 6px;
        border: none;
        background: var(--ta-ink-title);
        color: #fff;
        cursor: pointer;
        transition: opacity 0.15s;
    }

    .ta-rc-order-popup-access-copy-btn:hover {
        opacity: 0.85;
    }

    .ta-rc-order-popup-access-meta {
        font-size: 10.5px;
        color: var(--ta-ink-mute);
        margin-top: 2px;
    }

    .ta-rc-order-popup-access-empty {
        font-size: 12px;
        color: var(--ta-ink-faint);
        font-style: italic;
    }

    /* Ghi chú đơn — sửa được tại chỗ (rcCalToggleNoteEdit()/rcCalSaveOrderNote()), không cần mở
       orderform. Lưu xong tự cập nhật badge chuông trên ô khung giờ (xem rcCalRenderGrid()). */
    .ta-rc-order-popup-note-block {
        padding: 7px 0;
        border-bottom: 1px solid var(--ta-line-soft);
        font-size: 12.5px;
    }

    .ta-rc-order-popup-note-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 4px;
    }

    .ta-rc-order-popup-note-edit-btn {
        font-size: 11px;
        font-weight: 600;
        color: var(--ta-ink-mute);
        background: var(--ta-line-soft);
        border: 1px solid var(--ta-line);
        border-radius: 999px;
        padding: 2px 9px;
        cursor: pointer;
        font-family: inherit;
        transition: all 0.15s;
    }

    .ta-rc-order-popup-note-edit-btn:hover {
        background: var(--ta-line);
        color: var(--ta-ink);
    }

    .ta-rc-order-popup-note-display {
        color: var(--ta-ink-title);
        font-weight: 600;
        word-break: break-word;
        white-space: pre-wrap;
    }

    .ta-rc-order-popup-note-input {
        width: 100%;
        resize: vertical;
        min-height: 60px;
        border: 1px solid var(--ta-line);
        border-radius: 8px;
        padding: 7px 9px;
        font-size: 12.5px;
        font-family: inherit;
        color: var(--ta-ink-title);
    }

    .ta-rc-order-popup-note-input:focus {
        outline: none;
        border-color: var(--ta-cal-primary);
    }

    .ta-rc-order-popup-note-actions {
        display: flex;
        justify-content: flex-end;
        gap: 6px;
        margin-top: 6px;
    }

    .ta-rc-order-popup-note-cancel-btn,
    .ta-rc-order-popup-note-save-btn {
        font-size: 11.5px;
        font-weight: 700;
        border-radius: 8px;
        padding: 5px 12px;
        cursor: pointer;
        font-family: inherit;
        border: 1px solid var(--ta-line);
        transition: opacity 0.15s;
    }

    .ta-rc-order-popup-note-cancel-btn {
        background: #fff;
        color: var(--ta-ink-mute);
    }

    .ta-rc-order-popup-note-save-btn {
        background: var(--ta-cal-primary);
        border-color: var(--ta-cal-primary);
        color: #fff;
    }

    .ta-rc-order-popup-note-save-btn:hover {
        opacity: 0.88;
    }

    .ta-rc-order-popup-note-save-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .ta-rc-order-popup-goto-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        width: 100%;
        margin-top: 14px;
        padding: 10px;
        border-radius: 10px;
        border: none;
        background: var(--ta-ink-title);
        color: #fff;
        font-size: 13px;
        font-weight: 700;
        font-family: inherit;
        text-decoration: none;
        cursor: pointer;
        transition: opacity 0.15s;
    }

    .ta-rc-order-popup-goto-btn:hover {
        opacity: 0.88;
    }

    /* Popup "Giá phòng" (rcCalOpenPricePopup() trong _scripts.blade.php) — 2 cột: trái = điều
       kiện giá (nhãn dài, KHÔNG được xuống dòng), phải = khung giờ/giá/khuyến mãi. */
    .ta-rc-price-columns {
        display: grid;
        grid-template-columns: 190px 1fr;
        gap: 20px;
        align-items: start;
    }

    @media (max-width: 560px) {
        .ta-rc-price-columns {
            grid-template-columns: 1fr;
        }
    }

    .ta-rc-price-field {
        padding: 8px 0;
        border-bottom: 1px solid var(--ta-line-soft);
    }

    .ta-rc-price-field:last-child {
        border-bottom: none;
    }

    /* Nhãn LUÔN 1 hàng, không wrap — đây chính là lý do tách riêng khỏi
       .ta-rc-order-popup-field-label (dùng flex:0 0 84px cố định, nhãn dài kiểu "Giảm giá full
       phòng"/"Giảm theo khung" sẽ vỡ dòng nếu dùng chung class đó). */
    .ta-rc-price-field-label {
        display: block;
        font-size: 11px;
        color: var(--ta-ink-mute);
        white-space: nowrap;
        margin-bottom: 3px;
    }

    .ta-rc-price-field-value {
        display: block;
        font-size: 13px;
        font-weight: 700;
        color: var(--ta-ink-title);
        line-height: 1.4;
    }

    /* "Giảm theo khung" — sửa được: mỗi mức 1 hàng (số khung + % + nút xoá riêng), "+ Thêm mức"
       (rcCalAddBulkRow()) thêm hàng trống. */
    .ta-rc-price-bulk-editor {
        display: flex;
        flex-direction: column;
        gap: 5px;
        margin-bottom: 6px;
    }

    .ta-rc-price-bulk-row {
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .ta-rc-price-bulk-slots,
    .ta-rc-price-bulk-discount {
        width: 52px;
        flex: 0 0 auto;
        border: 1px solid var(--ta-line);
        border-radius: 6px;
        padding: 4px 6px;
        font-size: 11.5px;
        font-weight: 700;
        font-family: inherit;
        color: var(--ta-ink-title);
    }

    .ta-rc-price-bulk-slots:focus,
    .ta-rc-price-bulk-discount:focus {
        outline: none;
        border-color: var(--ta-cal-primary);
    }

    .ta-rc-price-bulk-arrow,
    .ta-rc-price-bulk-pct {
        font-size: 11px;
        color: var(--ta-ink-mute);
        white-space: nowrap;
    }

    .ta-rc-price-bulk-remove-btn {
        flex: 0 0 auto;
        width: 20px;
        height: 20px;
        border: none;
        border-radius: 5px;
        background: var(--ta-line-soft);
        color: var(--ta-ink-mute);
        cursor: pointer;
        font-size: 13px;
        line-height: 1;
    }

    .ta-rc-price-bulk-remove-btn:hover {
        background: #fee2e2;
        color: #dc2626;
    }

    .ta-rc-price-bulk-add-btn {
        font-size: 11px;
        font-weight: 600;
        color: var(--ta-ink-mute);
        background: var(--ta-line-soft);
        border: 1px solid var(--ta-line);
        border-radius: 999px;
        padding: 3px 10px;
        cursor: pointer;
        font-family: inherit;
    }

    .ta-rc-price-bulk-add-btn:hover {
        background: var(--ta-line);
        color: var(--ta-ink);
    }

    .ta-rc-price-slots-head {
        font-size: 11px;
        font-weight: 700;
        color: var(--ta-ink-mute);
        text-transform: uppercase;
        letter-spacing: 0.03em;
        margin: 0 0 6px;
    }

    .ta-rc-price-slots {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .ta-rc-price-slot-row {
        background: var(--ta-bg);
        border: 1px solid var(--ta-line);
        border-radius: 8px;
        padding: 7px 10px;
    }

    .ta-rc-price-slot-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    .ta-rc-price-slot-label {
        font-size: 12.5px;
        font-weight: 700;
        color: var(--ta-ink-title);
    }

    /* Khuyến mãi gắn theo khung giờ — tag gắn sẵn (bấm × gỡ) + <select> thêm khuyến mãi có sẵn
       (rcCalAddPromoTag()/rcCalSavePricing()). */
    .ta-rc-price-promo-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        margin-top: 6px;
    }

    .ta-rc-price-promo-tags:empty {
        margin-top: 0;
    }

    .ta-rc-price-promo-tag {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 10.5px;
        font-weight: 600;
        color: #b45309;
        background: #fef3c7;
        border-radius: 999px;
        padding: 2px 4px 2px 8px;
    }

    .ta-rc-price-promo-tag.is-inactive {
        color: var(--ta-ink-faint);
        background: var(--ta-line-soft);
        text-decoration: line-through;
    }

    .ta-rc-price-promo-tag button {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 14px;
        height: 14px;
        border: none;
        border-radius: 50%;
        background: rgba(0, 0, 0, 0.12);
        color: inherit;
        cursor: pointer;
        font-size: 11px;
        line-height: 1;
        text-decoration: none;
        padding: 0;
    }

    .ta-rc-price-promo-select {
        /* -webkit-appearance/appearance PHẢI reset về none — thiếu bước này 1 số trình duyệt
           (Chrome Android/webview) vẽ chồng mũi tên gốc của <select> lên custom border/padding,
           lặp lại thành nhiều mũi tên dọc theo bề ngang ô (đúng lỗi đã gặp). Tự vẽ lại ĐÚNG 1 mũi
           tên bằng background-image thay cho mũi tên gốc đã tắt. */
        -webkit-appearance: none;
        appearance: none;
        width: 100%;
        margin-top: 5px;
        border: 1px dashed var(--ta-line);
        border-radius: 6px;
        padding: 4px 22px 4px 6px;
        font-size: 11px;
        font-family: inherit;
        color: var(--ta-ink-mute);
        background: #fff url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") no-repeat right 6px center / 12px 12px;
    }

    .ta-rc-price-promo-select:focus {
        outline: none;
        border-color: var(--ta-cal-primary);
    }

    /* Input sửa giá tại chỗ (rcCalSavePricing()) — dùng cho cả 3 field cột trái lẫn giá từng
       khung giờ cột phải. */
    .ta-rc-price-input {
        width: 100%;
        min-width: 0;
        border: 1px solid var(--ta-line);
        border-radius: 7px;
        padding: 5px 8px;
        font-size: 12.5px;
        font-weight: 700;
        font-family: inherit;
        color: var(--ta-ink-title);
        background: #fff;
    }

    .ta-rc-price-input:focus {
        outline: none;
        border-color: var(--ta-cal-primary);
    }

    /* number input + đơn vị (khách/đ) đứng cạnh nhau trên 1 hàng. */
    .ta-rc-price-input-row {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .ta-rc-price-input-row .ta-rc-price-input {
        width: 84px;
        flex: 0 0 auto;
    }

    .ta-rc-price-input-suffix {
        font-size: 11px;
        color: var(--ta-ink-mute);
        white-space: nowrap;
    }

    .ta-rc-price-slot-top .ta-rc-price-input-row {
        flex: 0 0 auto;
    }

    .ta-rc-price-slot-top .ta-rc-price-input {
        width: 92px;
    }

    /* Chân popup: link "Mở SettingBook" (giảm theo khung/khuyến mãi — không sửa trực tiếp được ở
       đây) bên trái, trạng thái lưu + nút "Lưu thay đổi" bên phải. */
    .ta-rc-price-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 14px;
        padding-top: 12px;
        border-top: 1px solid var(--ta-line-soft);
    }

    .ta-rc-price-settingbook-link {
        font-size: 11.5px;
        color: var(--ta-ink-mute);
        text-decoration: underline;
    }

    .ta-rc-price-settingbook-link:hover {
        color: var(--ta-ink-title);
    }

    .ta-rc-price-save-row {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .ta-rc-price-save-status {
        font-size: 11.5px;
        font-weight: 600;
        color: var(--ta-accent);
    }

    .ta-rc-price-save-status.is-error {
        color: #dc2626;
    }

    .ta-rc-price-footer .ta-rc-order-popup-goto-btn {
        width: auto;
        margin-top: 0;
        padding: 8px 16px;
    }

    /* ===== Popup "Xem phòng" — 2 thẻ thống kê (doanh thu/đơn thành công) + biểu đồ cột thuần CSS
       so sánh doanh thu 6 tháng gần nhất, xem rcCalOpenViewPopup() trong _scripts.blade.php. ===== */
    .ta-rc-view-stats {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 16px;
    }

    .ta-rc-view-stat-card {
        display: flex;
        flex-direction: column;
        gap: 4px;
        padding: 12px 14px;
        border: 1px solid var(--ta-line);
        border-radius: 10px;
        background: var(--ta-line-soft);
    }

    .ta-rc-view-stat-label {
        font-size: 11px;
        font-weight: 600;
        color: var(--ta-ink-mute);
    }

    .ta-rc-view-stat-value {
        font-size: 17px;
        font-weight: 800;
        color: var(--ta-ink-title);
    }

    .ta-rc-view-chart-title {
        font-size: 12px;
        font-weight: 700;
        color: var(--ta-ink-title);
        margin-bottom: 10px;
    }

    /* Hàng tiêu đề "Doanh thu theo tháng" + ta-yr-picker (năm) — chỉ biểu đồ cột tháng mới có bộ
       lọc năm, biểu đồ tần suất khung giờ (Nightingale) không cần vì group theo TOÀN BỘ lịch sử. */
    .ta-rc-view-chart-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 10px;
    }

    .ta-rc-view-chart-head .ta-rc-view-chart-title {
        margin-bottom: 0;
    }

    /* Container biểu đồ (ECharts, rcCalInitViewMonthlyChart() trong _scripts.blade.php) — ECharts
       cần kích thước CỐ ĐỊNH đo được ngay lúc init(), không tự co theo nội dung. */
    .ta-rc-view-chart-el {
        width: 100%;
        height: 280px;
    }

    /* Room card — dark header + row-based order list */
    .ta-pop-room-card {
        background: var(--ta-panel);
        border: 1px solid #E5E7EB;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.06);
    }

    .ta-pop-room-head {
        background: #0F172A;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    .ta-pop-room-head-left {
        display: flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
    }

    .ta-pop-room-name {
        color: #fff;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 0.3px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .ta-pop-room-cnt {
        color: rgba(255, 255, 255, 0.45);
        font-size: 10px;
        font-weight: 500;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .ta-pop-room-orders {
        display: flex;
        flex-direction: column;
    }

    .ta-pop-order-row {
        position: relative;
        padding: 9px 14px 9px 17px;
        display: flex;
        flex-direction: column;
        gap: 4px;
        border-top: 1px solid #F3F4F6;
    }

    .ta-pop-order-row::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 3px;
    }

    .ta-pop-order-row.seg-active::before {
        background: #10B981;
    }

    .ta-pop-order-row.seg-today::before {
        background: #3B82F6;
    }

    .ta-pop-order-row.seg-overdue::before {
        background: #EF4444;
    }

    .ta-pop-order-row.seg-upcoming::before {
        background: #D1D5DB;
    }

    .ta-pop-order-row.seg-overdue {
        background: #FFF8F8;
    }

    .ta-pop-order-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 8px;
    }

    .ta-pop-order-name-wrap {
        display: flex;
        align-items: center;
        gap: 5px;
        min-width: 0;
        flex-shrink: 1;
    }

    .ta-pop-gname {
        font-size: 13px;
        font-weight: 600;
        color: #0F172A;
    }

    .ta-pop-order-badges {
        display: flex;
        align-items: center;
        gap: 4px;
        flex-shrink: 0;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .ta-pop-order-meta {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    .ta-pop-trange {
        font-size: 12px;
        font-weight: 600;
        color: #374151;
        font-family: 'Inter', ui-sans-serif, sans-serif;
    }

    .ta-pop-scount {
        display: inline-flex;
        align-items: center;
        background: #F0FDF4;
        color: #065F46;
        border: 1px solid #A7F3D0;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 700;
        padding: 1px 7px;
        white-space: nowrap;
    }

    .ta-pop-order-note {
        margin-top: 4px;
        font-size: 11px;
        color: #92400E;
        font-style: italic;
        line-height: 1.4;
        padding: 3px 6px;
        background: #FEF9C3;
        border-left: 2px solid #FCD34D;
        border-radius: 0 4px 4px 0;
    }

    .ta-pop-status-pill {
        display: inline-flex;
        align-items: center;
        font-size: 10px;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 999px;
        border: 1px solid transparent;
        white-space: nowrap;
    }

    .ta-pop-created-badge {
        font-size: 10px;
        color: #374151;
        display: inline-flex;
        align-items: center;
        gap: 2px;
    }

    /* Deposit note — shown below orders list */
    .ta-pop-deposit {
        padding: 8px 14px;
        border-top: 1px solid #F3F4F6;
    }

    .ta-pop-deposit-display {
        font-size: 11px;
        color: var(--ta-ink);
        padding: 6px 8px;
        background: #FFFBEB;
        border: 1px solid #FDE68A;
        border-radius: 6px;
        margin-bottom: 4px;
        white-space: pre-wrap;
        line-height: 1.5;
    }

    .ta-pop-deposit-edit {
        margin-bottom: 6px;
    }

    .ta-pop-deposit-input {
        width: 100%;
        padding: 7px 10px;
        border-radius: 7px;
        border: 1.5px solid var(--ta-line);
        background: var(--ta-bg);
        font-size: 12px;
        color: var(--ta-ink);
        font-family: inherit;
        resize: vertical;
        outline: none;
        transition: border-color .15s;
        box-sizing: border-box;
        line-height: 1.5;
    }

    .ta-pop-deposit-input:focus {
        border-color: var(--ta-accent);
        box-shadow: 0 0 0 3px rgba(5, 150, 105, .08);
    }

    .ta-pop-deposit-btns {
        display: flex;
        gap: 6px;
        margin-top: 5px;
    }

    .ta-pop-deposit-save {
        padding: 4px 12px;
        background: var(--ta-accent);
        color: #fff;
        border: none;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        transition: background .15s;
    }

    .ta-pop-deposit-save:hover {
        background: #047857;
    }

    .ta-pop-deposit-save:disabled {
        opacity: .55;
        cursor: not-allowed;
    }

    .ta-pop-deposit-cancel {
        padding: 4px 10px;
        background: transparent;
        color: var(--ta-ink-mute);
        border: 1px solid var(--ta-line);
        border-radius: 6px;
        font-size: 11px;
        font-weight: 600;
        cursor: pointer;
        font-family: inherit;
        transition: all .15s;
    }

    .ta-pop-deposit-cancel:hover {
        background: var(--ta-line-soft);
    }

    .ta-pop-deposit-btn {
        font-size: 11px;
        font-weight: 600;
        color: var(--ta-accent);
        background: none;
        border: none;
        cursor: pointer;
        padding: 0;
        font-family: inherit;
        transition: opacity .15s;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .ta-pop-deposit-btn:hover {
        opacity: .7;
    }
</style>