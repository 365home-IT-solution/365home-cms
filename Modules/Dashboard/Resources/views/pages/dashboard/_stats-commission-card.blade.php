{{-- Tab "Tất cả đối tác" (Super Admin): tóm tắt hoa hồng 365home thu từ các đối tác trong kỳ. Chi tiết: Quản lý › Thống kê hoa hồng. --}}
@php
    $commissionCard = $this->statsCommissionSummary();
    $money = fn ($v) => number_format((int) $v, 0, ',', '.') . 'đ';
@endphp
@if ($commissionCard)
    <div style="border:1px solid #e5e7eb;border-radius:12px;background:#fff;padding:14px 18px;margin-bottom:16px;display:flex;gap:28px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
        <div style="display:flex;gap:28px;flex-wrap:wrap;">
            <div>
                <div style="font-size:0.72rem;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Hoa hồng thu được</div>
                <div style="font-size:1.35rem;font-weight:800;color:#10b981;">{{ $money($commissionCard['summary']['commission']) }}</div>
                <div style="font-size:0.72rem;color:#9ca3af;">{{ $commissionCard['summary']['orders'] }} đơn đã chốt · {{ $commissionCard['period'] }}</div>
            </div>
            <div>
                <div style="font-size:0.72rem;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">365home bù khuyến mãi</div>
                <div style="font-size:1.35rem;font-weight:800;color:#f59e0b;">{{ $money($commissionCard['summary']['platform_subsidy']) }}</div>
            </div>
            <div>
                <div style="font-size:0.72rem;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Thu nhập ròng</div>
                <div style="font-size:1.35rem;font-weight:800;color:#1f4e79;">{{ $money($commissionCard['summary']['platform_net_income']) }}</div>
            </div>
        </div>
        <a href="{{ \App\Filament\Pages\CommissionStats::getUrl() }}" style="font-size:0.85rem;color:#1f4e79;font-weight:600;text-decoration:none;">Xem chi tiết hoa hồng →</a>
    </div>
@endif
