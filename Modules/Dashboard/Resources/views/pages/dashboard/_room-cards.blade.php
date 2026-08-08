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
                {{-- <button class="ta-rc-view-toggle-btn" data-view="detail" onclick="rcSetView('detail')" title="Giao diện cũ, đầy đủ chi tiết — để so sánh">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="16" height="16" rx="2" stroke="currentColor" stroke-width="2"/><path d="M4 10h16M9 10v10" stroke="currentColor" stroke-width="2"/></svg>
                    Chi tiết (cũ)
                </button> --}}
                {{-- Chế độ "Lịch" — carousel chọn chi nhánh + lưới ngày×khung giờ nhiều phòng cạnh
                     nhau CÙNG NGÔN NGỮ THỊ GIÁC với lịch đặt phòng phía khách (xem
                     home-booking-board.blade.php/book/_desktop-grid.blade.php), nhưng chỉ XEM
                     (bấm ô đã đặt mở thẳng đơn tương ứng — xem rcCalRenderGrid() trong
                     _scripts.blade.php), không cho chọn ô để đặt như bên khách. --}}
                <button class="ta-rc-view-toggle-btn" data-view="calendar" onclick="rcSetView('calendar')" title="Lịch nhiều phòng theo chi nhánh">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="17" rx="2" stroke="currentColor" stroke-width="2"/><path d="M3 9h18M8 3v3M16 3v3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    Lịch
                </button>
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
             data-time="{{ $room['latest_time'] }}"
             data-edit-url="{{ $room['edit_url'] }}"
             onclick="rcCardClick(event, this)">
            <div class="ta-rc-head">
                <div class="ta-rc-info">
                    <div class="ta-rc-name">
                        <span class="ta-rc-name-text">{{ $room['room_name'] }}</span>
                        @if($rcRefund)
                        <span class="ta-rc-flag refund" title="Chờ hoàn tiền: {{ number_format($rcRefund['amount']) }}đ — {{ $rcRefund['buyer_name'] }} (#{{ $rcRefund['order_code'] }})">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10h11a4 4 0 010 8h-2M3 10l4-4M3 10l4 4"/></svg>
                        </span>
                        @endif
                    </div>
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
                     data-order="{{ json_encode(['order_id'=>$order['order_id'],'order_code'=>$order['order_code'],'buyer_name'=>$order['buyer_name'],'buyer_phone'=>$order['buyer_phone'],'checkin'=>$order['checkin'],'checkout'=>$order['checkout'],'status_label'=>$order['status_label'],'status_color'=>$order['status_color'],'amount'=>$order['amount'],'segment'=>$order['segment'],'slot_count'=>$order['slot_count']??null,'slot_labels'=>$order['slot_labels']??'','slot_ranges'=>$order['slot_ranges']??[],'created_at'=>$order['created_at'],'created_at_fmt'=>$order['created_at_fmt']??'','is_new'=>$order['is_new']??false,'deposit_room'=>$order['deposit_room']??'']) }}"
                     onclick="rcOrderItemClick(event, this)">
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

            {{-- View "Chi tiết (cũ)" — giữ nguyên giao diện đầy đủ trước khi tối giản, để so sánh --}}
            <div class="ta-rc-orders-detail">
                @forelse($room['orders'] as $order)
                <div class="ta-rc-order-item {{ $order['is_new'] ? 'is-new' : '' }} seg-{{ $order['segment'] }}"
                     data-segment="{{ $order['segment'] }}"
                     data-status="{{ $order['status'] }}"
                     data-order="{{ json_encode(['order_id'=>$order['order_id'],'order_code'=>$order['order_code'],'buyer_name'=>$order['buyer_name'],'buyer_phone'=>$order['buyer_phone'],'checkin'=>$order['checkin'],'checkout'=>$order['checkout'],'status_label'=>$order['status_label'],'status_color'=>$order['status_color'],'amount'=>$order['amount'],'segment'=>$order['segment'],'slot_count'=>$order['slot_count']??null,'slot_labels'=>$order['slot_labels']??'','slot_ranges'=>$order['slot_ranges']??[],'created_at'=>$order['created_at'],'created_at_fmt'=>$order['created_at_fmt']??'','is_new'=>$order['is_new']??false,'deposit_room'=>$order['deposit_room']??'']) }}"
                     onclick="rcOrderItemClick(event, this)">
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


    {{-- Chế độ "Lịch" — toàn bộ nội dung do JS dựng (rcCalRenderBranches()/rcCalRenderGrid()
         trong _scripts.blade.php) từ ĐÚNG dữ liệu phòng đã có (window.__rcRoomsData, bootstrap từ
         $roomCards ở đây + tự cập nhật mỗi khi renderRoomCards() làm mới định kỳ) — không lặp lại
         vòng lặp Blade riêng để tránh 2 nơi build HTML lệch nhau. --}}
    <div class="ta-rc-cal-view" id="ta-rc-cal-view" style="display:none;">
        {{-- Trước/Sau chi nhánh — hàng RIÊNG phía trên danh sách chi nhánh, 2 nút sát nhau, có chữ
             — CÙNG kiểu với Trước/Sau của carousel phòng (.ta-rc-cal-rooms-nav) bên dưới. --}}
        <div class="ta-rc-cal-branches-nav">
            <button type="button" class="ta-rc-cal-nav-btn" id="ta-rc-cal-prev" onclick="rcCalScrollBranches(-1)" title="Trước">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M15 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Trước
            </button>
            <button type="button" class="ta-rc-cal-nav-btn" id="ta-rc-cal-next" onclick="rcCalScrollBranches(1)" title="Sau">
                Sau
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M9 5l7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
        </div>
        {{-- Danh sách chi nhánh — nút gọn (không bo tròn hẳn như pill), cuộn ngang bằng Trước/Sau
             ở trên — không giới hạn số chi nhánh vì luôn cuộn được. --}}
        <div class="ta-rc-cal-branches">
            <div class="ta-rc-cal-branch-track" id="ta-rc-cal-branch-track"></div>
        </div>

        <div class="ta-rc-cal-legend-row">
            <div class="ta-rc-cal-legend">
                <span class="ta-rc-cal-legend-item"><i class="ta-rc-cal-dot booked"></i> Đã đặt</span>
                <span class="ta-rc-cal-legend-item"><i class="ta-rc-cal-dot pending"></i> Chờ xác nhận</span>
                <span class="ta-rc-cal-legend-item"><i class="ta-rc-cal-dot overdue"></i> Hết giờ</span>
                <span class="ta-rc-cal-legend-item"><i class="ta-rc-cal-dot free"></i> Còn trống</span>
                <span class="ta-rc-cal-legend-item">
                    <svg class="ta-rc-cal-legend-note-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0M3.124 7.5A8.969 8.969 0 0 1 5.292 3m13.416 0a8.969 8.969 0 0 1 2.168 4.5" />
                    </svg>
                    Có ghi chú
                </span>
                <span class="ta-rc-cal-legend-item">
                    <i class="ta-rc-cal-dot discounted"></i>
                    Có giảm giá
                </span>
            </div>
            {{-- Số ngày hiển thị — DÙNG CHUNG cho toàn bộ chi nhánh đang xem (mọi phòng trong
                 carousel đều dùng chung 1 cột "Thời gian"), không phải mỗi phòng 1 giá trị riêng —
                 chỉ 3 mốc 5/10/15 ngày, chọn là áp dụng luôn (không cần bấm thêm nút) — xem
                 rcCalApplyDaysRange()/window._rcCalDays trong _scripts.blade.php. --}}
            <div class="ta-rc-cal-days-control">
                <label for="ta-rc-cal-days-input">Số ngày hiển thị</label>
                <select id="ta-rc-cal-days-input" class="ta-rc-cal-days-input" onchange="rcCalApplyDaysRange()">
                    <option value="5">5 ngày</option>
                    <option value="10" selected>10 ngày</option>
                    <option value="15">15 ngày</option>
                </select>
            </div>
            {{-- Carousel PHÒNG (khác carousel chi nhánh ở trên) — vài phòng/lần, trượt MƯỢT bằng
                 rcCalScrollRooms() (scrollBy() smooth, không còn tính "trang" bằng tay rồi vẽ lại
                 toàn bộ lưới như trước — nặng/giật), tự dựng bằng scroll-snap thuần, cùng kỹ thuật
                 với carousel chi nhánh ở trên. --}}
            <div class="ta-rc-cal-rooms-nav">
                <button type="button" class="ta-rc-cal-nav-btn" id="ta-rc-cal-rooms-prev" onclick="rcCalScrollRooms(-1)" title="Phòng trước">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M15 19l-7-7 7-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Trước
                </button>
                <span class="ta-rc-cal-rooms-page-info" id="ta-rc-cal-rooms-page-info"></span>
                <button type="button" class="ta-rc-cal-nav-btn" id="ta-rc-cal-rooms-next" onclick="rcCalScrollRooms(1)" title="Phòng sau">
                    Sau
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M9 5l7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </div>
        </div>

        <div class="ta-rc-cal-grid-wrap" id="ta-rc-cal-grid-wrap"></div>
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

