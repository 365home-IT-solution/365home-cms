@php
    $data = $this->getViewData();
    extract($data);

    $periodLabel = match ($this->period) {
        '7d'  => '7 ngày',
        '90d' => '90 ngày',
        'ytd' => 'Năm nay',
        default => '30 ngày',
    };

    $donutJson    = json_encode($donutData);
    $trendDaysJ   = json_encode($trendDays);
    $trendPendJ   = json_encode($trendPending);
    $trendPaidJ   = json_encode($trendPaid);
    $trendCancelJ = json_encode($trendCancel);
    $barDataJ     = json_encode($barData);

    $totalSrc     = array_sum(array_column($sources, 'count')) ?: 1;
@endphp

<style>
.ta-wrap {
    --ta-bg: #0e0d0b;
    --ta-panel: #1c1a14;
    --ta-panel2: #221f17;
    --ta-line: #2a2619;
    --ta-line-soft: #221f18;
    --ta-ink: #f5efe0;
    --ta-ink-dim: #a8a294;
    --ta-ink-mute: #706b5e;
    --ta-accent: #e8c275;
    --ta-accent2: #d97757;
    --ta-green: #9ab87a;
    --ta-red: #c96f57;

    background: var(--ta-bg);
    color: var(--ta-ink);
    font-family: 'Manrope', 'Inter', sans-serif;
    border-radius: 12px;
    padding: 28px 32px 36px;
    position: relative;
    overflow: hidden;
}

.ta-wrap::before {
    content: "";
    position: absolute;
    inset: 0;
    background-image:
        radial-gradient(circle at 20% 10%, rgba(232,194,117,0.07), transparent 40%),
        radial-gradient(circle at 85% 90%, rgba(217,119,87,0.05), transparent 45%);
    pointer-events: none;
    z-index: 0;
}

.ta-inner { position: relative; z-index: 1; }

