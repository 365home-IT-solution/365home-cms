@php
    /** @var \Modules\VehicleRental\App\Models\VehicleBooking $booking */
    $booking = $getRecord();
    $gps = app(\Modules\VehicleRental\App\Services\VehicleGpsService::class);
    $summary = $gps->bookingSummary($booking);
    $track = $gps->track($booking);
    $tracker = $gps->trackerFor($booking->vehicle_id);
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', '.');

    $marker = $tracker ? \Modules\Motrak\App\Support\MotrakTransformer::marker($tracker) : null;

    // Đường đi: tối đa ~1500 điểm cho bản đồ (lấy mẫu đều, luôn giữ điểm đầu và điểm cuối).
    $points = $track['points'] ?? [];
    if (count($points) > 1500) {
        $step = (int) ceil(count($points) / 1500);
        $last = end($points);
        $points = array_values(array_filter($points, fn ($p, $i) => $i % $step === 0, ARRAY_FILTER_USE_BOTH));
        $points[] = $last;
    }
    $polyline = array_map(fn ($p) => [$p['latitude'], $p['longitude']], $points);
    $cmp = $summary['km_comparison'] ?? null;
@endphp

@if (! ($summary['linked'] ?? false))
    <p class="text-sm text-gray-500 dark:text-gray-400">Xe chưa được ghép với thiết bị GPS.</p>
@else
    <div class="space-y-4">
        @if (! empty($summary['pickup_warnings']))
            <div class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-200">
                <b>Lưu ý GPS trước khi giao xe:</b> {{ implode(' ', $summary['pickup_warnings']) }}
            </div>
        @endif

        @if (($summary['open_alerts'] ?? 0) > 0)
            <div class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm text-danger-800 dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-200">
                Có <b>{{ $summary['open_alerts'] }}</b> cảnh báo GPS chưa xử lý cho đơn này (menu Thuê xe → Cảnh báo GPS).
            </div>
        @endif

        @php($cur = $summary['current'])
        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm md:grid-cols-4">
            <div><dt class="text-gray-500 dark:text-gray-400">Thiết bị GPS</dt><dd>{{ $cur['tracker']['plate'] }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Kết nối</dt><dd>{{ $cur['connection_label'] }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Tốc độ</dt><dd>{{ $cur['speed_kmh'] !== null ? $cur['speed_kmh'] . ' km/h' : '—' }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Giờ GPS</dt><dd>{{ $cur['gps_time'] ? \Carbon\Carbon::parse($cur['gps_time'])->format('H:i:s d/m/Y') : 'Không có dữ liệu' }}</dd></div>
            <div class="md:col-span-2"><dt class="text-gray-500 dark:text-gray-400">Vị trí hiện tại</dt><dd>{{ $cur['address'] ?: '—' }}@if ($cur['administrative_area']) ({{ $cur['administrative_area'] }})@endif
                @if ($cur['position']) · <a class="text-primary-600 hover:underline dark:text-primary-400" href="{{ $cur['position']['google_maps_url'] }}" target="_blank" rel="noopener noreferrer">Google Maps</a>@endif</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Cách chi nhánh</dt><dd>{{ $cur['distance_to_branch_m'] !== null ? number_format($cur['distance_to_branch_m']) . ' m' : '—' }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">Km tích luỹ GPS</dt><dd>{{ $money($cur['kmage']) }} km</dd></div>
        </dl>

        @if ($cmp)
            <div class="rounded-lg p-3 text-sm {{ $cmp['flagged'] ? 'border border-warning-300 bg-warning-50 text-warning-800 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-200' : 'bg-gray-50 dark:bg-white/5' }}">
                <b>Đối chiếu km:</b> GPS {{ $money($cmp['gps_km']) }} km · Odometer {{ number_format($cmp['odometer_km']) }} km · lệch {{ $money($cmp['discrepancy_km']) }} km ({{ $money($cmp['discrepancy_pct']) }}%)
                @if (! $cmp['reliable']) — <i>chưa đủ tin cậy (dữ liệu GPS lúc giao/nhận quá cũ hoặc bất thường), không dùng để cảnh báo</i>@elseif ($cmp['flagged']) — <b>lệch vượt ngưỡng, nên kiểm tra lại số km ghi nhận</b>@endif
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Số km tính tiền vẫn theo odometer ghi trong biên bản; km GPS chỉ để đối chiếu.</div>
            </div>
        @endif

        @if ($track['available'] ?? false)
            @php($s = $track['summary'])
            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm md:grid-cols-4">
                <div><dt class="text-gray-500 dark:text-gray-400">Số điểm GPS đã lưu</dt><dd>{{ number_format($s['point_count']) }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Quãng đường theo các điểm</dt><dd>{{ $money($s['path_distance_km']) }} km</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Tốc độ cao nhất</dt><dd>{{ $s['max_speed_kmh'] !== null ? $s['max_speed_kmh'] . ' km/h' : '—' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Khu vực đã đi qua</dt><dd>{{ $s['areas'] ? implode(', ', $s['areas']) : '—' }}</dd></div>
            </dl>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $track['reason'] ?? '' }}</p>
        @endif

        @if ($marker || $polyline)
            @include('motrak::partials.map', ['markers' => array_filter([$marker]), 'polyline' => $polyline, 'height' => 440])
        @endif

        <p class="text-xs text-gray-500 dark:text-gray-400">{{ \Modules\VehicleRental\App\Services\VehicleGpsService::COVERAGE_NOTE }} Chỉ nhân viên thấy; khách không thấy dữ liệu GPS.</p>
    </div>
@endif
