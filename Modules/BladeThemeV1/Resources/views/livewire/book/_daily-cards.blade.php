{{-- Phòng THEO NGÀY (styles=2) của chi nhánh đang chọn — bảng khung giờ không hiển thị được kiểu
     phòng này, nên in thành card đơn giản dẫn sang trang chi tiết (chọn ngày nhận/trả ở đó).
     Dữ liệu: Book::buildDailyCards(). Ảnh có width/height + aspect-ratio cố định để không giật layout. --}}
@php $dailyRooms = $category['daily'] ?? []; @endphp
@if (! empty($dailyRooms))
<section class="daily-rooms" aria-labelledby="daily-rooms-{{ $category['id'] }}">
    <div class="daily-rooms__head">
        <h3 id="daily-rooms-{{ $category['id'] }}" class="daily-rooms__title">Phòng theo ngày</h3>
        <span class="daily-rooms__hint">Nhận {{ $dailyRooms[0]['checkin'] }} · trả {{ $dailyRooms[0]['checkout'] }} hôm sau</span>
    </div>
    <div class="daily-rooms__grid">
        @foreach ($dailyRooms as $room)
        <a href="{{ $room['url'] }}" class="daily-card" wire:key="daily-{{ $room['id'] }}">
            <div class="daily-card__media">
                @if ($room['image'])
                <img src="{{ $room['image'] }}" alt="{{ $room['name'] }}" width="480" height="320" loading="lazy" decoding="async">
                @endif
            </div>
            <div class="daily-card__body">
                <p class="daily-card__name">{{ $room['name'] }}</p>
                <p class="daily-card__meta">
                    Nhận {{ $room['checkin'] }} · Trả {{ $room['checkout'] }}@if ($room['max_guests'] > 0) · {{ $room['max_guests'] }} khách @endif
                </p>
                <div class="daily-card__foot">
                    <div>
                        @if ($room['from_price'] < $room['base_price'])
                        <span class="daily-card__old">{{ number_format($room['base_price'], 0, ',', '.') }}đ</span>
                        @endif
                        @if ($room['from_price'] > 0)
                        <span class="daily-card__price"><small>từ</small> {{ number_format($room['from_price'], 0, ',', '.') }}đ<small>/đêm</small></span>
                        @else
                        <span class="daily-card__price">Liên hệ</span>
                        @endif
                    </div>
                </div>
            </div>
        </a>
        @endforeach
    </div>
</section>
@endif