/* ---- HEADER ---- */
.ta-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    margin-bottom: 28px;
    flex-wrap: wrap;
    gap: 16px;
}
.ta-title {
    font-family: 'Georgia', 'Times New Roman', serif;
    font-size: 40px;
    line-height: 0.95;
    letter-spacing: -0.5px;
    font-weight: 400;
}
.ta-title em { color: var(--ta-accent); font-style: italic; }
.ta-sub-label {
    font-size: 10px;
    color: var(--ta-ink-mute);
    text-transform: uppercase;
    letter-spacing: 2px;
    margin-bottom: 10px;
    font-weight: 500;
}
.ta-period-tabs {
    display: flex;
    gap: 4px;
    background: #111009;
    padding: 3px;
    border-radius: 20px;
    border: 1px solid var(--ta-line);
}
.ta-tab {
    padding: 6px 14px;
    border-radius: 16px;
    font-size: 10px;
    letter-spacing: 0.8px;
    color: var(--ta-ink-mute);
    cursor: pointer;
    border: none;
    background: transparent;
    text-transform: uppercase;
    font-weight: 600;
    transition: all 0.2s;
    font-family: 'Courier New', monospace;
}
.ta-tab.active { background: var(--ta-accent); color: #0e0d0b; }
.ta-tab:not(.active):hover { color: var(--ta-ink); }

/* ---- KPI GRID ---- */
.ta-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1px;
    background: var(--ta-line);
    border: 1px solid var(--ta-line);
    border-radius: 4px;
    margin-bottom: 28px;
}
.ta-kpi {
    background: var(--ta-bg);
    padding: 20px 22px;
    position: relative;
    overflow: hidden;
}
.ta-kpi::before {
    content: "";
    position: absolute;
    top: 0; left: 0;
    width: 36px; height: 2px;
}
.ta-kpi:nth-child(1)::before { background: var(--ta-accent); }
.ta-kpi:nth-child(2)::before { background: var(--ta-green); }
.ta-kpi:nth-child(3)::before { background: var(--ta-accent2); }
.ta-kpi:nth-child(4)::before { background: #7a9cb5; }
.ta-kpi-label {
    font-size: 10px;
    color: var(--ta-ink-mute);
    text-transform: uppercase;
    letter-spacing: 1.6px;
    margin-bottom: 14px;
    font-weight: 600;
}
.ta-kpi-value {
    font-family: 'Georgia', serif;
    font-size: 38px;
    line-height: 1;
    letter-spacing: -0.5px;
}
.ta-kpi-value .unit {
    font-family: inherit;
    font-size: 13px;
    color: var(--ta-ink-mute);
    margin-left: 3px;
}
.ta-kpi-delta {
    margin-top: 10px;
    font-size: 11px;
    font-family: 'Courier New', monospace;
    display: flex;
    align-items: center;
    gap: 6px;
}
.ta-kpi-delta.up { color: var(--ta-green); }
.ta-kpi-delta.down { color: var(--ta-red); }
.ta-kpi-delta .bar {
    flex: 1; height: 1px;
    background: var(--ta-line);
    margin-left: 6px;
    position: relative;
}
.ta-kpi-delta .bar::after {
    content: ""; position: absolute;
    left: 0; top: 0; height: 1px;
    background: currentColor;
    width: var(--w, 50%);
}

/* ---- MAIN GRID ---- */
.ta-main-grid {
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}
.ta-panel {
    background: var(--ta-panel);
    border: 1px solid var(--ta-line);
    border-radius: 6px;
    padding: 24px 26px;
}
.ta-panel-label {
    font-size: 10px;
    color: var(--ta-ink-mute);
    text-transform: uppercase;
    letter-spacing: 2px;
    margin-bottom: 6px;
    font-weight: 600;
}
.ta-panel-title {
    font-family: 'Georgia', serif;
    font-size: 22px;
    line-height: 1.1;
    margin-bottom: 16px;
}

/* Donut container */
.ta-donut-wrap { position: relative; }
#ta-donut { width: 100%; height: 360px; }
.ta-chart-center {
    position: absolute;
    top: 57%;
    left: 41%;
    transform: translate(-50%, -50%);
    text-align: center;
    pointer-events: none;
}
.ta-chart-center .cc-label {
    font-size: 10px;
    color: var(--ta-ink-mute);
    text-transform: uppercase;
    letter-spacing: 2px;
}
.ta-chart-center .cc-value {
    font-family: 'Georgia', serif;
    font-size: 46px;
    line-height: 1;
    margin-top: 4px;
    color: var(--ta-ink);
}
.ta-chart-center .cc-sub {
    font-size: 11px;
    color: var(--ta-ink-dim);
    margin-top: 4px;
    letter-spacing: 0.5px;
}

/* Source list */
.ta-src-list { display: flex; flex-direction: column; gap: 2px; }
.ta-src-item {
    padding: 14px 2px;
    border-bottom: 1px solid var(--ta-line-soft);
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 14px;
    transition: all 0.2s;
}
.ta-src-item:last-child { border-bottom: none; }
.ta-src-item:hover {
    padding-left: 8px;
    background: linear-gradient(to right, rgba(232,194,117,0.05), transparent);
}
.ta-swatch {
    width: 9px; height: 9px;
    border-radius: 50%;
    flex-shrink: 0;
}
.ta-src-name { font-size: 14px; font-weight: 500; }
.ta-src-meta {
    font-size: 10px;
    color: var(--ta-ink-mute);
    margin-top: 3px;
    font-family: 'Courier New', monospace;
    letter-spacing: 0.3px;
}
.ta-src-right { text-align: right; }
.ta-src-pct {
    font-family: 'Georgia', serif;
    font-size: 22px;
    line-height: 1;
}
.ta-src-pct::after { content: "%"; font-size: 12px; color: var(--ta-ink-mute); margin-left: 1px; }
.ta-src-val {
    font-size: 10px;
    color: var(--ta-ink-dim);
    margin-top: 3px;
    font-family: 'Courier New', monospace;
}
.ta-src-bar {
    grid-column: 1 / -1;
    height: 2px;
    background: var(--ta-line-soft);
    margin-top: 8px;
    overflow: hidden;
    border-radius: 2px;
}
.ta-src-bar-fill {
    height: 100%;
    border-radius: 2px;
    animation: taFill 1.2s cubic-bezier(0.22, 1, 0.36, 1) forwards;
}
@keyframes taFill { from { transform: scaleX(0); transform-origin: left; } }

/* Bottom grid */
.ta-bottom-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
#ta-trend, #ta-bar { width: 100%; height: 230px; }

@media (max-width: 900px) {
    .ta-main-grid, .ta-bottom-grid { grid-template-columns: 1fr; }
    .ta-kpi-grid { grid-template-columns: repeat(2, 1fr); }
    .ta-title { font-size: 28px; }
    .ta-chart-center { left: 50%; top: 50%; }
}

@media (max-width: 480px) {
    .ta-kpi-grid { grid-template-columns: 1fr; }
    .ta-kpi { padding: 14px 16px; }
}
</style>

<div class="ta-wrap">
<div class="ta-inner">

    {{-- HEADER --}}
    <div class="ta-header">
        <div>
            <div class="ta-sub-label">— 01 / Tổng quan đơn đặt phòng</div>
            <h2 class="ta-title">Thống kê <em>booking</em><br/>{{ $periodLabel }} qua.</h2>
        </div>
        <div>
            <div class="ta-period-tabs">
                @foreach(['7d' => '7N', '30d' => '30N', '90d' => '90N', 'ytd' => 'Năm'] as $val => $lbl)
                    <button class="ta-tab {{ $this->period === $val ? 'active' : '' }}"
                            wire:click="$set('period', '{{ $val }}')">{{ $lbl }}</button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- KPI GRID --}}
    <div class="ta-kpi-grid">
        <div class="ta-kpi">
            <div class="ta-kpi-label">Tổng đơn đặt phòng</div>
            <div class="ta-kpi-value">{{ number_format($total) }}</div>
            <div class="ta-kpi-delta {{ $totalDelta >= 0 ? 'up' : 'down' }}">
                {{ $totalDelta >= 0 ? '↑' : '↓' }} {{ abs($totalDelta) }}%
                <span class="bar" style="--w:{{ min(abs($totalDelta) * 2, 100) }}%"></span>
            </div>
        </div>
        <div class="ta-kpi">
            <div class="ta-kpi-label">Đơn hoàn thành</div>
            <div class="ta-kpi-value">{{ number_format($paidCount) }}</div>
            <div class="ta-kpi-delta {{ $paidDelta >= 0 ? 'up' : 'down' }}">
                {{ $paidDelta >= 0 ? '↑' : '↓' }} {{ abs($paidDelta) }}%
                <span class="bar" style="--w:{{ min(abs($paidDelta) * 2, 100) }}%"></span>
            </div>
        </div>
        <div class="ta-kpi">
            <div class="ta-kpi-label">Tỷ lệ chuyển đổi</div>
            <div class="ta-kpi-value">{{ $convRate }}<span class="unit">%</span></div>
            <div class="ta-kpi-delta {{ $convDelta >= 0 ? 'up' : 'down' }}">
                {{ $convDelta >= 0 ? '↑' : '↓' }} {{ abs($convDelta) }}pt
                <span class="bar" style="--w:{{ min(abs($convDelta) * 5, 100) }}%"></span>
            </div>
        </div>
        <div class="ta-kpi">
            <div class="ta-kpi-label">Doanh thu</div>
            <div class="ta-kpi-value" style="font-size:28px;">
                {{ number_format($revenue / 1000000, 1) }}<span class="unit">tr₫</span>
            </div>
            <div class="ta-kpi-delta {{ $revenueDelta >= 0 ? 'up' : 'down' }}">
                {{ $revenueDelta >= 0 ? '↑' : '↓' }} {{ abs($revenueDelta) }}%
                <span class="bar" style="--w:{{ min(abs($revenueDelta) * 2, 100) }}%"></span>
            </div>
        </div>
    </div>

    {{-- MAIN GRID: Donut + Source list --}}
    <div class="ta-main-grid">
        <div class="ta-panel">
            <div class="ta-panel-label">— Phân bổ trạng thái</div>
            <div class="ta-panel-title">Tỉ lệ theo trạng thái đơn</div>
            <div class="ta-donut-wrap">
                <div id="ta-donut"></div>
                <div class="ta-chart-center">
                    <div class="cc-label">Tổng</div>
                    <div class="cc-value" id="ta-cc-value">{{ number_format($total) }}</div>
                    <div class="cc-sub" id="ta-cc-sub">đơn đặt phòng</div>
                </div>
            </div>
        </div>

        <div class="ta-panel">
            <div class="ta-panel-label">— Chi tiết</div>
            <div class="ta-panel-title">Từng trạng thái</div>
            <div class="ta-src-list">
                @foreach($sources as $i => $src)
                <div class="ta-src-item">
                    <div class="ta-swatch" style="background:{{ $src['color'] }};box-shadow:0 0 10px {{ $src['color'] }}66;"></div>
                    <div>
                        <div class="ta-src-name">{{ $src['name'] }}</div>
                        <div class="ta-src-meta">HẠNG {{ str_pad($i+1, 2, '0', STR_PAD_LEFT) }} · {{ number_format($src['count']) }} ĐƠN</div>
                    </div>
                    <div class="ta-src-right">
                        <div class="ta-src-pct">{{ $src['pct'] }}</div>
                        <div class="ta-src-val">{{ number_format($src['count']) }}</div>
                    </div>
                    <div class="ta-src-bar">
                        <div class="ta-src-bar-fill"
                             style="width:{{ $src['pct'] }}%;background:{{ $src['color'] }};animation-delay:{{ $i * 0.08 }}s;transform-origin:left;"></div>
                    </div>
                </div>
                @endforeach
                @if(empty($sources))
                    <div style="color:var(--ta-ink-mute);font-size:13px;padding:20px 0;text-align:center;">Chưa có dữ liệu trong kỳ này</div>
                @endif
            </div>
        </div>
    </div>

    {{-- BOTTOM GRID: Trend + Bar --}}
    <div class="ta-bottom-grid">
        <div class="ta-panel">
            <div class="ta-panel-label">— Xu hướng</div>
            <div class="ta-panel-title">7 ngày gần nhất</div>
            <div id="ta-trend"></div>
        </div>
        <div class="ta-panel">
            <div class="ta-panel-label">— So sánh</div>
            <div class="ta-panel-title">Phân bổ 30 ngày qua</div>
            <div id="ta-bar"></div>
        </div>
    </div>

