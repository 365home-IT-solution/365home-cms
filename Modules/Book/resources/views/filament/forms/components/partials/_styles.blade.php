<style>
/* ── Scoped styles: CSS custom properties Filament – không phụ thuộc Tailwind build ── */
.bk-day-endpoint {
    background-color: rgb(var(--color-primary-600)) !important;
    color: #fff !important;
    font-weight: 700;
}
.bk-day-endpoint:hover { background-color: rgb(var(--color-primary-500)) !important; }

.bk-day-other {
    background-color: rgb(var(--color-primary-600) / 0.12);
    color: rgb(var(--color-primary-700));
    font-weight: 600;
}
.bk-day-in-range {
    color: rgb(var(--color-primary-700));
    font-weight: 600;
}
.bk-day-in-range:hover { background-color: rgb(var(--color-primary-600) / 0.1); }

.bk-strip {
    position: absolute; top: 3px; bottom: 3px;
    background-color: rgb(var(--color-primary-600) / 0.1);
    pointer-events: none;
}
.bk-strip-start { left: 50%; right: 0; }
.bk-strip-end   { left: 0;   right: 50%; }
.bk-strip-full  { left: 0;   right: 0; }

.bk-day-btn {
    width: 34px; height: 34px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%; font-size: 13px; font-weight: 500; color: #374151;
    transition: background 0.1s; position: relative; z-index: 1; cursor: pointer;
    border: none; background: transparent;
}
.bk-day-btn:hover:not(.bk-day-endpoint) { background: #f3f4f6; }
.bk-day-btn:focus { outline: none; }

.bk-nav-btn {
    width: 28px; height: 28px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 8px; color: #6b7280; cursor: pointer;
    transition: background 0.15s; border: none; background: transparent;
}
.bk-nav-btn:hover { background: #f3f4f6; }

/* ── Date input button ── */
.bk-date-btn {
    width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 6px;
    border: 1.5px solid #e5e7eb; border-radius: 10px; padding: 8px 11px;
    font-size: 13px; background: #fff; color: #374151;
    cursor: pointer; transition: border-color 0.15s, box-shadow 0.15s; text-align: left;
}
.bk-date-btn:hover { border-color: #d1d5db; }
.bk-date-btn.is-open {
    border-color: rgb(var(--color-primary-600));
    box-shadow: 0 0 0 3px rgb(var(--color-primary-600) / 0.12);
}

/* ── Time picker ── */
.bk-time-display {
    display: flex; align-items: center; justify-content: space-between;
    border: 1.5px solid #e5e7eb; border-radius: 10px; padding: 8px 11px;
    cursor: pointer; transition: border-color 0.15s, box-shadow 0.15s; background: #fff;
}
.bk-time-display:hover { border-color: #d1d5db; }
.bk-time-display.is-open {
    border-color: rgb(var(--color-primary-600));
    box-shadow: 0 0 0 3px rgb(var(--color-primary-600) / 0.12);
}
.bk-time-value { font-size: 15px; font-weight: 700; color: #111827; letter-spacing: 0.03em; }

.bk-time-panel {
    background: #fff; border: 1.5px solid #e5e7eb; border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.1); overflow: hidden; margin-top: 6px;
}
.bk-time-spinbox {
    display: flex; align-items: center; justify-content: center; gap: 4px;
}
.bk-spin-input {
    width: 52px; text-align: center; font-size: 22px; font-weight: 700; color: #111827;
    border: 1.5px solid #e5e7eb; border-radius: 8px; padding: 6px 4px;
    outline: none; transition: border-color 0.15s;
    -moz-appearance: textfield;
}
.bk-spin-input::-webkit-outer-spin-button,
.bk-spin-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.bk-spin-input:focus { border-color: rgb(var(--color-primary-600)); }
.bk-spin-sep { font-size: 22px; font-weight: 700; color: #9ca3af; padding: 0 2px; }
.bk-spin-btn {
    width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;
    border: 1.5px solid #e5e7eb; border-radius: 6px; cursor: pointer; color: #6b7280;
    background: #fff; transition: all 0.12s; flex-shrink: 0;
}
.bk-spin-btn:hover { border-color: rgb(var(--color-primary-600)); color: rgb(var(--color-primary-600)); background: rgb(var(--color-primary-600) / 0.06); }

.bk-preset-btn {
    padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600;
    border: 1.5px solid #e5e7eb; background: #fff; color: #374151; cursor: pointer;
    transition: all 0.12s;
}
.bk-preset-btn:hover {
    border-color: rgb(var(--color-primary-600));
    color: rgb(var(--color-primary-600));
    background: rgb(var(--color-primary-600) / 0.06);
}
.bk-preset-btn.active {
    border-color: rgb(var(--color-primary-600));
    background: rgb(var(--color-primary-600));
    color: #fff;
}

/* ── Apply button ── */
.bk-apply-btn {
    flex: 1; background-color: rgb(var(--color-primary-600)); color: #fff;
    font-weight: 600; font-size: 13px; border: none; border-radius: 10px;
    padding: 10px 16px; cursor: pointer; transition: opacity 0.15s;
    display: flex; align-items: center; justify-content: center; gap: 6px;
}
.bk-apply-btn:hover:not(:disabled) { opacity: 0.88; }
.bk-apply-btn:disabled { opacity: 0.4; cursor: not-allowed; }

/* ── Action modal icon box ── */
.bk-modal-icon {
    width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    transition: background 0.2s;
}
.bk-modal-icon--primary { background: rgba(var(--primary-600), 0.12); }
.bk-modal-icon--amber   { background: #fef3c7; }
.bk-modal-icon--orange  { background: #ffedd5; }
.bk-modal-icon--emerald { background: #d1fae5; }

/* ── Segmented tab control ── */
.bk-tab-rail {
    display: flex; gap: 3px;
    background: #e5e7eb; border-radius: 11px; padding: 3px;
}
.bk-tab-btn {
    flex: 1; display: flex; align-items: center; justify-content: center; gap: 5px;
    padding: 7px 6px; font-size: 11px; font-weight: 600;
    border: none; cursor: pointer; border-radius: 8px;
    transition: background 0.18s, color 0.18s, box-shadow 0.18s;
    background: transparent; color: #6b7280; line-height: 1.2;
    white-space: nowrap;
}
.bk-tab-btn--primary {
    background: rgb(var(--primary-600));
    color: #fff;
    box-shadow: 0 2px 8px rgb(var(--primary-600) / 0.3);
}
.bk-tab-btn--amber {
    background: #fff;
    color: #b45309;
    box-shadow: 0 1px 6px rgba(0,0,0,0.14);
}
.bk-tab-btn--orange {
    background: #fff;
    color: #ea580c;
    box-shadow: 0 1px 6px rgba(0,0,0,0.14);
}
.bk-tab-btn--emerald {
    background: #fff;
    color: #065f46;
    box-shadow: 0 1px 6px rgba(0,0,0,0.14);
}

/* ── Action modal footer buttons ── */
.bk-btn-cancel {
    flex: 1; border: 1.5px solid #e5e7eb; background: #fff; border-radius: 10px;
    padding: 10px 16px; font-size: 13px; font-weight: 600; color: #374151;
    cursor: pointer; transition: background 0.15s;
}
.bk-btn-cancel:hover { background: #f9fafb; }

.bk-btn-primary {
    flex: 2; border: none; border-radius: 10px;
    padding: 10px 16px; font-size: 13px; font-weight: 700; color: #fff;
    cursor: pointer; transition: all 0.18s;
    display: flex; align-items: center; justify-content: center; gap: 6px;
}
.bk-btn-primary--blue    { background: rgb(var(--primary-600)); box-shadow: 0 2px 8px rgb(var(--primary-600) / 0.35); }
.bk-btn-primary--amber   { background: #d97706; box-shadow: 0 2px 8px rgba(217,119,6,0.3); }
.bk-btn-primary--emerald { background: #059669; box-shadow: 0 2px 8px rgba(5,150,105,0.3); }
.bk-btn-primary--red     { background: #dc2626; box-shadow: 0 2px 8px rgba(220,38,38,0.3); }
.bk-btn-primary--disabled { background: #d1d5db; box-shadow: none; cursor: not-allowed; }
.bk-btn-primary:not(.bk-btn-primary--disabled):hover { opacity: 0.88; }

/* ── Selection list (promo / coupon) ── */
.bk-sel-list { border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; }
.bk-sel-list-scroll { max-height:200px; overflow-y:auto; }
.bk-sel-row { display:flex; align-items:center; gap:10px; padding:9px 13px; border-bottom:1px solid #f3f4f6; cursor:pointer; transition:background 0.15s; }
.bk-sel-row:last-child { border-bottom:none; }
.bk-sel-row:hover { background:#f9fafb; }
.bk-sel-row--amber  { background:#fffbeb !important; }
.bk-sel-row--yellow { background:#fefce8 !important; }
.bk-sel-row--emerald { background:#ecfdf5 !important; }
.bk-sel-check { width:16px; height:16px; border-radius:4px; border:1.5px solid #d1d5db; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:all 0.15s; background:#fff; }
.bk-sel-check--amber   { background:#d97706; border-color:#d97706; }
.bk-sel-check--yellow  { background:#eef122; border-color:#eef122; }
.bk-sel-check--emerald { background:#059669; border-color:#059669; }
.bk-sel-label { font-size:12px; font-weight:500; color:#374151; flex:1; line-height:1.4; transition:color 0.12s; }
.bk-sel-label--amber   { color:#92400e; font-weight:600; }
.bk-sel-label--yellow  { color:#713f12; font-weight:600; }
.bk-sel-label--emerald { color:#065f46; font-weight:600; }
.bk-sel-badge { font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; flex-shrink:0; }
.bk-sel-badge--amber   { background:#fef3c7; color:#b45309; }
.bk-sel-badge--yellow  { background:#fef9c3; color:#713f12; }
.bk-sel-badge--emerald { background:#d1fae5; color:#065f46; }
.bk-sel-empty { text-align:center; padding:28px 16px; color:#9ca3af; font-size:12px; }
.bk-sel-count { margin-top:6px; font-size:11px; color:#6b7280; }
.bk-sel-count strong { font-weight:700; }

/* ── Main calendar cell redesign ── */
.cal-cell {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    min-height: 56px; border-radius: 12px; cursor: pointer; transition: all 0.12s;
    position: relative; padding: 4px 2px; gap: 2px; border: none; background: transparent;
    width: 100%;
}
.cal-cell:hover:not(.cal-cell--editing):not(.cal-cell--has-slot) {
    background: #f9fafb;
}
.cal-cell--has-slot {
    background: rgb(52 211 153 / 0.1);
}
.cal-cell--has-slot:hover { background: rgb(52 211 153 / 0.18); }
/* cal-cell--has-surcharge: không dùng nền, chỉ hiển thị dot */
.cal-cell--has-promo {
    background: rgb(251 191 36 / 0.08);
}
.cal-cell--has-promo:hover { background: rgb(251 191 36 / 0.16); }
.cal-cell--editing {
    background: rgb(var(--color-primary-600) / 0.1);
    box-shadow: 0 0 0 2px rgb(var(--color-primary-600));
}
.cal-cell__day {
    font-size: 14px; font-weight: 600; line-height: 1;
}
.cal-cell--editing   .cal-cell__day { color: rgb(var(--color-primary-600)); font-weight: 700; }
.cal-cell--has-slot  .cal-cell__day { color: #059669; }
.cal-cell--has-promo .cal-cell__day { color: #d97706; }
.cal-cell--normal    .cal-cell__day { color: #374151; }

.cal-price-tag {
    font-size: 9px; font-weight: 700; color: #059669;
    background: #d1fae5; border-radius: 4px; padding: 1px 4px; line-height: 1.4;
    max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.cal-price-tag--surcharge {
    font-size: 9px; font-weight: 700; color: #ea580c;
    background: #ffedd5; border-radius: 4px; padding: 1px 4px; line-height: 1.4;
    max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.cal-price-tag--base {
    font-size: 9px; font-weight: 600; color: #9ca3af;
    background: #f3f4f6; border-radius: 4px; padding: 1px 4px; line-height: 1.4;
    max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.cal-promo-dot {
    width: 5px; height: 5px; border-radius: 50%; background: #f97316; flex-shrink: 0;
}
.cal-surcharge-dot {
    width: 5px; height: 5px; border-radius: 50%; background: #eef122; flex-shrink: 0;
}
.cal-coupon-dot {
    width: 5px; height: 5px; border-radius: 50%; background: #ef4444; flex-shrink: 0;
}

.bk-section-label {
    font-size: 10px; font-weight: 700; letter-spacing: 0.07em;
    text-transform: uppercase; color: #9ca3af; margin-bottom: 6px;
}
/* --- Thay thế Dot thành Line (Đường kẻ) --- */
.cal-promo-line {
    width: 14px;             /* Độ dài đường kẻ */
    height: 3px;             /* Độ dày đường kẻ */
    border-radius: 2px;      /* Bo góc nhẹ */
    background: #f97316;    /* Màu cam */
    flex-shrink: 0;
}

.cal-surcharge-line {
    width: 14px;
    height: 3px;
    border-radius: 2px;
    background: #eef122;    /* Màu vàng */
    flex-shrink: 0;
}

.cal-coupon-line {
    width: 14px;
    height: 3px;
    border-radius: 2px;
    background: #ef4444;    /* Màu đỏ */
    flex-shrink: 0;
}

/* Container chứa các đường kẻ trong mỗi ô ngày */
.cal-indicator-wrapper {
    display: flex;
    gap: 3px;                /* Khoảng cách giữa các đường kẻ */
    align-items: center;
    justify-content: center;
    margin-top: 4px;         /* Khoảng cách với phần giá ở trên */
    min-height: 4px;
}

/* Cập nhật lại chiều cao ô lịch để không bị chật */
.cal-cell {
    min-height: 60px !important; 
    padding: 6px 2px !important;
}
.cal-cell--past {
    opacity: 0.4;             /* Làm mờ */
    filter: grayscale(1);     /* Chuyển sang màu xám */
    cursor: not-allowed !important; 
    pointer-events: none;     /* Không cho phép click */
    background: #f3f4f6 !important; /* Nền xám nhạt */
}

.cal-cell--past .cal-cell__day {
    color: #9ca3af !important;
}
</style>
