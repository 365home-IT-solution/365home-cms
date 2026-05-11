@php
    $rrColors       = ['#10b981','#3b82f6','#8b5cf6','#f59e0b','#ef4444','#06b6d4','#f97316','#84cc16','#ec4899','#6366f1'];
    $rrRooms        = $roomRevenue['rooms'] ?? [];
    $rrTotal        = $roomRevenue['total'] ?? 0;
    $rrYear         = $roomRevenue['year'] ?? date('Y');
    $mrMonths       = $monthlyRevenue['months'] ?? array_fill(0, 12, 0);
    $mrYear         = $monthlyRevenue['year'] ?? date('Y');
    $mrTotal        = array_sum($mrMonths);
    $availableYears = $roomRevenue['available_years'] ?? [$rrYear];
@endphp

<div class="ta-bottom-grid" wire:ignore>

    {{-- ── Col 1: Room Revenue Overview ──────────────────────── --}}
    <div class="ta-bg-card" id="ta-rr-card">
        <div class="ta-bg-card-head">
            <div>
                <div class="ta-sub-label">— 02 / Doanh thu phòng</div>
                <div class="ta-panel-title">Xếp hạng phòng</div>
            </div>
            <div class="ta-yr-picker" id="ta-rr-yr" data-selected="{{ $rrYear }}">
                <button class="ta-yr-btn" type="button">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" class="ta-yr-ico"><rect x="3" y="4" width="18" height="18" rx="2.5" stroke="currentColor" stroke-width="2"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    <span class="ta-yr-val">{{ $rrYear }}</span>
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" class="ta-yr-chev"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <div class="ta-yr-drop" role="listbox">
                    @foreach($availableYears as $y)
                    <div class="ta-yr-opt{{ $y == $rrYear ? ' active' : '' }}" role="option" data-year="{{ $y }}">{{ $y }}</div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="ta-rr-body">
            {{-- Table --}}
            <div class="ta-rr-table-wrap">
                <div class="ta-rr-th">
                    <div>Phòng</div>
                    <div class="ta-rr-th-r">Đơn</div>
                    <div class="ta-rr-th-r">Doanh thu</div>
                </div>
                <div id="ta-rr-tbody">
                    @forelse($rrRooms as $i => $room)
                    <div class="ta-rr-tr" data-idx="{{ $i }}" data-name="{{ e($room['name']) }}">
                        <div class="ta-rr-svc">
                            <span class="ta-rr-dot" style="background:{{ $rrColors[$i % count($rrColors)] }};"></span>
                            <span class="ta-rr-name">{{ $room['name'] }}</span>
                            <span class="ta-rr-pct">{{ $room['pct'] }}%</span>
                        </div>
                        <div class="ta-rr-num">{{ $room['order_count'] }}</div>
                        <div class="ta-rr-num ta-rr-bold">{{ number_format($room['revenue']) }}đ</div>
                    </div>
                    @empty
                    <div class="ta-rr-empty">Chưa có dữ liệu doanh thu năm {{ $rrYear }}</div>
                    @endforelse
                </div>
            </div>

            {{-- Donut + Summary --}}
            <div class="ta-rr-right">
                <div class="ta-rr-chart-wrap">
                    <div id="ta-rr-donut"></div>
                    <div class="ta-rr-center" id="ta-rr-center">
                        <div class="ta-rr-center-label" id="ta-rr-center-label">
                            {{ !empty($rrRooms) ? mb_substr($rrRooms[0]['name'], 0, 14) : '—' }}
                        </div>
                        <div class="ta-rr-center-val" id="ta-rr-center-val">
                            @if(!empty($rrRooms))
                                {{ number_format($rrRooms[0]['revenue']) }}đ
                            @else
                                —
                            @endif
                        </div>
                    </div>
                </div>

                <div class="ta-rr-summary">
                    <div class="ta-rr-sum-row">
                        <span class="ta-rr-sum-lbl">Tổng năm</span>
                        <span class="ta-rr-sum-val" id="ta-rr-sum-total">{{ number_format($rrTotal) }}đ</span>
                    </div>
                    <div class="ta-rr-sum-row ta-rr-sum-best" id="ta-rr-sum-best-row" style="{{ empty($rrRooms) ? 'display:none' : '' }}">
                        <span class="ta-rr-sum-lbl">Cao nhất</span>
                        <span class="ta-rr-sum-val" id="ta-rr-sum-best">{{ !empty($rrRooms) ? mb_substr($rrRooms[0]['name'], 0, 20) : '' }}</span>
                    </div>
                    <div class="ta-rr-sum-row ta-rr-sum-worst" id="ta-rr-sum-worst-row" style="{{ count($rrRooms) < 2 ? 'display:none' : '' }}">
                        <span class="ta-rr-sum-lbl">Thấp nhất</span>
                        <span class="ta-rr-sum-val" id="ta-rr-sum-worst">{{ count($rrRooms) > 1 ? mb_substr($rrRooms[count($rrRooms)-1]['name'], 0, 20) : '' }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Col 2: Top Customers ────────────────────────────────── --}}
    <div class="ta-bg-card" id="ta-cust-card">
        <div class="ta-bg-card-head">
            <div>
                <div class="ta-sub-label">— 03 / Khách hàng</div>
                <div class="ta-panel-title">Top khách đặt phòng nhiều nhất</div>
            </div>
            <div class="ta-yr-picker" id="ta-cust-yr" data-selected="{{ $rrYear }}">
                <button class="ta-yr-btn" type="button">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" class="ta-yr-ico"><rect x="3" y="4" width="18" height="18" rx="2.5" stroke="currentColor" stroke-width="2"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    <span class="ta-yr-val">{{ $rrYear }}</span>
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" class="ta-yr-chev"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <div class="ta-yr-drop" role="listbox">
                    @foreach($availableYears as $y)
                    <div class="ta-yr-opt{{ $y == $rrYear ? ' active' : '' }}" role="option" data-year="{{ $y }}">{{ $y }}</div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Filter toolbar --}}
        <div class="ta-cust-toolbar">
            <div class="ta-cust-filter-group">
                <span class="ta-cust-filter-lbl">Sắp xếp:</span>
                <button class="ta-cust-filter-btn active" data-cust-sort="count">Nhiều đơn</button>
                <button class="ta-cust-filter-btn" data-cust-sort="revenue">Doanh thu</button>
            </div>
        </div>

        <div class="ta-rr-th ta-cust-th">
            <div>Khách hàng</div>
            <div class="ta-rr-th-r">Đơn</div>
            <div class="ta-rr-th-r">Doanh thu</div>
            <div class="ta-rr-th-r">Hành động</div>
        </div>
        <div class="ta-cust-scroll">
            <div id="ta-cust-body"></div>
        </div>
    </div>

    {{-- ── Row full: Monthly Revenue Bar ──────────────────────── --}}
    <div class="ta-bg-card ta-bg-card-full" id="ta-mr-card">
        <div class="ta-bg-card-head">
            <div>
                <div class="ta-sub-label">— 04 / Theo tháng</div>
                <div class="ta-panel-title">Doanh thu tháng</div>
            </div>
            <div class="ta-yr-picker" id="ta-mr-yr" data-selected="{{ $mrYear }}">
                <button class="ta-yr-btn" type="button">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" class="ta-yr-ico"><rect x="3" y="4" width="18" height="18" rx="2.5" stroke="currentColor" stroke-width="2"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    <span class="ta-yr-val">{{ $mrYear }}</span>
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" class="ta-yr-chev"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <div class="ta-yr-drop" role="listbox">
                    @foreach($availableYears as $y)
                    <div class="ta-yr-opt{{ $y == $mrYear ? ' active' : '' }}" role="option" data-year="{{ $y }}">{{ $y }}</div>
                    @endforeach
                </div>
            </div>
        </div>
        <div id="ta-monthly-chart" class="ta-mr-chart"></div>
        <div class="ta-mr-footer">
            Tổng cả năm: <strong id="ta-mr-total">{{ number_format($mrTotal / 1000000, 1) }}tr₫</strong>
        </div>
    </div>

    {{-- ── Row full: Branch Bar Chart ──────────────────────────── --}}
    <div class="ta-bg-card ta-bg-card-full" id="ta-br-card">
        <div class="ta-bg-card-head">
            <div>
                <div class="ta-sub-label">— 05 / Doanh thu chi nhánh</div>
                <div class="ta-panel-title">Doanh thu từng chi nhánh theo tháng</div>
            </div>
            <div class="ta-yr-picker" id="ta-br-yr" data-selected="{{ $rrYear }}">
                <button class="ta-yr-btn" type="button">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" class="ta-yr-ico"><rect x="3" y="4" width="18" height="18" rx="2.5" stroke="currentColor" stroke-width="2"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    <span class="ta-yr-val">{{ $rrYear }}</span>
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" class="ta-yr-chev"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <div class="ta-yr-drop" role="listbox">
                    @foreach($availableYears as $y)
                    <div class="ta-yr-opt{{ $y == $rrYear ? ' active' : '' }}" role="option" data-year="{{ $y }}">{{ $y }}</div>
                    @endforeach
                </div>
            </div>
        </div>
        <div id="ta-branch-race-chart" class="ta-br-chart"></div>
        <div class="ta-mr-footer">
            Tổng cả năm: <strong id="ta-br-total">—</strong>
        </div>
    </div>

</div>

{{-- Inline SSR data for JS chart init --}}
<script id="ta-rr-data" type="application/json">@json($roomRevenue)</script>
<script id="ta-mr-data" type="application/json">@json($monthlyRevenue)</script>