</div>
</div>

@assets
<script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>
@endassets

@script
<script>
(function () {
    const donutData  = @json($donutData);
    const trendDays  = @json($trendDays);
    const trendPend  = @json($trendPending);
    const trendPaid  = @json($trendPaid);
    const trendCancel= @json($trendCancel);
    const barData    = @json($barData);
    const totalCount = {{ $total }};

    function initCharts() {
        if (typeof echarts === 'undefined') return;

        // ---- DONUT ----
        const donutEl = document.getElementById('ta-donut');
        if (!donutEl || donutEl._echarts_instance_) return;
        const donut = echarts.init(donutEl, null, { renderer: 'canvas' });

        donut.setOption({
            tooltip: {
                trigger: 'item',
                backgroundColor: '#1c1a14',
                borderColor: '#2a2619',
                borderWidth: 1,
                padding: [10, 14],
                textStyle: { color: '#f5efe0', fontFamily: 'Manrope, sans-serif', fontSize: 12 },
                formatter: p => `<div style="font-size:10px;color:#a8a294;text-transform:uppercase;margin-bottom:4px;">${p.seriesName}</div>
                    <div style="font-size:14px;font-weight:600;">${p.name}</div>
                    <div style="font-size:20px;margin-top:6px;color:#e8c275;">${p.value.toLocaleString('vi-VN')} <span style="font-size:11px;color:#a8a294;">(${p.percent}%)</span></div>`
            },
            legend: { show: false },
            color: donutData.map(d => d.hex),
            series: [{
                name: 'Trạng thái đơn',
                type: 'pie',
                radius: ['54%', '76%'],
                center: ['42%', '55%'],
                padAngle: 2,
                itemStyle: { borderRadius: 5, borderColor: '#1c1a14', borderWidth: 3 },
                label: { show: false },
                labelLine: { show: false },
                emphasis: { scale: true, scaleSize: 6, itemStyle: { shadowBlur: 20, shadowColor: 'rgba(232,194,117,0.4)' } },
                data: donutData
            }]
        });

        const ccValue = document.getElementById('ta-cc-value');
        const ccSub   = document.getElementById('ta-cc-sub');
        donut.on('mouseover', p => {
            if (ccValue) { ccValue.textContent = p.value.toLocaleString('vi-VN'); ccValue.style.color = p.color; }
            if (ccSub) ccSub.textContent = `${p.percent}% tổng số đơn`;
        });
        donut.on('mouseout', () => {
            if (ccValue) { ccValue.textContent = totalCount.toLocaleString('vi-VN'); ccValue.style.color = '#f5efe0'; }
            if (ccSub) ccSub.textContent = 'đơn đặt phòng';
        });

        // ---- TREND ----
        const trendEl = document.getElementById('ta-trend');
        if (trendEl && !trendEl._echarts_instance_) {
            const trend = echarts.init(trendEl, null, { renderer: 'canvas' });
            trend.setOption({
                grid: { left: 30, right: 20, top: 20, bottom: 28 },
                tooltip: {
                    trigger: 'axis',
                    backgroundColor: '#1c1a14',
                    borderColor: '#2a2619',
                    textStyle: { color: '#f5efe0', fontSize: 12 }
                },
                xAxis: {
                    type: 'category',
                    data: trendDays,
                    axisLine: { lineStyle: { color: '#2a2619' } },
                    axisLabel: { color: '#706b5e', fontSize: 9 },
                    axisTick: { show: false }
                },
                yAxis: {
                    type: 'value',
                    minInterval: 1,
                    splitLine: { lineStyle: { color: '#221f18', type: 'dashed' } },
                    axisLabel: { color: '#706b5e', fontSize: 9 },
                    axisLine: { show: false },
                    axisTick: { show: false }
                },
                series: [
                    {
                        name: 'Chờ xác nhận', type: 'line', smooth: true,
                        symbol: 'circle', symbolSize: 5,
                        lineStyle: { color: '#e8c275', width: 2 },
                        itemStyle: { color: '#e8c275', borderColor: '#0e0d0b', borderWidth: 2 },
                        areaStyle: { color: { type:'linear', x:0,y:0,x2:0,y2:1, colorStops:[{offset:0,color:'rgba(232,194,117,0.2)'},{offset:1,color:'rgba(232,194,117,0)'}] } },
                        data: trendPend
                    },
                    {
                        name: 'Hoàn thành', type: 'line', smooth: true,
                        symbol: 'circle', symbolSize: 5,
                        lineStyle: { color: '#9ab87a', width: 2 },
                        itemStyle: { color: '#9ab87a', borderColor: '#0e0d0b', borderWidth: 2 },
                        data: trendPaid
                    },
                    {
                        name: 'Đã huỷ', type: 'line', smooth: true,
                        symbol: 'circle', symbolSize: 5,
                        lineStyle: { color: '#c96f57', width: 2 },
                        itemStyle: { color: '#c96f57', borderColor: '#0e0d0b', borderWidth: 2 },
                        data: trendCancel
                    }
                ]
            });
        }

        // ---- BAR ----
        const barEl = document.getElementById('ta-bar');
        if (barEl && !barEl._echarts_instance_) {
            const bar = echarts.init(barEl, null, { renderer: 'canvas' });
            bar.setOption({
                grid: { left: 100, right: 40, top: 10, bottom: 18 },
                tooltip: {
                    trigger: 'axis',
                    axisPointer: { type: 'shadow', shadowStyle: { color: 'rgba(232,194,117,0.04)' } },
                    backgroundColor: '#1c1a14',
                    borderColor: '#2a2619',
                    textStyle: { color: '#f5efe0', fontSize: 12 }
                },
                xAxis: {
                    type: 'value',
                    minInterval: 1,
                    splitLine: { lineStyle: { color: '#221f18', type: 'dashed' } },
                    axisLabel: { color: '#706b5e', fontSize: 9 },
                    axisLine: { show: false },
                    axisTick: { show: false }
                },
                yAxis: {
                    type: 'category',
                    data: barData.map(d => d.name),
                    axisLine: { show: false },
                    axisTick: { show: false },
                    axisLabel: { color: '#f5efe0', fontSize: 11 }
                },
                series: [{
                    type: 'bar', barWidth: 12,
                    data: barData.map(d => ({
                        value: d.value,
                        itemStyle: {
                            color: { type:'linear', x:0,y:0,x2:1,y2:0, colorStops:[{offset:0,color:d.color+'33'},{offset:1,color:d.color}] },
                            borderRadius: [0, 3, 3, 0]
                        }
                    })),
                    label: { show: true, position: 'right', color: '#a8a294', fontSize: 10, formatter: '{c}' }
                }]
            });
        }

        // Resize all
        window.addEventListener('resize', () => {
            donut.resize();
            if (trendEl && trendEl._echarts_instance_) echarts.getInstanceByDom(trendEl)?.resize();
            if (barEl && barEl._echarts_instance_) echarts.getInstanceByDom(barEl)?.resize();
        });
    }

    initCharts();

    // Re-init after Livewire updates (period tab change)
    document.addEventListener('livewire:updated', () => {
        // destroy old instances so they re-init with new data
        ['ta-donut','ta-trend','ta-bar'].forEach(id => {
            const el = document.getElementById(id);
            if (el) { try { echarts.getInstanceByDom(el)?.dispose(); } catch(e){} }
        });
        setTimeout(initCharts, 50);
    });
})();
</script>
@endscript
