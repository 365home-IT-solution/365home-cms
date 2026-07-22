{{-- DỌN VỆ SINH — hiện ngay đầu Dashboard để kiểm tra được ngay khi vào --}}
@php
    $hkCount = $housekeeping['cleaning_count'] ?? 0;
    $hkRooms = $housekeeping['cleaning_rooms'] ?? [];
    $hkCanView = \Modules\Product\App\Filament\Resources\RoomHousekeepingResource::canViewAny();
    $hkUrl = $hkCanView ? \Modules\Product\App\Filament\Resources\RoomHousekeepingResource::getUrl('index') : null;
@endphp

@if($hkCount > 0)
<div style="display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-radius:12px;background:#fffbeb;border:1.5px solid #fde68a;margin-bottom:18px;">
    <div style="flex-shrink:0;width:34px;height:34px;border-radius:9999px;background:#fef3c7;display:flex;align-items:center;justify-content:center;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
    </div>
    <div style="flex:1;min-width:0;">
        <div style="font-weight:600;font-size:0.92rem;color:#92400e;">
            {{ $hkCount }} phòng đang chờ dọn vệ sinh
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;">
            @foreach(array_slice($hkRooms, 0, 8) as $room)
                <span style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:8px;background:#fff;border:1px solid #fde68a;font-size:0.78rem;color:#78350f;">
                    <strong>{{ $room['name'] }}</strong>
                    <span style="color:#b45309;">· {{ $room['branch'] }}</span>
                    @if($room['since'])
                        <span style="color:#a16207;">· {{ $room['since'] }}</span>
                    @endif
                </span>
            @endforeach
            @if($hkCount > 8)
                <span style="align-self:center;font-size:0.78rem;color:#92400e;">+{{ $hkCount - 8 }} phòng khác</span>
            @endif
        </div>
    </div>
    @if($hkUrl)
        <a href="{{ $hkUrl }}" style="flex-shrink:0;white-space:nowrap;padding:7px 14px;border-radius:8px;background:#d97706;color:#fff;font-size:0.8rem;font-weight:600;text-decoration:none;">
            Xem &amp; xác nhận
        </a>
    @endif
</div>
@elseif($hkCanView)
<div style="display:flex;align-items:center;gap:8px;padding:8px 16px;border-radius:10px;background:#f0fdf4;border:1px solid #bbf7d0;margin-bottom:18px;font-size:0.82rem;color:#166534;">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
    Tất cả phòng đã dọn xong, sẵn sàng nhận đặt mới.
</div>
@endif
