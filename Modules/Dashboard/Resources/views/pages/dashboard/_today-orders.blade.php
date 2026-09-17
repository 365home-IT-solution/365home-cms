{{-- TODAY CREATED ORDERS --}}
<div class="ta-tod-section">

    <div class="ta-room-head">
        <div>
            <div class="ta-sub-label">— Đơn đặt · {{ \Carbon\Carbon::today()->format('d/m/Y') }}</div>
            <div class="ta-panel-title">Đơn tạo hôm nay</div>
        </div>
        <span class="ta-room-badge">
            <span id="ta-tod-count">{{ $todayCreatedOrders['total'] }}</span> đơn
        </span>
    </div>

    @if(empty($todayCreatedOrders['rooms']))
    <div class="ta-tod-empty-state">Chưa có đơn nào được tạo hôm nay</div>
    @else
    <div class="ta-tod-grid">
        @foreach($todayCreatedOrders['rooms'] as $room)
        @php
            $hasNewPending = collect($room['bookings'])->contains(fn($b) => $b['is_new'] && $b['status'] === 'pending');
            $hasPaid       = collect($room['bookings'])->contains(fn($b) => in_array($b['status'], ['paid', 'deposit', 'completed', 'shipping']));
            $roomEmoji     = $hasNewPending ? '🔥' : ($hasPaid ? '✅' : '📋');
        @endphp
        <div class="ta-tod-room {{ $hasNewPending ? 'has-new' : '' }} {{ $hasPaid ? 'has-paid' : '' }}">
            <div class="ta-tod-room-hdr">
                <span class="ta-tod-room-emoji">{{ $roomEmoji }}</span>
                <span class="ta-tod-room-name">{{ strtoupper($room['room_name']) }}</span>
                @if(count($room['bookings']) > 1)
                <span class="ta-tod-bk-cnt">{{ count($room['bookings']) }}</span>
                @endif
            </div>

            <div class="ta-tod-bookings">
                @foreach($room['bookings'] as $bk)
                <div class="ta-tod-bk {{ $bk['status'] === 'pending' ? 'bk-pending' : 'bk-ok' }} {{ $bk['is_new'] ? 'bk-new' : '' }}">
                    <span class="ta-tod-bk-em">{{ $bk['emoji'] }}</span>
                    <span class="ta-tod-bk-body">
                        <span class="ta-tod-bk-name">{{ $bk['buyer_name'] }}</span>
                        @if($bk['time_label'])
                        <span class="ta-tod-bk-time">{{ $bk['time_label'] }}</span>
                        @endif
                        @if($bk['slot_count'] || $bk['is_overnight'])
                        <span class="ta-tod-bk-notes">
                            @if($bk['slot_count'])<span class="ta-tod-note">({{ $bk['slot_count'] }} khung)</span>@endif
                            @if($bk['is_overnight'])<span class="ta-tod-note ta-tod-note-night">Qua đêm</span>@endif
                        </span>
                        @endif
                    </span>
                    <a href="/home-admin/orders/{{ $bk['order_id'] }}/edit" class="ta-rc-btn-detail" title="#{{ $bk['order_code'] }}">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none">
                            <path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" stroke="currentColor" stroke-width="2"/>
                            <path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </a>
                </div>
                @endforeach
            </div>
        </div>
        @endforeach
    </div>
    @endif

</div>