{{-- Popup thông tin nhanh — bấm 1 ô đã đặt trong view "Lịch" (rcCalOpenOrderPopup() trong
     _scripts.blade.php) mở popup này thay vì điều hướng thẳng sang orderform, gọi
     GET /admin/api/orders/{id}/quick-info để lấy tên khách/SĐT/ghi chú/tổng thanh toán — nút
     "Xem chi tiết đơn" bên trong mới thật sự điều hướng sang orderform. --}}
<div class="ta-rc-order-popup-overlay" id="ta-rc-order-popup" style="display:none;" onclick="if(event.target===this) rcCalCloseOrderPopup()">
    <div class="ta-rc-order-popup-modal">
        <div class="ta-rc-order-popup-head">
            <div class="ta-rc-order-popup-head-left">
                <span class="ta-rc-order-popup-title">Thông tin đơn</span>
                {{-- Badge trạng thái thanh toán + "Có ghi chú" — JS (rcCalOpenOrderPopup()/
                     rcCalSaveOrderNote() trong _scripts.blade.php) tự đổ vào đây, cuối hàng tiêu
                     đề, thay vì nằm trong phần thân popup như trước. --}}
                <span class="ta-rc-order-popup-head-badges" id="ta-rc-order-popup-head-badges"></span>
            </div>
            <button type="button" class="ta-rc-popup-close-btn" onclick="rcCalCloseOrderPopup()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                    <path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
        <div class="ta-rc-order-popup-body" id="ta-rc-order-popup-body"></div>
    </div>
