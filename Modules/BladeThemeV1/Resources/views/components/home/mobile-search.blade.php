{{-- Ô tìm kiếm trang chủ MOBILE kiểu Agoda — thay cho thanh tìm kiếm gọn trong header mobile ở trang chủ.
     Logic Alpine: Resources/assets/js/home-mobile-search.js (nạp qua bundle home.js). Chỉ in khi request
     cần bản mobile (App\Support\ViewportMode) và luôn ẩn từ lg trở lên.
     CSS viết thẳng ở đây (prefix hms-) thay vì class Tailwind tuỳ ý, để không phụ thuộc lần build home.css. --}}
@props(['roomTypes' => []])

@php
    // Phòng MiniHouse (thuê tháng) không nằm trong luồng tìm phòng ngắn hạn → không có chip lọc riêng.
    $hmsTypes = collect($roomTypes)
        ->reject(fn ($t) => in_array($t['slug'] ?? '', ['mini_house', 'minihouse'], true))
        ->map(fn ($t) => ['slug' => $t['slug'], 'name' => $t['name']])
        ->values();

    // Nhãn ngày in sẵn từ server (Alpine ghi đè sau) để ô ngày không trống/nhảy kích thước lúc tải.
    $hmsDayLabel = fn (\Carbon\Carbon $d) => ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'][$d->dayOfWeek] . ', ' . $d->day . ' tháng ' . $d->month;
    $hmsToday = now();
@endphp

