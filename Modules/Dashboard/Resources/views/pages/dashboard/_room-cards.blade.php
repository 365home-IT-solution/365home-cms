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
            {{-- Chuyển đổi cách hiển thị thẻ phòng: danh sách gọn / dải giờ 24h --}}
            <div class="ta-rc-view-toggle" id="ta-rc-view-toggle" role="tablist">
                <button class="ta-rc-view-toggle-btn active" data-view="list" onclick="rcSetView('list')" title="Danh sách gọn">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    Danh sách
                </button>
                <button class="ta-rc-view-toggle-btn" data-view="timeline" onclick="rcSetView('timeline')" title="Dải giờ trong ngày">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    Dải giờ
                </button>
                {{-- <button class="ta-rc-view-toggle-btn" data-view="detail" onclick="rcSetView('detail')" title="Giao diện cũ, đầy đủ chi tiết — để so sánh">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="16" height="16" rx="2" stroke="currentColor" stroke-width="2"/><path d="M4 10h16M9 10v10" stroke="currentColor" stroke-width="2"/></svg>
                    Chi tiết (cũ)
                </button> --}}
            </div>
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
        @php
            // URL menu thao tác nhanh + housekeeping_status/pending_refund đều được gắn sẵn từ
            // Dashboard::getRoomCardsData() (dùng chung cho cả lần render Blade đầu tiên này LẪN
            // JS tự làm mới định kỳ qua /admin/api/room-cards — xem renderRoomCards() trong
            // _scripts.blade.php — để 2 nơi không lệch nhau).
            $rcNeedsCleaning = ($room['housekeeping_status'] ?? 'available') !== 'available';
            $rcRefund        = $room['pending_refund'] ?? null;
            $rcMenuData    = [
                'edit_url'     => $room['edit_url'],
                'timeslot_url' => $room['timeslot_url'],
                'book_url'     => $room['book_url'],
                'room_name'    => $room['room_name'],
                'cleaning'     => $room['housekeeping_status'] ?? 'available',
                'refund'       => $rcRefund,
            ];
        @endphp
        <div class="ta-room-card {{ $room['has_new'] ? 'has-new' : '' }} {{ $room['active_count'] > 0 ? 'has-active' : '' }}"
             data-branch="{{ $room['branch'] }}"
             data-product="{{ $room['product_id'] }}"
             data-styles="{{ $room['styles'] ?? 1 }}"
             data-time="{{ $room['latest_time'] }}">
            <div class="ta-rc-head">
                <div class="ta-rc-info">
                    <div class="ta-rc-name">
                        <span class="ta-rc-name-text">{{ $room['room_name'] }}</span>
                        @if($rcNeedsCleaning)
                        <span class="ta-rc-flag cleaning" title="{{ $room['housekeeping_status'] === 'maintenance' ? 'Đang bảo trì' : 'Cần dọn vệ sinh' }}">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.5 3.5l5 5L9 20H4v-5L15.5 3.5z"/></svg>
                        </span>
                        @endif
                        @if($rcRefund)
                        <span class="ta-rc-flag refund" title="Chờ hoàn tiền: {{ number_format($rcRefund['amount']) }}đ — {{ $rcRefund['buyer_name'] }} (#{{ $rcRefund['order_code'] }})">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10h11a4 4 0 010 8h-2M3 10l4-4M3 10l4 4"/></svg>
                        </span>
                        @endif
                    </div>
                    <div class="ta-rc-branch">{{ $room['branch'] }}</div>
                </div>
                <button type="button" class="ta-rc-menu-btn" title="Thao tác nhanh"
                    onclick="rcOpenRoomMenu(event, '{{ $room['product_id'] }}')"
                    data-room-menu="{{ json_encode($rcMenuData) }}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="5" r="1.6" fill="currentColor"/><circle cx="12" cy="12" r="1.6" fill="currentColor"/><circle cx="12" cy="19" r="1.6" fill="currentColor"/></svg>
                </button>
                <div class="ta-rc-count {{ $room['count'] === 0 ? 'empty' : '' }}">
                    {{ $room['count'] > 0 ? $room['count'].' đơn' : 'Trống' }}
                </div>
            </div>

            {{-- View "Danh sách gọn" — mỗi đơn chỉ còn 2 dòng, không icon/khung phụ --}}
            <div class="ta-rc-orders">
                @forelse($room['orders'] as $order)
                <div class="ta-rc-order-item {{ $order['is_new'] ? 'is-new' : '' }} seg-{{ $order['segment'] }}"
                     data-segment="{{ $order['segment'] }}"
                     data-status="{{ $order['status'] }}"
                     data-order="{{ json_encode(['order_id'=>$order['order_id'],'order_code'=>$order['order_code'],'buyer_name'=>$order['buyer_name'],'buyer_phone'=>$order['buyer_phone'],'checkin'=>$order['checkin'],'checkout'=>$order['checkout'],'status_label'=>$order['status_label'],'status_color'=>$order['status_color'],'amount'=>$order['amount'],'segment'=>$order['segment'],'slot_count'=>$order['slot_count']??null,'slot_labels'=>$order['slot_labels']??'','slot_ranges'=>$order['slot_ranges']??[],'created_at'=>$order['created_at'],'created_at_fmt'=>$order['created_at_fmt']??'','is_new'=>$order['is_new']??false,'deposit_room'=>$order['deposit_room']??'']) }}">
                    <div class="ta-rc-line1">
                        <span class="ta-rc-status-compact" style="background:{{ $order['status_color'] }}1a;color:{{ $order['status_color'] }};">{{ $order['status_label'] }}</span>
                        <span class="ta-rc-guest-compact">{{ $order['buyer_name'] }}</span>
                    </div>
                    <div class="ta-rc-line2">
                        @if(!empty($order['slot_labels']))
                        <span class="ta-rc-time-compact">{{ $order['checkin'] }}, {{ $order['slot_labels'] }}</span>
                        @elseif($order['checkin'])
                        <span class="ta-rc-time-compact">{{ $order['checkin'] }}{{ $order['checkout'] ? ' → '.$order['checkout'] : '' }}</span>
                        @endif
                        <span class="ta-rc-spacer"></span>
                        @if($order['amount'] > 0)
                        <span class="ta-rc-amount-compact">{{ number_format($order['amount']) }}₫</span>
                        @endif
                        <a href="/admin/orders/{{ $order['order_id'] }}/edit" class="ta-rc-detail-compact" title="Xem chi tiết đơn">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </a>
                    </div>
                </div>
                @empty
                <div class="ta-rc-empty">Không có đơn</div>
                @endforelse
                <div class="ta-rc-no-match" style="display:none;">Không có đơn ở chế độ xem này</div>
            </div>

            {{-- View "Dải giờ" — thanh 24h hôm nay + danh sách rút gọn các lượt sắp tới ngày khác --}}
            @php
                $todayStart = $roomCards['today_start_ts'];
                $todayEnd   = $roomCards['today_end_ts'];

                // 1 đơn có thể có NHIỀU khung giờ (vd 8h30-11h20 và 11h50-14h40) — coi là 1 lượt
                // lưu trú LIÊN TỤC của cùng 1 khách, nối liền thành 1 đoạn duy nhất từ khung SỚM
                // NHẤT tới khung MUỘN NHẤT (checkin_ts/checkout_ts đã được RoomCardsService tính
                // đúng theo toàn bộ khung giờ của đơn, không chỉ khung đầu tiên).
                $todayOrders  = [];
                $futureOrders = [];
                foreach ($room['orders'] as $o) {
                    $cin  = $o['checkin_ts']  ?? null;
                    $cout = $o['checkout_ts'] ?? null;
                    if ($cin === null || $cout === null) {
                        continue;
                    }
                    if ($cin <= $todayEnd && $cout >= $todayStart) {
                        $todayOrders[] = $o;
                    } elseif ($cin > $todayEnd) {
                        $futureOrders[] = $o;
                    }
                }
                $nowTs  = now()->timestamp;
                $nowPct = max(0, min(100, ($nowTs - $todayStart) / max(1, $todayEnd - $todayStart) * 100));
            @endphp
            <div class="ta-rc-timeline">
                <div class="ta-rc-tl-track">
                    @foreach($todayOrders as $order)
                        @php
                            $segStart = max($todayStart, $order['checkin_ts']);
                            $segEnd   = min($todayEnd, $order['checkout_ts']);
                            $left     = ($segStart - $todayStart) / max(1, $todayEnd - $todayStart) * 100;
                            $width    = max(1.5, ($segEnd - $segStart) / max(1, $todayEnd - $todayStart) * 100);
                            // Nhãn giờ hiển thị trong tooltip: có nhiều khung giờ thì liệt kê đủ
                            // từng khung (slot_labels), không thì dùng checkin/checkout thường.
                            $segTimeLabel = !empty($order['slot_labels'])
                                ? ($order['checkin'] ? $order['checkin'].', ' : '').$order['slot_labels']
                                : ($order['checkin'].($order['checkout'] ? ' → '.$order['checkout'] : ''));
                        @endphp
                        <a href="/admin/orders/{{ $order['order_id'] }}/edit" class="ta-rc-tl-seg" data-segment="{{ $order['segment'] }}" data-status="{{ $order['status'] }}"
                           data-order="{{ json_encode(['order_id'=>$order['order_id'],'order_code'=>$order['order_code'],'buyer_name'=>$order['buyer_name'],'buyer_phone'=>$order['buyer_phone'],'checkin'=>$segTimeLabel,'checkout'=>null,'status_label'=>$order['status_label'],'status_color'=>$order['status_color'],'amount'=>$order['amount'],'segment'=>$order['segment'],'created_at'=>$order['created_at'],'created_at_fmt'=>$order['created_at_fmt']??'','deposit_room'=>$order['deposit_room']??'']) }}"
                           style="left:{{ $left }}%;width:{{ $width }}%;background:{{ $order['status_color'] }};">
                        </a>
                    @endforeach
                    <div class="ta-rc-tl-now" style="left:{{ $nowPct }}%;"></div>
                </div>
                <div class="ta-rc-tl-scale"><span>00:00</span><span>06:00</span><span>12:00</span><span>18:00</span><span>24:00</span></div>
                @if(empty($todayOrders))
                    <div class="ta-rc-tl-empty">Trống hôm nay</div>
                @endif
                @if(!empty($futureOrders))
                    <div class="ta-rc-tl-future">
                        @foreach($futureOrders as $order)
                        <a href="/admin/orders/{{ $order['order_id'] }}/edit" class="ta-rc-tl-future-item">
                            <span class="ta-rc-dot" style="background:{{ $order['status_color'] }};"></span>{{ $order['checkin'] }} · {{ $order['buyer_name'] }}
                        </a>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- View "Chi tiết (cũ)" — giữ nguyên giao diện đầy đủ trước khi tối giản, để so sánh --}}
            <div class="ta-rc-orders-detail">
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

{{-- Tooltip dùng chung cho mọi đơn (view Danh sách + Dải giờ) — 1 phần tử duy nhất, JS tự định vị
     bằng getBoundingClientRect() nên không bị cắt bởi overflow:hidden của thẻ phòng, và không bị
     tình trạng "chỉ hover được 1 đơn" do nhiều tooltip tuyệt đối chồng lên nhau như trước. --}}
<div class="rc-tooltip" id="rc-tooltip"></div>

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

{{-- Menu thao tác nhanh (⋮) trên mỗi thẻ phòng — panel nhỏ tự định vị cạnh nút bấm bằng
     getBoundingClientRect() (cùng kỹ thuật với tooltip/popup ở trên), nội dung được JS
     (rcOpenRoomMenu, xem _scripts.blade.php) render từ data-room-menu của nút vừa bấm. --}}
<div class="ta-rc-menu-catcher" id="ta-rc-menu-catcher" style="display:none;" onclick="rcCloseRoomMenu()"></div>
<div class="ta-rc-menu-panel" id="ta-rc-menu-panel" style="display:none;"></div>
