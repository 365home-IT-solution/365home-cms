<div>

{{-- ===== VIEW MỚI: Phòng theo loại roomtype (carousel) ===== --}}
@if (!empty($searchSections))
    @foreach ($searchSections as $sIndex => $section)
    @php $trackId = 'pt-' . $sIndex; @endphp
    <section style="padding:28px 0 0;">
        <div style="max-width:80rem; margin:0 auto; padding:0 1.25rem;">

            {{-- Header --}}
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <h2 style="font-size:1.15rem; font-weight:800; color:#111827; text-transform:uppercase; letter-spacing:.02em; margin:0;">
                        {{ $section['title'] }}
                    </h2>
                    {{-- <span style="font-size:12px; color:#9ca3af;">({{ $section['count'] }} phòng)</span> --}}
                </div>
                <a href="{{ route('product.search') }}?type={{ $section['slug'] ?? '' }}"
                    style="font-size:13px; font-weight:600; color:#0f766e; text-decoration:none; display:flex; align-items:center; gap:3px; white-space:nowrap; flex-shrink:0;">
                    Xem tất cả
                    <svg style="width:14px;height:14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>

            {{-- Carousel wrapper --}}
            <div style="position:relative;">

                {{-- Nút Prev --}}
                <button onclick="document.getElementById('{{ $trackId }}').scrollBy({left:-880,behavior:'smooth'})"
                    style="position:absolute; left:-14px; top:50%; transform:translateY(-60%); z-index:10; width:34px; height:34px; border-radius:50%; background:#fff; border:1.5px solid #e5e7eb; box-shadow:0 2px 8px rgba(0,0,0,.12); display:flex; align-items:center; justify-content:center; cursor:pointer; color:#374151;">
                    <svg style="width:16px;height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>

                {{-- Nút Next --}}
                <button onclick="document.getElementById('{{ $trackId }}').scrollBy({left:880,behavior:'smooth'})"
                    style="position:absolute; right:-14px; top:50%; transform:translateY(-60%); z-index:10; width:34px; height:34px; border-radius:50%; background:#fff; border:1.5px solid #e5e7eb; box-shadow:0 2px 8px rgba(0,0,0,.12); display:flex; align-items:center; justify-content:center; cursor:pointer; color:#374151;">
                    <svg style="width:16px;height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>

                {{-- Scroll track --}}
                <div id="{{ $trackId }}"
                    style="display:flex; gap:16px; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none; padding-bottom:6px;">
                    <style>#{{ $trackId }}::-webkit-scrollbar{display:none}</style>

                    @foreach ($section['products'] as $room)
                    @php
                        $grandChild = $room->display_grandchild ?? null;
                        $child      = $room->display_child ?? null;

                        $lowestPrice = null;
                        $lowestUnit  = '';
                        if (!empty($room->time_price_pairs)) {
                            foreach (array_map('trim', explode(',', $room->time_price_pairs)) as $pair) {
                                if (strpos($pair, ':') === false) continue;
                                [$tv, $pr] = explode(':', $pair, 2);
                                $tv = floatval($tv);
                                $pr = floatval(str_replace([',','.'], '', trim($pr)));
                                if ($tv == 999 || $tv < 0 || $tv == 0 || $pr <= 0) continue;
                                if ($lowestPrice === null || $pr < $lowestPrice) {
                                    $lowestPrice = $pr;
                                    $hours = floor($tv); $mins = round(($tv - $hours) * 100);
                                    $lowestUnit = $mins > 0 ? "{$hours}h{$mins}" : "{$hours}h";
                                }
                            }
                        }
                    @endphp

                    {{-- Card kiểu Airbnb --}}
                    <a href="{{ route('product.detail', ['slug' => $room->pslug]) }}"
                        style="flex:0 0 220px; scroll-snap-align:start; display:flex; flex-direction:column; gap:9px; text-decoration:none;">

                        {{-- Ảnh --}}
                        <div style="position:relative; padding-top:72%; overflow:hidden; background:#f3f4f6; border-radius:14px; flex-shrink:0;">
                            @if ($room->media_file)
                                <img src="{{ asset('storage/' . $room->media_file) }}"
                                    alt="{{ $room->pname }}"
                                    loading="lazy"
                                    style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover; transition:transform .35s;"
                                    onmouseover="this.style.transform='scale(1.04)'"
                                    onmouseout="this.style.transform='scale(1)'" />
                            @else
                                <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center;">
                                    <svg style="width:2rem;height:2rem;color:#d1d5db;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                            d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                            @endif
                        </div>

                        {{-- Text: 2 hàng --}}
                        <div style="padding:0 2px; display:flex; flex-direction:column; gap:3px;">
                            {{-- Hàng 1: Tên phòng --}}
                            <p style="font-size:13px; font-weight:600; color:#111827; margin:0; overflow:hidden; white-space:nowrap; text-overflow:ellipsis;">
                                {{ $room->pname }}
                            </p>
                            {{-- Hàng 2: Giá + số sao --}}
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:6px;">
                                <p style="font-size:13px; color:#111827; margin:0; white-space:nowrap;">
                                    @if ($lowestPrice)
                                        <span style="font-weight:700;">{{ number_format($lowestPrice) }}đ</span><span style="color:#9ca3af; font-size:11px;"> /{{ $lowestUnit }}</span>
                                    @else
                                        <span style="font-weight:700;">Liên hệ</span>
                                    @endif
                                </p>
                                @if ($room->rating_score > 0)
                                <span style="font-size:12px; color:#374151; white-space:nowrap; display:flex; align-items:center; gap:2px; flex-shrink:0;">
                                    <svg style="width:12px;height:12px;color:#f59e0b;flex-shrink:0;" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                    </svg>
                                    {{ number_format($room->rating_score, 1) }}
                                    @if ($room->ratings_count > 0)
                                        <span style="color:#9ca3af;">({{ $room->ratings_count }})</span>
                                    @endif
                                </span>
                                @endif
                            </div>
                        </div>
                    </a>
                    @endforeach

                </div>{{-- end scroll track --}}
            </div>{{-- end carousel wrapper --}}
        </div>
    </section>
    @endforeach

    <div style="height:28px;"></div>

@else
    <div style="padding:4rem 1rem; text-align:center;">
        <p style="color:#6b7280; font-size:15px;">Chưa có phòng nào.</p>
    </div>
@endif


</div>