</div>

{{-- Popup "Giá phòng" — bấm nút cùng tên dưới tên phòng ở view "Lịch" (rcCalOpenPricePopup()
     trong _scripts.blade.php), gọi GET /admin/api/rooms/{id}/pricing-info để xem nhanh giảm giá
     full phòng/số khách miễn phí/phụ thu/giảm giá theo số khung giờ + từng khung giờ, giá,
     khuyến mãi đang gắn — CHỈ XEM, sửa vẫn phải qua SettingBook (link cuối popup). --}}
<div class="ta-rc-order-popup-overlay" id="ta-rc-price-popup" style="display:none;" onclick="if(event.target===this) rcCalClosePricePopup()">
    <div class="ta-rc-order-popup-modal" style="max-width:760px;">
        <div class="ta-rc-order-popup-head">
            <span class="ta-rc-order-popup-title" id="ta-rc-price-popup-title">Giá phòng</span>
            <button type="button" class="ta-rc-popup-close-btn" onclick="rcCalClosePricePopup()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                    <path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
        <div class="ta-rc-order-popup-body" id="ta-rc-price-popup-body"></div>
    </div>
</div>

{{-- Popup "Xem phòng" — bấm nút cùng tên dưới tên phòng ở view "Lịch" (rcCalOpenViewPopup() trong
     _scripts.blade.php), gọi GET /admin/api/rooms/{id}/stats-popup lấy tổng doanh thu + số đơn
     thành công (ALL-TIME) + doanh thu 6 tháng gần nhất để so sánh — nút "Xem chi tiết phòng" bên
     trong mới thật sự điều hướng sang trang sửa phòng (dùng lại r.edit_url có sẵn, không gọi API
     riêng cho URL này). --}}
<div class="ta-rc-order-popup-overlay" id="ta-rc-view-popup" style="display:none;" onclick="if(event.target===this) rcCalCloseViewPopup()">
    <div class="ta-rc-order-popup-modal" style="max-width:860px;">
        <div class="ta-rc-order-popup-head">
            <span class="ta-rc-order-popup-title" id="ta-rc-view-popup-title">Thống kê</span>
            <button type="button" class="ta-rc-popup-close-btn" onclick="rcCalCloseViewPopup()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                    <path d="M6 18L18 6M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
        <div class="ta-rc-order-popup-body" id="ta-rc-view-popup-body"></div>
    </div>
</div>