<section class="hms lg:hidden" x-data="homeMobileSearch(@js(['roomTypes' => $hmsTypes]))" x-init="init()">
    <style>
        .hms { padding: 14px 16px 6px; background: #fff; }
        .hms-card { background: #fff; border-radius: 16px; padding: 12px; box-shadow: 0 4px 20px rgba(15, 23, 42, .10); border: 1px solid #eef0f3; display: flex; flex-direction: column; gap: 10px; }
        .hms-field { display: flex; align-items: center; gap: 12px; width: 100%; min-height: 52px; padding: 10px 14px; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; text-align: left; cursor: pointer; transition: border-color .15s ease; }
        .hms-field:active { border-color: var(--color-primary); }
        .hms-field-dest { border-color: color-mix(in srgb, var(--color-primary) 45%, #e5e7eb); }
        .hms-ico { width: 20px; height: 20px; flex-shrink: 0; color: #4b5563; }
        .hms-ph { font-size: 15px; color: #6b7280; }
        .hms-val { font-size: 15px; font-weight: 700; color: var(--color-primary); line-height: 1.25; }
        .hms-sub { display: block; font-size: 12px; color: #6b7280; margin-top: 1px; }
        .hms-dates { display: grid; grid-template-columns: 1fr 1fr; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
        .hms-date { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: #fff; text-align: left; min-height: 58px; cursor: pointer; }
        .hms-date + .hms-date { border-left: 1px solid #e5e7eb; }
        .hms-date-k { display: block; font-size: 12px; color: #6b7280; }
        .hms-date-v { display: block; font-size: 14.5px; font-weight: 700; color: var(--color-primary); white-space: nowrap; }
        .hms-guests-v { font-size: 14.5px; color: #374151; }
        .hms-guests-v b { color: var(--color-primary); }
        .hms-submit { width: 100%; height: 48px; border: none; border-radius: 12px; background: var(--color-primary); color: #fff; font-size: 16px; font-weight: 800; letter-spacing: .06em; cursor: pointer; }
        .hms-submit:active { opacity: .9; }

        /* Lớp toàn màn hình — z 9000: trên header/bottom nav, dưới modal đăng nhập (z 9999, mở khi bấm ♥).
           Nút liên hệ nổi cũng z 9999 nên ẩn đi trong lúc có lớp mở (body.hms-open, xem lockScroll()). */
        body.hms-open .custom-floating-contact { display: none !important; }
        /* Màn kết quả: bottom bar của site (.bottom-navigation-1, z 1000) nổi lên trên lớp kết quả;
           danh sách chừa chỗ đáy cho nó. */
        body.hms-results .bottom-navigation-1 { z-index: 9001; }
        body.hms-results .hms-list { padding-bottom: calc(88px + env(safe-area-inset-bottom)); }
        .hms-layer { position: fixed; inset: 0; z-index: 9000; background: #fff; display: flex; flex-direction: column; }
        .hms-bar { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-bottom: 1px solid #f1f2f4; background: #fff; }
        .hms-bar-title { flex: 1; text-align: center; font-size: 16px; font-weight: 700; color: #111827; margin-right: 40px; }
        .hms-icon-btn { width: 40px; height: 40px; min-width: 40px; min-height: 40px; border: none; border-radius: 50%; background: transparent; display: flex; align-items: center; justify-content: center; color: #111827; cursor: pointer; padding: 0; }
        .hms-icon-btn:active { background: #f3f4f6; }
        .hms-scroll { flex: 1; overflow-y: auto; -webkit-overflow-scrolling: touch; overscroll-behavior: contain; }

        /* Điểm đến */
        .hms-search { padding: 10px 16px 12px; }
        .hms-search-box { display: flex; align-items: center; gap: 10px; height: 48px; padding: 0 14px; border-radius: 14px; background: color-mix(in srgb, var(--color-primary) 8%, #f4f6f8); }
        .hms-search-box input { flex: 1; min-width: 0; border: none; outline: none; background: transparent; font-size: 15.5px; color: #111827; padding: 0; box-shadow: none; }
        .hms-search-box input:focus { box-shadow: none; }
        .hms-sec { padding: 10px 16px 6px; font-size: 12.5px; font-weight: 700; color: #4b5563; background: #f7f8fa; border-top: 1px solid #f1f2f4; border-bottom: 1px solid #f1f2f4; }
        .hms-row { display: flex; align-items: center; gap: 12px; width: 100%; padding: 12px 16px; border: none; border-bottom: 1px solid #f3f4f6; background: #fff; text-align: left; cursor: pointer; }
        .hms-row:active { background: #f9fafb; }
        .hms-row-main { flex: 1; min-width: 0; }
        .hms-row-name { display: block; font-size: 16px; font-weight: 600; color: var(--color-primary); }
        .hms-row-sub { display: block; font-size: 12.5px; color: #6b7280; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .hms-row-count { color: #059669; font-weight: 600; }
        .hms-tag { flex-shrink: 0; font-size: 12px; font-weight: 600; color: var(--color-primary); border: 1px solid color-mix(in srgb, var(--color-primary) 60%, #fff); border-radius: 6px; padding: 4px 10px; }
        .hms-row-right { flex-shrink: 0; text-align: right; font-size: 12px; color: #374151; line-height: 1.45; }
        .hms-note { padding: 8px 16px; font-size: 12.5px; color: #b91c1c; }
        .hms-muted { padding: 24px 16px; text-align: center; font-size: 14px; color: #6b7280; }

        /* Bottom sheet (lịch, khách) */
        .hms-sheet-bg { position: fixed; inset: 0; z-index: 9000; background: rgba(15, 23, 42, .45); display: flex; align-items: flex-end; }
        .hms-sheet { width: 100%; max-height: 92vh; background: #fff; border-radius: 18px 18px 0 0; display: flex; flex-direction: column; }
        .hms-sheet-foot { padding: 12px 16px calc(12px + env(safe-area-inset-bottom)); border-top: 1px solid #f1f2f4; display: flex; align-items: center; gap: 12px; }
        .hms-sheet-foot .hms-submit { flex: 1; letter-spacing: .02em; font-size: 15px; }
        .hms-weekdays { display: grid; grid-template-columns: repeat(7, 1fr); padding: 6px 12px; font-size: 12px; font-weight: 600; color: #6b7280; text-align: center; border-bottom: 1px solid #f1f2f4; }
        .hms-month { padding: 10px 12px 4px; }
        .hms-month-title { font-size: 15px; font-weight: 700; color: #111827; padding: 4px 4px 8px; }
        .hms-days { display: grid; grid-template-columns: repeat(7, 1fr); row-gap: 4px; }
        .hms-day { position: relative; height: 44px; min-height: 44px; min-width: 0; border: none; background: transparent; font-size: 14.5px; color: #111827; cursor: pointer; padding: 0; }
        .hms-day span { position: relative; z-index: 1; display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; }
        .hms-day.is-blank { visibility: hidden; pointer-events: none; }
        .hms-day.is-past { color: #d1d5db; pointer-events: none; }
        .hms-day.is-today span { box-shadow: inset 0 0 0 1px #9ca3af; }
        .hms-day.is-range::before, .hms-day.is-start.has-range::before, .hms-day.is-end::before { content: ''; position: absolute; top: 2px; bottom: 2px; left: 0; right: 0; background: color-mix(in srgb, var(--color-primary) 14%, #fff); }
        .hms-day.is-start.has-range::before { left: 50%; }
        .hms-day.is-end::before { right: 50%; }
        .hms-day.is-start span, .hms-day.is-end span { background: var(--color-primary); color: #fff; font-weight: 700; box-shadow: none; }
        .hms-foot-info { font-size: 13px; color: #374151; line-height: 1.35; }
        .hms-foot-info b { color: #111827; }
        .hms-stepper-row { display: flex; align-items: center; justify-content: space-between; padding: 16px; border-bottom: 1px solid #f3f4f6; }
        .hms-stepper-row p { margin: 0; font-size: 15.5px; font-weight: 600; color: #111827; }
        .hms-stepper-row small { display: block; font-size: 12.5px; color: #6b7280; font-weight: 400; }
        .hms-stepper { display: flex; align-items: center; gap: 14px; }
        .hms-step { width: 36px; height: 36px; min-width: 36px; min-height: 36px; border-radius: 50%; border: 1px solid color-mix(in srgb, var(--color-primary) 55%, #fff); background: #fff; color: var(--color-primary); font-size: 20px; line-height: 1; display: flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; }
        .hms-step:disabled { border-color: #e5e7eb; color: #d1d5db; cursor: default; }
        .hms-step-v { min-width: 22px; text-align: center; font-size: 16px; font-weight: 700; }

        /* Kết quả */
        .hms-res-bar { display: flex; align-items: center; gap: 6px; padding: 8px 10px 8px 6px; background: #fff; }
        .hms-res-summary { flex: 1; min-width: 0; display: flex; align-items: center; gap: 10px; padding: 8px 14px; border-radius: 999px; background: #f2f4f7; border: none; text-align: left; cursor: pointer; }
        .hms-res-summary-main { flex: 1; min-width: 0; }
        .hms-res-summary b { display: block; font-size: 14.5px; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .hms-res-summary small { display: block; font-size: 12px; color: #4b5563; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .hms-chips { display: flex; gap: 8px; padding: 2px 12px 10px; overflow-x: auto; scrollbar-width: none; border-bottom: 1px solid #eef0f3; background: #fff; }
        .hms-chips::-webkit-scrollbar { display: none; }
        .hms-chip { flex-shrink: 0; display: inline-flex; align-items: center; gap: 6px; height: 34px; min-height: 34px; padding: 0 14px; border-radius: 999px; border: 1px solid #d1d5db; background: #fff; font-size: 13.5px; font-weight: 600; color: #374151; cursor: pointer; white-space: nowrap; }
        .hms-chip.is-on { border-color: var(--color-primary); background: color-mix(in srgb, var(--color-primary) 10%, #fff); color: var(--color-primary); }
        .hms-sort-menu { position: absolute; left: 12px; top: 100%; z-index: 5; min-width: 200px; background: #fff; border-radius: 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, .16); padding: 6px; }
        .hms-sort-menu button { display: flex; align-items: center; justify-content: space-between; width: 100%; padding: 10px 12px; border: none; background: transparent; border-radius: 8px; font-size: 14px; color: #111827; text-align: left; cursor: pointer; }
        .hms-sort-menu button.is-on { color: var(--color-primary); font-weight: 700; background: #f5f7f8; }
        .hms-list { background: #f2f3f5; padding: 10px 10px calc(16px + env(safe-area-inset-bottom)); min-height: 100%; }
        .hms-group { display: flex; align-items: center; gap: 8px; margin: 4px 0 10px; padding: 10px 14px; border-radius: 10px; background: #1f2937; color: #fff; font-size: 13.5px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
        .hms-group-plain { background: transparent; color: #374151; padding: 6px 4px; letter-spacing: 0; text-transform: none; font-size: 14px; }
        .hms-count { font-size: 12.5px; color: #4b5563; padding: 2px 4px 10px; }
        .hms-room { position: relative; display: flex; gap: 0; margin-bottom: 10px; background: #fff; border-radius: 12px; overflow: hidden; text-decoration: none; color: inherit; box-shadow: 0 1px 3px rgba(15, 23, 42, .06); }
        .hms-room.is-promo { box-shadow: 0 0 0 1.5px color-mix(in srgb, #ef4444 40%, #fff), 0 1px 3px rgba(15, 23, 42, .06); }
        .hms-room-img { position: relative; flex: 0 0 40%; max-width: 40%; min-height: 168px; background: #e5e7eb; }
        .hms-room-img img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
        .hms-off { position: absolute; left: 0; top: 10px; z-index: 2; background: #e11d48; color: #fff; font-size: 12px; font-weight: 800; padding: 3px 8px; border-radius: 0 6px 6px 0; }
        .hms-room .room-wishlist-btn { top: 6px; right: 6px; }
        .hms-room-body { flex: 1; min-width: 0; padding: 10px 12px 10px; display: flex; flex-direction: column; gap: 4px; }
        .hms-room-kind { font-size: 11.5px; color: #6b7280; }
        .hms-room-name { font-size: 15px; font-weight: 700; color: #111827; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; margin: 0; }
        .hms-room-rate { display: flex; align-items: center; gap: 4px; font-size: 12.5px; color: #374151; }
        .hms-room-rate svg { width: 13px; height: 13px; color: #f59e0b; }
        .hms-room-loc { display: flex; align-items: flex-start; gap: 4px; font-size: 12.5px; color: #374151; }
        .hms-room-loc svg { width: 13px; height: 13px; flex-shrink: 0; margin-top: 2px; color: #6b7280; }
        .hms-room-loc span { display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }
        .hms-room-tags { display: flex; flex-wrap: wrap; gap: 4px; }
        .hms-room-tag { font-size: 11px; font-weight: 600; padding: 2px 7px; border-radius: 5px; background: #ecfdf5; color: #047857; }
        .hms-room-tag.is-deal { background: #fff1f2; color: #e11d48; }
        .hms-room-price { margin-top: auto; text-align: right; padding-top: 4px; }
        .hms-room-price s { display: block; font-size: 12px; color: #9ca3af; }
        .hms-room-price strong { font-size: 18px; font-weight: 800; color: #111827; }
        .hms-room.is-promo .hms-room-price strong { color: #e11d48; }
        .hms-room-price small { display: block; font-size: 11px; color: #6b7280; }
        .hms-soldout { position: absolute; inset: 0; z-index: 3; background: rgba(255, 255, 255, .6); display: flex; align-items: center; justify-content: center; }
        .hms-soldout span { background: #111827; color: #fff; font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 99px; }
        .hms-skel { background: #e9ebee; border-radius: 6px; }
        .hms-empty { padding: 40px 20px; text-align: center; }
        .hms-empty p { margin: 0 0 6px; font-size: 16px; font-weight: 700; color: #111827; }
        .hms-empty small { display: block; font-size: 13.5px; color: #6b7280; margin-bottom: 16px; }
        .hms-empty-chips { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; }
        .hms-spin { width: 22px; height: 22px; border-radius: 50%; border: 2.5px solid #d1d5db; border-top-color: var(--color-primary); animation: hms-spin .8s linear infinite; margin: 14px auto; }
        @keyframes hms-spin { to { transform: rotate(360deg); } }
    </style>

    {{-- ══════════ Form ══════════ --}}
    <div class="hms-card">
        <button type="button" class="hms-field hms-field-dest" @click="openDest()">
            <svg class="hms-ico" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z"/></svg>
            <span class="min-w-0 flex-1">
                <span class="hms-ph" x-show="!dest">Bạn muốn đi đâu?</span>
                <span x-show="dest" x-cloak>
                    <span class="hms-val" x-text="dest && dest.name"></span>
                    <span class="hms-sub truncate" x-text="dest && dest.subtitle"></span>
                </span>
            </span>
        </button>

        <div class="hms-dates">
            <button type="button" class="hms-date" @click="openDates()">
                <svg class="hms-ico" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="15" rx="2"/><path stroke-linecap="round" d="M3.5 9.5h17M8 3v4M16 3v4M8 14l2 2 4-4"/></svg>
                <span class="min-w-0">
                    <span class="hms-date-k">Nhận phòng</span>
                    <span class="hms-date-v" x-text="dayLabel(checkin)">{{ $hmsDayLabel($hmsToday) }}</span>
                </span>
            </button>
            <button type="button" class="hms-date" @click="openDates()">
                <svg class="hms-ico" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="15" rx="2"/><path stroke-linecap="round" d="M3.5 9.5h17M8 3v4M16 3v4M9 15h6"/></svg>
                <span class="min-w-0">
                    <span class="hms-date-k">Trả phòng</span>
                    <span class="hms-date-v" x-text="dayLabel(checkout)">{{ $hmsDayLabel($hmsToday->copy()->addDay()) }}</span>
                </span>
            </button>
        </div>

        <button type="button" class="hms-field" @click="openGuests()">
            <svg class="hms-ico" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path stroke-linecap="round" d="M5 20c.8-3.6 3.6-5.5 7-5.5s6.2 1.9 7 5.5"/></svg>
            <span class="hms-guests-v"><b x-text="rooms">1</b> Phòng, <b x-text="adults">2</b> Người lớn, <b x-text="children">0</b> Trẻ em</span>
        </button>

        <button type="button" class="hms-submit" @click="search()">TÌM</button>
    </div>

    {{-- ══════════ Chọn điểm đến ══════════ --}}
    <template x-teleport="body">
        <div class="hms-layer lg:hidden" x-show="isOpen('dest')" x-cloak
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-3" x-transition:enter-end="opacity-100 translate-y-0">
            <div class="hms-bar">
                <button type="button" class="hms-icon-btn" aria-label="Quay lại" @click="pop()">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <span class="hms-bar-title">Tìm chỗ ở</span>
            </div>
            <div class="hms-search">
                <label class="hms-search-box">
                    <svg width="20" height="20" fill="none" stroke="#374151" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z"/></svg>
                    <input type="text" inputmode="search" id="hms-dest-input" x-model="query" @input="onQueryInput()" placeholder="Nhập điểm đến hoặc cơ sở lưu trú" autocomplete="off" enterkeyhint="search" aria-label="Nhập điểm đến hoặc cơ sở lưu trú">
                    <button type="button" class="hms-icon-btn" style="width:28px;height:28px;min-width:28px;min-height:28px;" x-show="query" @click="query = ''; onQueryInput(); document.getElementById('hms-dest-input').focus()" aria-label="Xoá">
                        <svg width="16" height="16" fill="none" stroke="#6b7280" stroke-width="2.4" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                </label>
            </div>

            <div class="hms-scroll">
                {{-- Chưa gõ: gần đây + điểm đến có phòng + toàn bộ tỉnh/thành cũ --}}
                <template x-if="!query.trim()">
                    <div>
                        <template x-if="recent.length">
                            <div>
                                <div class="hms-sec">Đã xem gần đây</div>
                                <template x-for="(item, i) in recent" :key="'recent-' + i">
                                    <button type="button" class="hms-row" @click="pickRecent(item)">
                                        <span class="hms-row-main">
                                            <span class="hms-row-name" x-text="item.dest.name"></span>
                                            <span class="hms-row-sub" x-text="item.dest.subtitle"></span>
                                            <span class="hms-row-sub" x-text="shortDay(item.checkin) + ' - ' + shortDay(item.checkout)"></span>
                                        </span>
                                        <span class="hms-row-right">
                                            <span x-text="item.rooms + ' phòng'"></span><br>
                                            <span x-text="item.adults + ' người lớn'"></span><br>
                                            <span x-text="item.children + ' trẻ em'"></span>
                                        </span>
                                    </button>
                                </template>
                            </div>
                        </template>

                        <button type="button" class="hms-row" @click="useNearby()">
                            <svg width="22" height="22" fill="none" stroke="#111827" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2.5" fill="#111827"/><path stroke-linecap="round" d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>
                            <span class="hms-row-main" style="font-size:15.5px; color:#111827;">Gần đây</span>
                            <span class="hms-spin" style="margin:0; width:18px; height:18px;" x-show="locating"></span>
                        </button>
                        <p class="hms-note" x-show="locateError" x-text="locateError"></p>

                        <div class="hms-spin" x-show="destLoading"></div>
                        <template x-if="destError">
                            <div class="hms-muted">Không tải được danh sách tỉnh thành. <button type="button" class="hms-row-count" style="border:none;background:none;" @click="loadDestinations()">Thử lại</button></div>
                        </template>

                        <template x-if="popularDestinations.length">
                            <div>
                                <div class="hms-sec">Điểm đến đang có phòng</div>
                                <template x-for="d in popularDestinations" :key="'pop-' + d.kind + d.code">
                                    <button type="button" class="hms-row" @click="pickDestination(d)">
                                        <span class="hms-row-main">
                                            <span class="hms-row-name" x-text="d.short_name"></span>
                                            <span class="hms-row-sub" x-text="destSubtitle(d)"></span>
                                            <span class="hms-row-sub hms-row-count" x-text="d.room_count + ' phòng đang mở bán'"></span>
                                        </span>
                                        <span class="hms-tag" x-text="destTag(d)"></span>
                                    </button>
                                </template>
                            </div>
                        </template>

                        <template x-if="allProvinces.length">
                            <div>
                                <div class="hms-sec">Tất cả tỉnh thành</div>
                                <template x-for="d in allProvinces" :key="'all-' + d.code">
                                    <button type="button" class="hms-row" @click="pickDestination(d)">
                                        <span class="hms-row-main">
                                            <span class="hms-row-name" x-text="d.short_name"></span>
                                            <span class="hms-row-sub" x-text="destSubtitle(d)"></span>
                                        </span>
                                        <span class="hms-tag" x-text="destTag(d)"></span>
                                    </button>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Đang gõ: tỉnh/thành khớp + cơ sở lưu trú (chi nhánh) khớp --}}
                <template x-if="query.trim()">
                    <div>
                        <template x-if="filteredDestinations.length">
                            <div>
                                <div class="hms-sec">Điểm đến</div>
                                <template x-for="d in filteredDestinations" :key="'f-' + d.kind + d.code">
                                    <button type="button" class="hms-row" @click="pickDestination(d)">
                                        <span class="hms-row-main">
                                            <span class="hms-row-name" x-text="d.short_name"></span>
                                            <span class="hms-row-sub" x-text="destSubtitle(d)"></span>
                                            <span class="hms-row-sub hms-row-count" x-show="d.room_count > 0" x-text="d.room_count + ' phòng đang mở bán'"></span>
                                        </span>
                                        <span class="hms-tag" x-text="destTag(d)"></span>
                                    </button>
                                </template>
                            </div>
                        </template>
                        <template x-if="branchHits.length">
                            <div>
                                <div class="hms-sec">Cơ sở lưu trú</div>
                                <template x-for="b in branchHits" :key="'b-' + b.id">
                                    <button type="button" class="hms-row" @click="pickBranch(b)">
                                        <span class="hms-row-main">
                                            <span class="hms-row-name" x-text="b.name"></span>
                                            <span class="hms-row-sub" x-text="b.description"></span>
                                        </span>
                                        <span class="hms-tag">Chỗ ở</span>
                                    </button>
                                </template>
                            </div>
                        </template>
                        <div class="hms-spin" x-show="branchLoading || destLoading"></div>
                        <template x-if="!branchLoading && !destLoading && !filteredDestinations.length && !branchHits.length">
                            <div class="hms-muted">Không tìm thấy điểm đến phù hợp.</div>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </template>

    {{-- ══════════ Lịch nhận / trả phòng ══════════ --}}
    <template x-teleport="body">
        <div class="hms-sheet-bg lg:hidden" x-show="isOpen('dates')" x-cloak @click.self="pop()" x-transition.opacity>
            <div class="hms-sheet" style="height:88vh;">
                <div class="hms-bar">
                    <button type="button" class="hms-icon-btn" aria-label="Đóng" @click="pop()">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                    <span class="hms-bar-title">Chọn ngày</span>
                </div>
                <div class="hms-weekdays"><span>T2</span><span>T3</span><span>T4</span><span>T5</span><span>T6</span><span>T7</span><span>CN</span></div>
                <div class="hms-scroll">
                    <template x-for="m in months" :key="m.key">
                        <div class="hms-month">
                            <div class="hms-month-title" x-text="m.title"></div>
                            <div class="hms-days">
                                <template x-for="(iso, idx) in m.cells" :key="m.key + '-' + idx">
                                    <button type="button" class="hms-day" :class="dayClass(iso)" @click="pickDay(iso)"><span x-text="dayNum(iso)"></span></button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
                <div class="hms-sheet-foot">
                    <div class="hms-foot-info">
                        <template x-if="draftIn && draftOut">
                            <span><b x-text="shortDay(draftIn) + ' - ' + shortDay(draftOut)"></b><br><span x-text="draftNights + ' đêm'"></span></span>
                        </template>
                        <template x-if="draftIn && !draftOut">
                            <span>Nhận <b x-text="shortDay(draftIn)"></b><br>Chọn ngày trả phòng</span>
                        </template>
                    </div>
                    <button type="button" class="hms-submit" :disabled="!draftIn" @click="applyDates()">Xong</button>
                </div>
            </div>
        </div>
    </template>

    {{-- ══════════ Phòng & khách ══════════ --}}
    <template x-teleport="body">
        <div class="hms-sheet-bg lg:hidden" x-show="isOpen('guests')" x-cloak @click.self="pop()" x-transition.opacity>
            <div class="hms-sheet">
                <div class="hms-bar">
                    <button type="button" class="hms-icon-btn" aria-label="Đóng" @click="pop()">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                    <span class="hms-bar-title">Phòng và khách</span>
                </div>
                <div class="hms-stepper-row">
                    <p>Phòng</p>
                    <div class="hms-stepper">
                        <button type="button" class="hms-step" :disabled="draftRooms <= 1" @click="step('draftRooms', -1)" aria-label="Bớt phòng">−</button>
                        <span class="hms-step-v" x-text="draftRooms"></span>
                        <button type="button" class="hms-step" :disabled="draftRooms >= 10" @click="step('draftRooms', 1)" aria-label="Thêm phòng">+</button>
                    </div>
                </div>
                <div class="hms-stepper-row">
                    <p>Người lớn <small>Từ 18 tuổi</small></p>
                    <div class="hms-stepper">
                        <button type="button" class="hms-step" :disabled="draftAdults <= 1" @click="step('draftAdults', -1)" aria-label="Bớt người lớn">−</button>
                        <span class="hms-step-v" x-text="draftAdults"></span>
                        <button type="button" class="hms-step" :disabled="draftAdults >= 30" @click="step('draftAdults', 1)" aria-label="Thêm người lớn">+</button>
                    </div>
                </div>
                <div class="hms-stepper-row">
                    <p>Trẻ em <small>Dưới 18 tuổi</small></p>
                    <div class="hms-stepper">
                        <button type="button" class="hms-step" :disabled="draftChildren <= 0" @click="step('draftChildren', -1)" aria-label="Bớt trẻ em">−</button>
                        <span class="hms-step-v" x-text="draftChildren"></span>
                        <button type="button" class="hms-step" :disabled="draftChildren >= 10" @click="step('draftChildren', 1)" aria-label="Thêm trẻ em">+</button>
                    </div>
                </div>
                <div class="hms-sheet-foot">
                    <button type="button" class="hms-submit" @click="applyGuests()">Áp dụng</button>
                </div>
            </div>
        </div>
    </template>

    {{-- ══════════ Kết quả: chỉ card phòng, khuyến mãi lên đầu ══════════ --}}
    <template x-teleport="body">
        <div class="hms-layer lg:hidden" x-show="isOpen('results')" x-cloak style="background:#f2f3f5;"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-x-4" x-transition:enter-end="opacity-100 translate-x-0">
            <div style="position:relative; background:#fff; box-shadow:0 1px 0 #eef0f3;">
                <div class="hms-res-bar">
                    <button type="button" class="hms-icon-btn" aria-label="Quay lại" @click="pop()">
                        <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <button type="button" class="hms-res-summary" @click="pop()" aria-label="Sửa tìm kiếm">
                        <span class="hms-res-summary-main">
                            <b><span x-text="summaryTitle"></span><template x-if="meta"><span style="font-weight:500; color:#6b7280;" x-text="' · ' + meta.total + ' chỗ ở'"></span></template></b>
                            <small x-text="summaryLine"></small>
                        </span>
                        <svg width="20" height="20" fill="none" stroke="#111827" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z"/></svg>
                    </button>
                </div>
                <div class="hms-chips">
                    <button type="button" class="hms-chip" :class="sortOpen && 'is-on'" @click="sortOpen = !sortOpen">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M7 4v16M7 20l-3-3M7 20l3-3M17 20V4M17 4l-3 3M17 4l3 3"/></svg>
                        <span x-text="sortLabel"></span>
                    </button>
                    <button type="button" class="hms-chip" :class="type === '' && 'is-on'" @click="setType('')">Tất cả</button>
                    <template x-for="t in roomTypes" :key="'t-' + t.slug">
                        <button type="button" class="hms-chip" :class="type === t.slug && 'is-on'" @click="setType(t.slug)" x-text="t.name"></button>
                    </template>
                </div>
                <div class="hms-sort-menu" x-show="sortOpen" x-cloak @click.outside="sortOpen = false" x-transition.opacity>
                    <template x-for="o in sortOptions" :key="'s-' + o.value">
                        <button type="button" :class="sort === o.value && 'is-on'" @click="setSort(o.value)">
                            <span x-text="o.label"></span>
                            <svg x-show="sort === o.value" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        </button>
                    </template>
                </div>
            </div>

            <div class="hms-scroll" id="hms-results-scroll">
                <div class="hms-list">
                    <template x-if="meta && meta.total > 0">
                        <div class="hms-count">
                            <span x-text="meta.total + ' chỗ ở phù hợp'"></span><template x-if="meta.promo_count > 0"><span> · <span style="color:#e11d48; font-weight:700;" x-text="meta.promo_count + ' đang giảm giá'"></span></span></template>
                        </div>
                    </template>

                    <template x-for="(room, index) in results" :key="'r-' + room.id">
                        <div>
                            <template x-if="showPromoHeader(index)">
                                <div class="hms-group">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="#f43f5e"><path d="M12 2c1 3.5-1.5 5-1.5 7.5A2.5 2.5 0 0 0 13 12c1.7 0 2.5-1.3 2.5-3 2 1.6 3.5 4 3.5 6.5A7 7 0 0 1 5 15.5C5 10 10 8 12 2Z"/></svg>
                                    Đang giảm giá
                                </div>
                            </template>
                            <template x-if="showOthersHeader(index)">
                                <div class="hms-group hms-group-plain">Các chỗ ở khác</div>
                            </template>

                            <a :href="roomHref(room)" class="hms-room" :class="room.has_promotion && 'is-promo'">
                                <div class="hms-room-img">
                                    <template x-if="roomImage(room)">
                                        <img :src="roomImage(room)" :alt="room.name" loading="lazy" decoding="async">
                                    </template>
                                    <template x-if="room.price && room.price.discount_percent">
                                        <span class="hms-off" x-text="'-' + room.price.discount_percent + '%'"></span>
                                    </template>
                                    <button type="button" class="room-wishlist-btn" :data-active="room.wishlist_status ? '1' : '0'" aria-label="Yêu thích"
                                            x-html="heart(room)" @click.prevent.stop="toggleWishlist(room, $el)"></button>
                                    <template x-if="room.is_available === false">
                                        <div class="hms-soldout"><span>Tạm hết phòng</span></div>
                                    </template>
                                </div>
                                <div class="hms-room-body">
                                    <span class="hms-room-kind" x-text="[typeName(room), styleLabel(room)].filter(Boolean).join(' · ')"></span>
                                    <p class="hms-room-name" x-text="room.name"></p>
                                    <template x-if="room.rating">
                                        <span class="hms-room-rate">
                                            <svg viewBox="0 0 20 20" fill="currentColor"><path d="M9.05 2.93c.3-.92 1.6-.92 1.9 0l1.07 3.29a1 1 0 0 0 .95.69h3.46c.97 0 1.37 1.24.59 1.81l-2.8 2.03a1 1 0 0 0-.36 1.12l1.07 3.29c.3.92-.76 1.69-1.54 1.12l-2.8-2.03a1 1 0 0 0-1.18 0l-2.8 2.03c-.78.57-1.84-.2-1.54-1.12l1.07-3.29a1 1 0 0 0-.36-1.12L2.98 8.72c-.78-.57-.38-1.81.59-1.81h3.46a1 1 0 0 0 .95-.69l1.07-3.29Z"/></svg>
                                            <b x-text="Number(room.rating).toFixed(1)"></b>
                                        </span>
                                    </template>
                                    <template x-if="room.branch && room.branch.name">
                                        <span class="hms-room-loc">
                                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21Z"/><circle cx="12" cy="9.5" r="2.5"/></svg>
                                            <span x-text="room.branch.name"></span>
                                        </span>
                                    </template>
                                    <template x-if="typeof room.distance === 'number'">
                                        <span class="hms-room-kind" x-text="'Cách bạn ' + room.distance.toFixed(1) + ' km'"></span>
                                    </template>
                                    <div class="hms-room-tags">
                                        <template x-if="room.has_promotion">
                                            <span class="hms-room-tag is-deal">Đang khuyến mãi</span>
                                        </template>
                                        <template x-if="room.is_variant && room.variant_quantity > 1">
                                            <span class="hms-room-tag" x-text="'Còn ' + room.variant_quantity + ' phòng'"></span>
                                        </template>
                                    </div>
                                    <div class="hms-room-price">
                                        <template x-if="room.price">
                                            <div>
                                                <template x-if="room.price.original_amount > room.price.amount">
                                                    <s x-text="vnd(room.price.original_amount)"></s>
                                                </template>
                                                <strong x-text="vnd(room.price.amount)"></strong>
                                                <small x-text="'Giá ' + (room.price.unit_label || '').replace(/^\/\s*/, '/ ')"></small>
                                            </div>
                                        </template>
                                        <template x-if="!room.price">
                                            <strong style="font-size:15px;">Liên hệ</strong>
                                        </template>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </template>

                    {{-- Lần tải đầu: khung xương --}}
                    <template x-if="loadingResults && !results.length">
                        <div>
                            <template x-for="i in 4" :key="'sk-' + i">
                                <div class="hms-room" style="pointer-events:none;">
                                    <div class="hms-room-img hms-skel" style="border-radius:0;"></div>
                                    <div class="hms-room-body" style="gap:8px;">
                                        <div class="hms-skel" style="height:10px; width:40%;"></div>
                                        <div class="hms-skel" style="height:14px; width:85%;"></div>
                                        <div class="hms-skel" style="height:12px; width:60%;"></div>
                                        <div class="hms-skel" style="height:18px; width:45%; margin-top:auto; align-self:flex-end;"></div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    <div id="hms-results-sentinel" style="height:1px;"></div>
                    <div class="hms-spin" x-show="loadingResults && results.length"></div>

                    <template x-if="resultsError">
                        <div class="hms-empty">
                            <p>Không tải được danh sách phòng</p>
                            <small>Vui lòng kiểm tra kết nối và thử lại.</small>
                            <button type="button" class="hms-chip is-on" @click="resultsError = false; fetchResults(!results.length)">Thử lại</button>
                        </div>
                    </template>

                    <template x-if="!loadingResults && !resultsError && meta && meta.total === 0">
                        <div class="hms-empty">
                            <p x-text="'Chưa có phòng phù hợp' + (searched && searched.dest.kind !== 'all' ? ' tại ' + searched.dest.name : '')"></p>
                            <small>Thử đổi ngày, số khách hoặc chọn điểm đến đang có phòng bên dưới.</small>
                            <div class="hms-empty-chips">
                                <template x-for="d in popularDestinations.slice(0, 8)" :key="'e-' + d.kind + d.code">
                                    <button type="button" class="hms-chip" @click="searchPopular(d)">
                                        <span x-text="d.short_name"></span>
                                        <span style="color:#059669;" x-text="d.room_count"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>

                    <template x-if="!loadingResults && !hasMore && results.length > 0">
                        <div class="hms-muted" style="padding:14px;">Đã hiển thị tất cả chỗ ở</div>
                    </template>
                </div>
            </div>
        </div>
    </template>
</section>
