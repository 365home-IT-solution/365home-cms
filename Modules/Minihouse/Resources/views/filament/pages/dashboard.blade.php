@php
    extract($this->getViewData());
@endphp

<x-filament-panels::page>
    <div class="mh-wrap">
        <div class="mh-inner">
            <div class="mh-header">
                <div>
                    <div class="mh-sub-label">— Tổng quan MiniHouse</div>
                    <h2 class="mh-title">Thống kê <em>CHO THUÊ</em><br><span>{{ $dateRange }}</span></h2>
                </div>
                <div class="mh-header-right">
                    {{-- Tab chọn kỳ báo cáo — ĐÚNG bộ preset của Dashboard Home (Hôm nay/Hôm qua/7
                    Ngày/30 Ngày/90 Ngày/Tháng này/Tháng trước/Năm nay/Năm trước/Tuỳ chọn), không
                    thêm "Tô đen/Khoá lịch" (đặc thù đặt phòng theo giờ, không áp dụng ở đây). --}}
                    <div class="mh-tabs">
                        @foreach (\Modules\Minihouse\App\Filament\Pages\Dashboard::PERIOD_LABELS as $val => $label)
                            <button class="mh-tab {{ $this->period === $val ? 'active' : '' }}"
                                    wire:click="$set('period', '{{ $val }}')">{{ $label }}</button>
                        @endforeach
                        <button class="mh-tab mh-tab-custom {{ $this->period === 'custom' ? 'active' : '' }}"
                                wire:click="$set('period', 'custom')">Tùy chọn</button>
                    </div>
                </div>
            </div>

            @if ($this->period === 'custom')
                <div class="mh-custom-range">
                    <span class="mh-custom-label">Từ ngày</span>
                    <input type="date" class="mh-date-input" wire:model.live="customStart">
                    <span class="mh-custom-sep">→</span>
                    <span class="mh-custom-label">đến</span>
                    <input type="date" class="mh-date-input" wire:model.live="customEnd">
                    <span class="mh-custom-note">
                        % tăng/giảm so với kỳ trước cùng độ dài — các tab khác dùng mốc cố định, không bị ảnh hưởng bởi ô này
                    </span>
                </div>
            @endif

            <div class="mh-kpi-grid">
                <div class="mh-kpi">
                    <div class="mh-kpi-label">Hợp đồng mới</div>
                    <div class="mh-kpi-value">{{ number_format($newContracts) }}</div>
                    <div class="mh-kpi-delta {{ $newContractsDelta >= 0 ? 'up' : 'down' }}">
                        {{ $newContractsDelta >= 0 ? '↑' : '↓' }} {{ abs($newContractsDelta) }}%
                        <span class="dbar" style="--w:{{ min(abs($newContractsDelta) * 2, 100) }}%"></span>
                    </div>
                    <div class="mh-kpi-hint">so với <span class="mh-kpi-hint-range">{{ $prevDateRange }}</span></div>
                </div>

                <div class="mh-kpi">
                    <div class="mh-kpi-label">Doanh thu (thực thu)</div>
                    <div class="mh-kpi-value" style="font-size:22px;">{{ number_format($revenue, 0, ',', '.') }}<span class="unit">đ</span></div>
                    <div class="mh-kpi-delta {{ $revenueDelta >= 0 ? 'up' : 'down' }}">
                        {{ $revenueDelta >= 0 ? '↑' : '↓' }} {{ abs($revenueDelta) }}%
                        <span class="dbar" style="--w:{{ min(abs($revenueDelta) * 2, 100) }}%"></span>
                    </div>
                    <div class="mh-kpi-hint">Theo ngày thanh toán thật · so với <span class="mh-kpi-hint-range">{{ $prevDateRange }}</span></div>
                </div>

                <div class="mh-kpi">
                    <div class="mh-kpi-label">Chuyển khoản</div>
                    <div class="mh-kpi-value" style="font-size:22px;">{{ number_format($revenueTransfer, 0, ',', '.') }}<span class="unit">đ</span></div>
                    <div class="mh-kpi-delta {{ $revenueTransferDelta >= 0 ? 'up' : 'down' }}">
                        {{ $revenueTransferDelta >= 0 ? '↑' : '↓' }} {{ abs($revenueTransferDelta) }}%
                        <span class="dbar" style="--w:{{ min(abs($revenueTransferDelta) * 2, 100) }}%"></span>
                    </div>
                    <div class="mh-kpi-hint">Gồm PayOS · so với <span class="mh-kpi-hint-range">{{ $prevDateRange }}</span></div>
                </div>

                <div class="mh-kpi">
                    <div class="mh-kpi-label">Tiền mặt</div>
                    <div class="mh-kpi-value" style="font-size:22px;">{{ number_format($revenueCash, 0, ',', '.') }}<span class="unit">đ</span></div>
                    <div class="mh-kpi-delta {{ $revenueCashDelta >= 0 ? 'up' : 'down' }}">
                        {{ $revenueCashDelta >= 0 ? '↑' : '↓' }} {{ abs($revenueCashDelta) }}%
                        <span class="dbar" style="--w:{{ min(abs($revenueCashDelta) * 2, 100) }}%"></span>
                    </div>
                    <div class="mh-kpi-hint">so với <span class="mh-kpi-hint-range">{{ $prevDateRange }}</span></div>
                </div>

                <div class="mh-kpi">
                    <div class="mh-kpi-label">Chi phí</div>
                    <div class="mh-kpi-value" style="font-size:22px;">{{ number_format($expense, 0, ',', '.') }}<span class="unit">đ</span></div>
                    <div class="mh-kpi-delta {{ $expenseDelta >= 0 ? 'up' : 'down' }}">
                        {{ $expenseDelta >= 0 ? '↑' : '↓' }} {{ abs($expenseDelta) }}%
                        <span class="dbar" style="--w:{{ min(abs($expenseDelta) * 2, 100) }}%"></span>
                    </div>
                    <div class="mh-kpi-hint">Sửa chữa + vận hành · so với <span class="mh-kpi-hint-range">{{ $prevDateRange }}</span></div>
                </div>
            </div>

            {{-- Nhóm "hiện trạng" — KHÔNG đổi theo tab kỳ ở trên (tỷ lệ lấp đầy/hợp đồng hiệu lực/
            nợ đều là ảnh chụp tại thời điểm xem) — gộp CHUNG 1 khung nền với nhóm theo kỳ ở trên
            thay vì để MinihouseStatsWidget tách riêng thành 1 khối Filament Stat khác kiểu, khác bộ
            lọc như trước, nhìn rối và số "Thu/Chi tháng này" cũ bị trùng nghĩa với "Doanh thu/Chi
            phí" đã lọc theo kỳ ở trên. --}}
            <div class="mh-divider">
                <span>Hiện trạng (không theo kỳ đang lọc)</span>
            </div>

            <div class="mh-kpi-grid">
                <div class="mh-kpi mh-kpi-plain">
                    <div class="mh-kpi-label">Toà nhà</div>
                    <div class="mh-kpi-value">{{ number_format($totalBuildings) }}</div>
                </div>

                <div class="mh-kpi mh-kpi-plain">
                    <div class="mh-kpi-label">Phòng</div>
                    <div class="mh-kpi-value">{{ number_format($totalRooms) }}</div>
                    <div class="mh-kpi-hint">{{ $rentedRooms }} đang thuê · {{ $reservedRooms }} đã đặt cọc · {{ $emptyRooms }} trống</div>
                </div>

                <div class="mh-kpi mh-kpi-plain">
                    <div class="mh-kpi-label">Hợp đồng đang hiệu lực</div>
                    <div class="mh-kpi-value">{{ number_format($activeContracts) }}</div>
                </div>

                <div class="mh-kpi mh-kpi-plain">
                    <div class="mh-kpi-label">Sắp hết hạn hợp đồng (30 ngày)</div>
                    <div class="mh-kpi-value" style="color: {{ $expiringContracts > 0 ? 'var(--mh-red)' : 'var(--mh-ink-title)' }};">{{ number_format($expiringContracts) }}</div>
                </div>

                <div class="mh-kpi mh-kpi-plain">
                    <div class="mh-kpi-label">Hoá đơn chưa thanh toán</div>
                    <div class="mh-kpi-value" style="font-size:22px; color: {{ $unpaidInvoiceTotal > 0 ? 'var(--mh-red)' : 'var(--mh-ink-title)' }};">{{ number_format($unpaidInvoiceTotal, 0, ',', '.') }}<span class="unit">đ</span></div>
                </div>
            </div>
        </div>
    </div>

    <x-filament-widgets::widgets
        :columns="$this->getColumns()"
        :data="$this->getWidgetData()"
        :widgets="$this->getVisibleWidgets()"
    />

    <style>
        .mh-wrap {
            --mh-bg: #FAFAFA;
            --mh-panel: #FFFFFF;
            --mh-line: #EAEAEA;
            --mh-line-soft: #F3F4F6;
            --mh-ink: #171717;
            --mh-ink-title: #0A0A0A;
            --mh-ink-mute: #737373;
            --mh-ink-faint: #A3A3A3;
            --mh-green: #10B981;
            --mh-red: #EF4444;

            background: var(--mh-bg);
            color: var(--mh-ink);
            font-family: 'Manrope', 'Inter', ui-sans-serif, sans-serif;
            border-radius: 12px;
            padding: 28px 32px 24px;
            margin-bottom: 24px;
        }

        .mh-inner {
            position: relative;
        }

        .mh-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .mh-sub-label {
            font-size: 10px;
            color: var(--mh-ink-mute);
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 6px;
            font-weight: 600;
        }

        .mh-title {
            font-size: 32px;
            line-height: 1.1;
            font-weight: 700;
            color: var(--mh-ink-title);
            letter-spacing: -0.5px;
        }

        .mh-title em {
            color: var(--mh-green);
            font-style: normal;
        }

        .mh-header-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .mh-tabs {
            display: flex;
            gap: 2px;
            background: var(--mh-panel);
            padding: 3px;
            border-radius: 20px;
            border: 1px solid var(--mh-line);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            flex-wrap: wrap;
        }

        .mh-tab {
            padding: 6px 14px;
            border-radius: 16px;
            font-size: 10px;
            letter-spacing: 0.8px;
            color: var(--mh-ink-mute);
            cursor: pointer;
            border: none;
            background: transparent;
            text-transform: uppercase;
            font-weight: 600;
            transition: all 0.18s;
            font-family: 'Inter', ui-sans-serif, sans-serif;
        }

        .mh-tab.active {
            background: var(--mh-ink-title);
            color: #fff;
        }

        .mh-tab:not(.active):hover {
            background: var(--mh-line-soft);
            color: var(--mh-ink);
        }

        .mh-tab-custom {
            border-left: 1px solid var(--mh-line);
            margin-left: 3px;
            padding-left: 14px;
            border-radius: 16px;
        }

        .mh-custom-range {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 18px;
            background: var(--mh-panel);
            border: 1px solid var(--mh-line);
            border-radius: 10px;
            padding: 10px 14px;
        }

        .mh-custom-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--mh-ink-mute);
        }

        .mh-custom-sep {
            font-size: 13px;
            color: var(--mh-ink);
            font-weight: 700;
        }

        .mh-date-input {
            padding: 5px 10px;
            border-radius: 7px;
            border: 1px solid var(--mh-line);
            background: var(--mh-bg);
            font-size: 12px;
            font-weight: 600;
            color: var(--mh-ink-title);
            font-family: 'Inter', ui-sans-serif, sans-serif;
            outline: none;
            transition: border-color 0.15s;
        }

        .mh-date-input:focus {
            border-color: var(--mh-ink-title);
            box-shadow: 0 0 0 3px rgba(23, 23, 23, 0.08);
        }

        .mh-custom-note {
            flex: 1;
            min-width: 220px;
            font-size: 10px;
            color: var(--mh-ink-faint);
            border-left: 1px solid var(--mh-line);
            padding-left: 10px;
            line-height: 1.5;
        }

        .mh-kpi-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
        }

        .mh-kpi {
            background: var(--mh-panel);
            padding: 20px 20px 16px;
            border-radius: 12px;
            position: relative;
            border: 1px solid var(--mh-line);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        }

        .mh-kpi-label {
            font-size: 10px;
            color: var(--mh-ink-mute);
            text-transform: uppercase;
            letter-spacing: 1.4px;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .mh-kpi-value {
            font-size: 34px;
            font-weight: 700;
            line-height: 1;
            letter-spacing: -0.5px;
            color: var(--mh-ink-title);
        }

        .mh-kpi-value .unit {
            font-size: 13px;
            color: var(--mh-ink-mute);
            margin-left: 3px;
            font-weight: 400;
        }

        .mh-kpi-delta {
            margin-top: 10px;
            font-size: 11px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .mh-kpi-delta.up { color: var(--mh-green); }
        .mh-kpi-delta.down { color: var(--mh-red); }

        .mh-kpi-delta .dbar {
            flex: 1;
            height: 2px;
            background: var(--mh-line);
            border-radius: 2px;
            position: relative;
            overflow: hidden;
        }

        .mh-kpi-delta .dbar::after {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            background: currentColor;
            width: var(--w, 50%);
            border-radius: 2px;
        }

        .mh-kpi-hint {
            margin-top: 6px;
            font-size: 9px;
            color: var(--mh-ink-faint);
            letter-spacing: 0.2px;
            line-height: 1.3;
        }

        .mh-kpi-hint-range {
            font-family: 'Inter', ui-sans-serif, sans-serif;
            font-weight: 600;
        }

        .mh-divider {
            display: flex;
            align-items: center;
            margin: 20px 0 10px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--mh-ink-faint);
        }

        .mh-divider::before,
        .mh-divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background: var(--mh-line);
        }

        .mh-divider span {
            padding: 0 10px;
            white-space: nowrap;
        }

        .mh-kpi-plain .mh-kpi-value {
            font-size: 28px;
        }

        @media (max-width: 1200px) {
            .mh-kpi-grid { grid-template-columns: repeat(3, 1fr); }
        }

        @media (max-width: 640px) {
            .mh-wrap { padding: 16px 14px 20px; border-radius: 0; }
            .mh-header { margin-bottom: 14px; }
            .mh-title { font-size: 18px; }
            .mh-tabs { flex-wrap: wrap; gap: 2px; }
            .mh-tab { font-size: 9px; padding: 5px 9px; }
            .mh-kpi-grid { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 8px; }
            .mh-kpi { padding: 12px 10px 10px; }
            .mh-kpi-value { font-size: clamp(12px, 3.8vw, 20px); }
        }
    </style>
</x-filament-panels::page>
