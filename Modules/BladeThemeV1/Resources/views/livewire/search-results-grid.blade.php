<div>

{{-- Sticky header --}}
<div style="padding:12px 14px 10px; margin-top:30px; border-bottom:1px solid #f3f4f6; background:#fff; position:sticky; top:0; z-index:5;">
    @if ($filterProvinceName)
        <p style="margin:0; font-size:14px; font-weight:700; color:#111827;">
            {{ $totalFound }} chi nhánh tại {{ $filterProvinceName }}
        </p>
    @elseif ($totalFound > 0)
        <p style="margin:0; font-size:14px; font-weight:700; color:#111827;">
            {{ $totalFound }} chi nhánh trên toàn quốc
        </p>
    @else
        <p style="margin:0; font-size:14px; color:#6b7280;">Đang tải kết quả...</p>
    @endif

    {{-- Filter chips --}}
    @php
        $buoiLabel = $filterBuoi === '1' ? 'Theo giờ' : ($filterBuoi === '2' ? 'Theo ngày' : '');
    @endphp
    @if ($filterTypeName || $buoiLabel || $filterCheckIn)
    <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:7px;">
        @if ($buoiLabel)
        <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:#0f766e;background:#f0fdf4;border:1px solid #bbf7d0;padding:3px 10px;border-radius:99px;">
            {{ $buoiLabel }}
        </span>
        @endif
        @if ($filterTypeName)
        <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:#0f766e;background:#f0fdf4;border:1px solid #bbf7d0;padding:3px 10px;border-radius:99px;">
            {{ $filterTypeName }}
        </span>
        @endif
        @if ($filterCheckIn)
        <span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:#6b7280;background:#f9fafb;border:1px solid #e5e7eb;padding:3px 10px;border-radius:99px;">
            {{ $filterCheckIn }}{{ $filterCheckOut ? ' → ' . $filterCheckOut : '' }}
        </span>
        @endif
    </div>
    @endif
</div>

{{-- Danh sách theo nhóm tỉnh --}}
<div style="padding:10px 10px 32px;">

    @forelse ($groups as $group)

        {{-- Tiêu đề tỉnh — chỉ hiện khi không filter 1 tỉnh cụ thể --}}
        @if (!$filterLocation)
        <div style="padding:{{ $loop->first ? '4px' : '14px' }} 4px 8px;">
            <a href="{{ route('product.search') }}?location={{ $group['province_slug'] }}{{ $filterType ? '&type=' . $filterType : '' }}"
                style="display:inline-flex;align-items:center;gap:5px;text-decoration:none;">
                <svg style="width:14px;height:14px;color:#0f766e;flex-shrink:0;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span style="font-size:14px;font-weight:700;color:#111827;">{{ $group['province_name'] }}</span>
                <span style="font-size:12px;font-weight:500;color:#9ca3af;">({{ count($group['branches']) }})</span>
            </a>
        </div>
        @endif

        {{-- 2 cột chi nhánh --}}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:4px;">
            @foreach ($group['branches'] as $branch)
            <div class="branch-card"
                data-lat="{{ $branch['lat'] }}"
                data-lng="{{ $branch['lng'] }}"
                data-name="{{ e($branch['name']) }}"
                data-address="{{ e($branch['address']) }}"
                data-image="{{ $branch['image'] ?? '' }}"
                data-room-count="{{ $branch['room_count'] }}"
                data-booking-url="{{ $branch['booking_url'] }}"
                style="background:#fff;border-radius:14px;border:1.5px solid #f3f4f6;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);cursor:pointer;transition:border-color .15s,box-shadow .15s;"
                onmouseover="this.style.borderColor='#0f766e';this.style.boxShadow='0 3px 12px rgba(0,0,0,.1)'"
                onmouseout="this.style.borderColor='#f3f4f6';this.style.boxShadow='0 1px 3px rgba(0,0,0,.06)'">

                {{-- Ảnh --}}
                <div style="position:relative;padding-top:65%;overflow:hidden;background:#f3f4f6;">
                    @if ($branch['image'])
                        <img src="{{ $branch['image'] }}" alt="{{ $branch['name'] }}" loading="lazy"
                            style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;transition:transform .3s;"
                            onmouseover="this.style.transform='scale(1.05)'"
                            onmouseout="this.style.transform='scale(1)'" />
                    @else
                        <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;">
                            <svg style="width:28px;height:28px;color:#d1d5db;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                            </svg>
                        </div>
                    @endif
                    <div style="position:absolute;top:7px;right:7px;background:rgba(0,0,0,.5);color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;backdrop-filter:blur(4px);">
                        {{ $branch['room_count'] }} phòng
                    </div>
                </div>

                {{-- Tên --}}
                <div style="padding:9px 10px 10px;">
                    <p style="font-size:13px;font-weight:700;color:#111827;margin:0;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.4;">
                        {{ $branch['name'] }}
                    </p>
                    @if ($branch['address'])
                    <p style="font-size:11px;color:#9ca3af;margin:4px 0 0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;">
                        {{ $branch['address'] }}
                    </p>
                    @endif
                    <a class="branch-booking-btn" href="{{ $branch['booking_url'] }}"
                        onclick="event.stopPropagation()"
                        style="display:block;margin-top:9px;background:#0f766e;color:#fff;font-size:12px;font-weight:800;text-align:center;padding:8px 10px;border-radius:8px;text-decoration:none;letter-spacing:.01em;">
                        Đặt phòng →
                    </a>
                </div>
            </div>
            @endforeach
        </div>

    @empty
        <div style="padding:3rem 1rem;text-align:center;">
            <svg style="width:48px;height:48px;color:#d1d5db;margin:0 auto 12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p style="color:#6b7280;font-size:14px;margin:0;">Không tìm thấy chi nhánh nào phù hợp.</p>
        </div>
    @endforelse

</div>

{{-- Branch data cho map JS --}}
<script type="application/json" id="branch-map-data">@json($branches)</script>

</div>
