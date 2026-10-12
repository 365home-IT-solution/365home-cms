{{-- TAB "CÁC ĐỐI TÁC" (chỉ Super Admin): bảng so sánh tất cả đối tác Homestay trong kỳ. Bấm một dòng để xem chi tiết đối tác đó. --}}
@php
    $table = $this->statsPartnersTable();
    $money = fn ($v) => number_format((int) $v, 0, ',', '.') . 'đ';
    $th = 'padding:9px 10px;font-size:0.72rem;color:#6b7280;text-transform:uppercase;border-bottom:1px solid #e5e7eb;white-space:nowrap;cursor:pointer;';
    $arrow = fn (string $col) => $statsSort === $col ? ($statsDirection === 'desc' ? ' ↓' : ' ↑') : '';
    $td = 'padding:9px 10px;font-size:0.85rem;border-bottom:1px solid #f3f4f6;white-space:nowrap;text-align:right;';
    $searchBox = new \Illuminate\Support\HtmlString(
        '<input type="search" wire:model.live.debounce.400ms="statsSearch" placeholder="Tìm đối tác (tên, SĐT, email)" style="font-size:0.85rem;padding:6px 10px;border-radius:8px;border:1px solid #e5e7eb;min-width:240px;">'
    );
@endphp
@if ($table)
    @include('dashboard::pages.dashboard._stats-filter', ['extra' => $searchBox])

    <div style="font-size:0.85rem;color:#6b7280;margin-bottom:12px;">
        Kỳ: <strong>{{ $table['period']['label'] }}</strong> — {{ count($table['rows']) }} đối tác · tổng hoa hồng <strong style="color:#10b981;">{{ $money($table['totals']['commission']) }}</strong>
        · bù khuyến mãi <strong>{{ $money($table['totals']['platform_subsidy']) }}</strong>
        · thu nhập ròng <strong style="color:#1f4e79;">{{ $money($table['totals']['platform_net_income']) }}</strong>
        · doanh thu tính hoa hồng <strong>{{ $money($table['totals']['revenue_base']) }}</strong>
    </div>

    <div style="border:1px solid #e5e7eb;border-radius:12px;overflow-x:auto;background:#fff;">
        <table style="width:100%;border-collapse:collapse;">
            <thead><tr>
                <th style="{{ $th }}text-align:left;" wire:click="sortStatsBy('name')">Đối tác{{ $arrow('name') }}</th>
                <th style="{{ $th }}text-align:right;" wire:click="sortStatsBy('orders')">Đơn{{ $arrow('orders') }}</th>
                <th style="{{ $th }}text-align:right;" wire:click="sortStatsBy('revenue_base')">Doanh thu tính HH{{ $arrow('revenue_base') }}</th>
                <th style="{{ $th }}text-align:right;" wire:click="sortStatsBy('commission')">Hoa hồng{{ $arrow('commission') }}</th>
                <th style="{{ $th }}text-align:right;" wire:click="sortStatsBy('platform_subsidy')">Bù KM{{ $arrow('platform_subsidy') }}</th>
                <th style="{{ $th }}text-align:right;" wire:click="sortStatsBy('platform_net_income')">Thu nhập ròng{{ $arrow('platform_net_income') }}</th>
                <th style="{{ $th }}text-align:right;" wire:click="sortStatsBy('partner_receives')">Đối tác nhận{{ $arrow('partner_receives') }}</th>
                <th style="{{ $th }}text-align:right;" wire:click="sortStatsBy('open_to_collect')">Đang phải nộp{{ $arrow('open_to_collect') }}</th>
                <th style="{{ $th }}text-align:right;" wire:click="sortStatsBy('escrow_balance')">Ký quỹ{{ $arrow('escrow_balance') }}</th>
            </tr></thead>
            <tbody>
                @forelse ($table['rows'] as $row)
                    <tr wire:key="stats-partner-{{ $row['partner_id'] }}" wire:click="openPartnerDetail('{{ $row['partner_id'] }}')" style="cursor:pointer;" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background=''">
                        <td style="{{ $td }}text-align:left;white-space:normal;">
                            <strong>{{ $row['name'] }}</strong>
                            <div style="font-size:0.72rem;color:#9ca3af;">Hoa hồng hợp đồng {{ $row['commission_rate'] }}%@if ($row['fee_free_active']) · <span style="color:#10b981;">đang miễn hoa hồng</span>@endif</div>
                        </td>
                        <td style="{{ $td }}">{{ $row['orders'] }}</td>
                        <td style="{{ $td }}">{{ $money($row['revenue_base']) }}</td>
                        <td style="{{ $td }}">{{ $money($row['commission']) }}</td>
                        <td style="{{ $td }}">{{ $money($row['platform_subsidy']) }}</td>
                        <td style="{{ $td }}">{{ $money($row['platform_net_income']) }}</td>
                        <td style="{{ $td }}">{{ $money($row['partner_receives']) }}</td>
                        <td style="{{ $td }}">{{ $money($row['open_to_collect']) }}</td>
                        <td style="{{ $td }}">{{ $money($row['escrow_balance']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" style="{{ $td }}text-align:center;color:#9ca3af;">Không có đối tác nào.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="font-size:0.75rem;color:#9ca3af;margin-top:8px;">Số liệu theo đơn đã chốt hoa hồng trong kỳ. Bấm vào một đối tác để xem chi tiết.</div>
@endif
