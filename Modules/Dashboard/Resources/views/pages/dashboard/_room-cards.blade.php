{{-- ROOM CARDS --}}
<div class="ta-room-section" id="ta-room-section">

    {{-- Header --}}
    <div class="ta-room-head">
        <div>
            <div class="ta-sub-label">— Tình trạng phòng · {{ \Carbon\Carbon::today()->format('d/m/Y') }}</div>
            <div class="ta-panel-title">Lịch phòng</div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span class="ta-room-pulse" id="ta-rc-pulse" style="display:none;">
                <span class="ta-room-pulse-dot"></span> Đang cập nhật
            </span>
            <span class="ta-room-badge">
                <span id="ta-rc-stat-rooms">{{ $roomCards['total_rooms'] }}</span> phòng
            </span>
            <button class="ta-rc-seg-btn seg-now" id="ta-rc-btn-now" onclick="rcOpenNowPopup()" {{ ($roomCards['total_active'] + $roomCards['total_today']) > 0 ? '' : 'style="display:none;"' }}>
                <span class="ta-rct-dot" style="background:#fff;opacity:.85"></span>
                <span id="ta-rc-cnt-active">{{ $roomCards['total_active'] }}</span> đang ở
                <span style="opacity:.45;margin:0 1px">·</span>
                <span id="ta-rc-cnt-today">{{ $roomCards['total_today'] }}</span> hôm nay
            </button>
            <button class="ta-rc-view-sel-btn" id="ta-rc-view-btn" onclick="rcOpenSelectedPopup()" style="display:none;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none">
                    <path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" stroke="currentColor" stroke-width="2"/>
                    <path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" stroke="currentColor" stroke-width="2"/>
                </svg>
                Xem đơn · <span id="ta-rc-sel-count">0</span>
            </button>
        </div>
    </div>

    {{-- Time filter tabs --}}
    <div class="ta-rc-time-tabs" id="ta-rc-time-tabs">
        <button class="ta-rc-time-tab active" data-time="all" onclick="rcSwitchTime(this)">
            Tất cả
            <span class="ta-rc-tab-badge" id="ta-rct-badge-all">{{ $roomCards['total_orders'] > 0 ? $roomCards['total_orders'] : '' }}</span>
        </button>
        <button class="ta-rc-time-tab seg-active" data-time="active" onclick="rcSwitchTime(this)">
            <span class="ta-rct-dot active-dot"></span> Đang ở
            <span class="ta-rc-tab-badge" id="ta-rct-badge-active">{{ $roomCards['total_active'] > 0 ? $roomCards['total_active'] : '' }}</span>
        </button>
        <button class="ta-rc-time-tab seg-today" data-time="today" onclick="rcSwitchTime(this)">
            <span class="ta-rct-dot today-dot"></span> Hôm nay
            <span class="ta-rc-tab-badge" id="ta-rct-badge-today">{{ $roomCards['total_today'] > 0 ? $roomCards['total_today'] : '' }}</span>
        </button>
        <button class="ta-rc-time-tab seg-upcoming" data-time="upcoming" onclick="rcSwitchTime(this)">
            <span class="ta-rct-dot upcoming-dot"></span> Sắp tới
            <span class="ta-rc-tab-badge" id="ta-rct-badge-upcoming">{{ $roomCards['total_upcoming'] > 0 ? $roomCards['total_upcoming'] : '' }}</span>
        </button>
        <button class="ta-rc-time-tab seg-overdue" data-time="overdue" onclick="rcSwitchTime(this)">
            <span class="ta-rct-dot overdue-dot"></span> Quá hạn
            <span class="ta-rc-tab-badge" id="ta-rct-badge-overdue">{{ $roomCards['total_overdue'] > 0 ? $roomCards['total_overdue'] : '' }}</span>
        </button>
        <button class="ta-rc-time-tab seg-deposit" data-time="deposit" onclick="rcSwitchTime(this)">
            <span class="ta-rct-dot" style="background:#d97757;"></span> Đặt cọc
            <span class="ta-rc-tab-badge" id="ta-rct-badge-deposit"></span>
        </button>
    </div>

    {{-- Branch tabs --}}
    <div class="ta-rc-tabs-wrap" id="ta-rc-tabs">
        <button class="ta-rc-tab active" data-branch="__all__" onclick="rcSwitchTab(this)">
            Tất cả
            <span class="ta-rc-tab-badge" id="ta-rctab-badge-all">{{ $roomCards['total_orders'] > 0 ? $roomCards['total_orders'] : '' }}</span>
        </button>
        @foreach($roomCards['branches'] as $branch)
        <button class="ta-rc-tab" data-branch="{{ $branch['name'] }}" onclick="rcSwitchTab(this)">
            {{ $branch['name'] }}
            @if($branch['new_count'] > 0)
            <span class="ta-rc-tab-badge new">{{ $branch['new_count'] }}</span>
            @elseif($branch['order_count'] > 0)
            <span class="ta-rc-tab-badge">{{ $branch['order_count'] }}</span>
            @endif
        </button>
        @endforeach
    </div>

    {{-- Room grid --}}
    <div class="ta-room-grid" id="ta-room-grid">
        @foreach($roomCards['rooms'] as $room)
        <div class="ta-room-card {{ $room['has_new'] ? 'has-new' : '' }} {{ $room['active_count'] > 0 ? 'has-active' : '' }}"
             data-branch="{{ $room['branch'] }}"
             data-product="{{ $room['product_id'] }}"
             data-styles="{{ $room['styles'] ?? 1 }}"
             data-time="{{ $room['latest_time'] }}">
            <div class="ta-rc-head">
                <div class="ta-rc-icon {{ $room['active_count'] > 0 ? 'active' : '' }}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                        <path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="ta-rc-info">
                    <div class="ta-rc-name">
                        @if($room['has_new'])<span class="ta-rc-new-dot"></span>@endif
                        {{ $room['room_name'] }}
                    </div>
                    <div class="ta-rc-branch">{{ $room['branch'] }}</div>
                </div>
                <div class="ta-rc-count {{ $room['count'] === 0 ? 'empty' : '' }}">
                    {{ $room['count'] > 0 ? $room['count'].' đơn' : 'Trống' }}
                </div>
            </div>

            <div class="ta-rc-orders">
                @forelse($room['orders'] as $order)
                <div class="ta-rc-order-item {{ $order['is_new'] ? 'is-new' : '' }} seg-{{ $order['segment'] }}"
                     data-segment="{{ $order['segment'] }}"
                     data-status="{{ $order['status'] }}"
                     data-order="{{ json_encode(['order_id'=>$order['order_id'],'order_code'=>$order['order_code'],'buyer_name'=>$order['buyer_name'],'buyer_phone'=>$order['buyer_phone'],'checkin'=>$order['checkin'],'checkout'=>$order['checkout'],'status_label'=>$order['status_label'],'status_color'=>$order['status_color'],'amount'=>$order['amount'],'segment'=>$order['segment'],'slot_count'=>$order['slot_count']??null,'slot_labels'=>$order['slot_labels']??'','slot_ranges'=>$order['slot_ranges']??[],'created_at'=>$order['created_at'],'created_at_fmt'=>$order['created_at_fmt']??'','is_new'=>$order['is_new']??false,'deposit_room'=>$order['deposit_room']??'']) }}">
                    <div class="ta-rc-order-top">
                        <div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;">
                            <label class="ta-rc-check-wrap"><input type="checkbox" class="ta-rc-checkbox" onchange="rcToggleOrder(this)"><span class="ta-rc-check-box"></span></label>
                            <span class="ta-rc-code">#{{ $order['order_code'] }}</span>
                            @if($order['is_new'])<span class="ta-rc-new-badge">Mới</span>@endif
                            <span class="ta-seg-badge {{ $order['segment'] }}">
                                @if($order['segment']==='active')Đang ở
                                @elseif($order['segment']==='today')Hôm nay
                                @elseif($order['segment']==='overdue')Quá hạn
                                @else{{ $order['checkin'] }}
                                @endif
                            </span>
                        </div>
                        <span class="ta-rc-status-pill"
                              style="background:{{ $order['status_color'] }}22;color:{{ $order['status_color'] }};border-color:{{ $order['status_color'] }}44;">
                            {{ $order['status_label'] }}
                        </span>
                    </div>
                    <div class="ta-rc-order-bot">
                        <span class="ta-rc-guest">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" style="display:inline;vertical-align:middle;margin-right:3px;">
                                <path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>{{ $order['buyer_name'] }}@if($order['buyer_phone']) · {{ $order['buyer_phone'] }}@endif
                        </span>
                        @if($order['checkin'])
                        <span class="ta-rc-time">
                            <svg width="9" height="9" viewBox="0 0 24 24" fill="none" style="display:inline;vertical-align:middle;margin-right:2px;">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                                <path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>{{ $order['checkin'] }}{{ $order['checkout'] ? ' → '.$order['checkout'] : '' }}
                        </span>
                        @endif
                    </div>
                    @if(!empty($order['slot_count']))
                    <div class="ta-rc-slot-row">
                        <span class="ta-rc-slot-badge">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" style="display:inline;vertical-align:middle;margin-right:3px;"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            {{ $order['slot_count'] }} khung giờ
                        </span>
                        @if(!empty($order['slot_labels']))
                        <span class="ta-rc-slot-labels">{{ $order['slot_labels'] }}</span>
                        @endif
                    </div>
                    @endif
                    <div class="ta-rc-order-footer">
                        @if($order['amount'] > 0)
                        <span class="ta-rc-amount">{{ number_format($order['amount']) }}₫</span>
                        @else<span></span>@endif
                        <div style="display:flex;align-items:center;gap:5px;">
                            <span class="ta-rc-ago">{{ $order['created_at'] }}</span>
                            <a href="/admin/orders/{{ $order['order_id'] }}/edit" class="ta-rc-btn-detail">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none">
                                    <path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" stroke="currentColor" stroke-width="2"/>
                                    <path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" stroke="currentColor" stroke-width="2"/>
                                </svg>Chi tiết
                            </a>
                        </div>
                    </div>
                </div>
                @empty
                <div class="ta-rc-empty">Không có đơn</div>
                @endforelse
                <div class="ta-rc-no-match" style="display:none;">Không có đơn ở chế độ xem này</div>
            </div>
        </div>
        @endforeach
    </div>
</div>

{{-- Selected Orders Popup --}}
<div class="ta-rc-popup-overlay" id="ta-rc-popup" style="display:none;" onclick="if(event.target===this)rcCloseSelectedPopup()">
    <div class="ta-rc-popup-modal">
        <div class="ta-rc-popup-head">
            <div>
                <div class="ta-rc-popup-title" id="ta-rc-popup-title">Đơn đã chọn</div>
                <div class="ta-rc-popup-sub" id="ta-rc-popup-sub"></div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <button class="ta-rc-popup-clear" onclick="rcClearSelections()">Bỏ chọn tất cả</button>
                <button class="ta-rc-popup-close-btn" onclick="rcCloseSelectedPopup()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                        <path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
        </div>
        <div class="ta-rc-popup-body" id="ta-rc-popup-body"></div>
    </div>
</div>
