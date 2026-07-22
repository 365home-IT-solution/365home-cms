{{-- HOA HỒNG — super_admin thấy TẤT CẢ đối tác; chủ đối tác chỉ thấy số của CHÍNH MÌNH;
     $commission null với nhân viên (không liên quan tài chính đối tác). --}}
@if($commission)
@php
    $rows = $commission['rows'] ?? [];
    $totalCommission = $commission['total_commission'] ?? 0;
    $totalRevenue = $commission['total_revenue'] ?? 0;
    $selectedMonth = $commission['month'] ?? now()->month;
    $selectedYear = $commission['year'] ?? now()->year;
    $monthNames = [1=>'Tháng 1',2=>'Tháng 2',3=>'Tháng 3',4=>'Tháng 4',5=>'Tháng 5',6=>'Tháng 6',7=>'Tháng 7',8=>'Tháng 8',9=>'Tháng 9',10=>'Tháng 10',11=>'Tháng 11',12=>'Tháng 12'];
    $isSuperAdmin = $commissionIsSuperAdmin ?? false;
@endphp
<div style="border:1px solid #e5e7eb;border-radius:14px;padding:20px;margin-bottom:18px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;flex-wrap:wrap;gap:12px;">
        <div>
            <div style="font-size:0.78rem;color:#6b7280;">— {{ $isSuperAdmin ? 'Hoa hồng từ đối tác' : 'Hoa hồng phải thanh toán' }}</div>
            <h3 style="font-size:1.05rem;font-weight:700;margin:2px 0 0;">{{ $isSuperAdmin ? 'Doanh thu hoa hồng' : 'Hoa hồng bạn cần thanh toán' }}</h3>
        </div>

        <div style="display:flex;align-items:center;gap:8px;">
            <select wire:model.live="commissionMonth" style="font-size:0.8rem;padding:5px 8px;border-radius:8px;border:1px solid #e5e7eb;">
                @foreach($monthNames as $num => $label)
                    <option value="{{ $num }}" @selected($num == $selectedMonth)>{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="commissionYear" style="font-size:0.8rem;padding:5px 8px;border-radius:8px;border:1px solid #e5e7eb;">
                @foreach(range(now()->year, now()->year - 4) as $y)
                    <option value="{{ $y }}" @selected($y == $selectedYear)>{{ $y }}</option>
                @endforeach
            </select>
        </div>

        <div style="text-align:right;">
            <div style="font-size:1.4rem;font-weight:800;color:#10b981;">{{ number_format($totalCommission, 0, ',', '.') }}đ</div>
            <div style="font-size:0.75rem;color:#9ca3af;">trên {{ $isSuperAdmin ? 'tổng doanh thu' : 'doanh thu của bạn' }} {{ number_format($totalRevenue, 0, ',', '.') }}đ · {{ $monthNames[$selectedMonth] }}/{{ $selectedYear }}</div>
        </div>
    </div>

    @if($isSuperAdmin)
        @if(count($rows) > 0)
            <div style="overflow-x:auto;">
                <table style="width:100%;font-size:0.85rem;text-align:left;">
                    <thead>
                        <tr style="border-bottom:2px solid #e5e7eb;color:#6b7280;">
                            <th style="padding:8px;">Đối tác</th>
                            <th style="padding:8px;text-align:right;">Doanh thu tháng</th>
                            <th style="padding:8px;text-align:right;">Tỷ lệ hoa hồng</th>
                            <th style="padding:8px;text-align:right;">Hoa hồng</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px;font-weight:600;">{{ $row['partner_name'] }}</td>
                            <td style="padding:8px;text-align:right;">{{ number_format($row['revenue'], 0, ',', '.') }}đ</td>
                            <td style="padding:8px;text-align:right;color:#6b7280;">{{ $row['rate'] }}%</td>
                            <td style="padding:8px;text-align:right;font-weight:700;color:#10b981;">{{ number_format($row['commission'], 0, ',', '.') }}đ</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div style="padding:16px;text-align:center;color:#9ca3af;font-size:0.85rem;">Chưa có doanh thu từ đối tác nào trong {{ $monthNames[$selectedMonth] }}/{{ $selectedYear }}.</div>
        @endif
    @else
        {{-- Chủ đối tác: chỉ 1 dòng số liệu của chính mình, không cần bảng nhiều đối tác --}}
        @php $ownRow = $rows[0] ?? null; @endphp
        @if($ownRow && $ownRow['revenue'] > 0)
            <div style="font-size:0.85rem;color:#4b5563;">
                Doanh thu {{ number_format($ownRow['revenue'], 0, ',', '.') }}đ × tỷ lệ hoa hồng <strong>{{ $ownRow['rate'] }}%</strong>
                = <strong style="color:#10b981;">{{ number_format($ownRow['commission'], 0, ',', '.') }}đ</strong> cần thanh toán cho hệ thống.
            </div>
        @else
            <div style="padding:16px;text-align:center;color:#9ca3af;font-size:0.85rem;">Chưa có doanh thu trong {{ $monthNames[$selectedMonth] }}/{{ $selectedYear }}.</div>
        @endif
    @endif
</div>
@endif
