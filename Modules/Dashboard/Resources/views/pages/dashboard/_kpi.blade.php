{{-- KPI --}}
<div class="ta-kpi-grid">
    <div class="ta-kpi">
        <div class="ta-kpi-label">Tổng đơn đặt phòng</div>
        <div class="ta-kpi-value" id="ta-kpi-total">{{ number_format($total) }}</div>
        <div class="ta-kpi-delta {{ $totalDelta >= 0 ? 'up' : 'down' }}" id="ta-kpi-total-delta">
            {{ $totalDelta >= 0 ? '↑' : '↓' }} {{ abs($totalDelta) }}%
            <span class="dbar" style="--w:{{ min(abs($totalDelta) * 2, 100) }}%"></span>
        </div>
        <div class="ta-kpi-hint">so với <span class="ta-kpi-hint-range">{{ $prevDateRange }}</span></div>
    </div>
    <div class="ta-kpi">
        <div class="ta-kpi-label">Đơn hoàn thành</div>
        <div class="ta-kpi-value" id="ta-kpi-paid">{{ number_format($paidCount) }}</div>
        <div class="ta-kpi-delta {{ $paidDelta >= 0 ? 'up' : 'down' }}" id="ta-kpi-paid-delta">
            {{ $paidDelta >= 0 ? '↑' : '↓' }} {{ abs($paidDelta) }}%
            <span class="dbar" style="--w:{{ min(abs($paidDelta) * 2, 100) }}%"></span>
        </div>
        <div class="ta-kpi-hint">so với <span class="ta-kpi-hint-range">{{ $prevDateRange }}</span></div>
    </div>
    <div class="ta-kpi">
        <div class="ta-kpi-label">Doanh thu (thực thu)</div>
        <div class="ta-kpi-value" id="ta-kpi-revenue" style="font-size:22px;">
            {{ number_format($revenue, 0, ',', '.') }}<span class="unit">đ</span>
        </div>
        <div class="ta-kpi-delta {{ $revenueDelta >= 0 ? 'up' : 'down' }}" id="ta-kpi-revenue-delta">
            {{ $revenueDelta >= 0 ? '↑' : '↓' }} {{ abs($revenueDelta) }}%
            <span class="dbar" style="--w:{{ min(abs($revenueDelta) * 2, 100) }}%"></span>
        </div>
        <div class="ta-kpi-hint">Đã thanh toán + tiền cọc · so với <span class="ta-kpi-hint-range">{{ $prevDateRange }}</span></div>
    </div>
    {{-- <div class="ta-kpi">
        <div class="ta-kpi-label">Tổng giá gốc (lúc đặt)</div>
        <div class="ta-kpi-value" id="ta-kpi-revenue-original" style="font-size:22px;">
            {{ number_format($revenueOriginal, 0, ',', '.') }}<span class="unit">đ</span>
        </div>
        <div class="ta-kpi-delta {{ $revenueOriginalDelta >= 0 ? 'up' : 'down' }}" id="ta-kpi-revenue-original-delta">
            {{ $revenueOriginalDelta >= 0 ? '↑' : '↓' }} {{ abs($revenueOriginalDelta) }}%
            <span class="dbar" style="--w:{{ min(abs($revenueOriginalDelta) * 2, 100) }}%"></span>
        </div>
        <div class="ta-kpi-hint" id="ta-kpi-revenue-original-hint">
            @if ($revenueExtraCharge != 0)
                Chênh lệch (phụ phí phát sinh): <strong>{{ $revenueExtraCharge > 0 ? '+' : '' }}{{ number_format($revenueExtraCharge, 0, ',', '.') }}đ</strong>
            @else
                Đơn đã thanh toán · chưa có phụ phí phát sinh
            @endif
        </div>
    </div> --}}
    <div class="ta-kpi">
        <div class="ta-kpi-label">Chuyển khoản</div>
        <div class="ta-kpi-value" id="ta-kpi-revenue-payos" style="font-size:22px;">
            {{ number_format($revenuePayos, 0, ',', '.') }}<span class="unit">đ</span>
        </div>
        <div class="ta-kpi-delta {{ $revenuePayosDelta >= 0 ? 'up' : 'down' }}" id="ta-kpi-revenue-payos-delta">
            {{ $revenuePayosDelta >= 0 ? '↑' : '↓' }} {{ abs($revenuePayosDelta) }}%
            <span class="dbar" style="--w:{{ min(abs($revenuePayosDelta) * 2, 100) }}%"></span>
        </div>
        <div class="ta-kpi-hint">PayOS · so với <span class="ta-kpi-hint-range">{{ $prevDateRange }}</span></div>
    </div>
    <div class="ta-kpi">
        <div class="ta-kpi-label">Tiền mặt</div>
        <div class="ta-kpi-value" id="ta-kpi-revenue-cod" style="font-size:22px;">
            {{ number_format($revenueCod, 0, ',', '.') }}<span class="unit">đ</span>
        </div>
        <div class="ta-kpi-delta {{ $revenueCodDelta >= 0 ? 'up' : 'down' }}" id="ta-kpi-revenue-cod-delta">
            {{ $revenueCodDelta >= 0 ? '↑' : '↓' }} {{ abs($revenueCodDelta) }}%
            <span class="dbar" style="--w:{{ min(abs($revenueCodDelta) * 2, 100) }}%"></span>
        </div>
        <div class="ta-kpi-hint">COD · so với <span class="ta-kpi-hint-range">{{ $prevDateRange }}</span></div>
    </div>
    <div class="ta-kpi">
        <div class="ta-kpi-label">Chuyển khoản - Đặt cọc</div>
        <div class="ta-kpi-value" id="ta-kpi-revenue-deposit-payos" style="font-size:22px;">
            {{ number_format($revenueDepositPayos, 0, ',', '.') }}<span class="unit">đ</span>
        </div>
        <div class="ta-kpi-delta {{ $revenueDepositPayosDelta >= 0 ? 'up' : 'down' }}" id="ta-kpi-revenue-deposit-payos-delta">
            {{ $revenueDepositPayosDelta >= 0 ? '↑' : '↓' }} {{ abs($revenueDepositPayosDelta) }}%
            <span class="dbar" style="--w:{{ min(abs($revenueDepositPayosDelta) * 2, 100) }}%"></span>
        </div>
        <div class="ta-kpi-hint">Đặt cọc PayOS · so với <span class="ta-kpi-hint-range">{{ $prevDateRange }}</span></div>
    </div>
    <div class="ta-kpi">
        <div class="ta-kpi-label">Tiền mặt - Đặt cọc</div>
        <div class="ta-kpi-value" id="ta-kpi-revenue-deposit-cod" style="font-size:22px;">
            {{ number_format($revenueDepositCod, 0, ',', '.') }}<span class="unit">đ</span>
        </div>
        <div class="ta-kpi-delta {{ $revenueDepositCodDelta >= 0 ? 'up' : 'down' }}" id="ta-kpi-revenue-deposit-cod-delta">
            {{ $revenueDepositCodDelta >= 0 ? '↑' : '↓' }} {{ abs($revenueDepositCodDelta) }}%
            <span class="dbar" style="--w:{{ min(abs($revenueDepositCodDelta) * 2, 100) }}%"></span>
        </div>
        <div class="ta-kpi-hint">Đặt cọc tiền mặt · so với <span class="ta-kpi-hint-range">{{ $prevDateRange }}</span></div>
    </div>
</div>
