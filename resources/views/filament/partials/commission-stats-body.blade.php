{{-- Thân thống kê hoa hồng dùng chung cho trang Quản lý › Thống kê hoa hồng và tab chi tiết ở Dashboard. Nhận: $stats, $emptyMessage. --}}
@php
    // $stats và (tuỳ chọn) $emptyMessage do nơi include truyền vào.
    $emptyMessage = $emptyMessage ?? 'Chưa có dữ liệu.';
    $money = fn ($v) => \App\Filament\Pages\CommissionStats::money($v);
    $isPlatform = ($stats['scope'] ?? null) === 'platform';
    $s = $stats['summary'] ?? [];

    $card = function (string $label, $value, ?string $hint = null, string $color = '#111827') {
        return compact('label', 'value', 'hint', 'color');
    };

    $cards = $isPlatform ? [
        $card('Hoa hồng thu được', $money($s['commission'] ?? 0), ($s['orders'] ?? 0) . ' đơn đã chốt · tỉ lệ thực tế ' . ($s['effective_commission_rate'] ?? 0) . '%', '#10b981'),
        $card('365home bù khuyến mãi', $money($s['platform_subsidy'] ?? 0), 'Khoản giảm giá do nền tảng chịu', '#f59e0b'),
        $card('Thu nhập ròng của nền tảng', $money($s['platform_net_income'] ?? 0), 'Hoa hồng − khoản bù', '#1f4e79'),
        $card('Doanh thu tính hoa hồng', $money($s['revenue_base'] ?? 0), 'Khách trả ' . $money($s['customer_paid'] ?? 0) . ' + bù ' . $money($s['platform_subsidy'] ?? 0)),
        $card('Đối tác thực nhận', $money($s['partner_receives'] ?? 0), 'Doanh thu tính hoa hồng − hoa hồng'),
        $card('Tiền 365home thu hộ', $money($s['platform_collected'] ?? 0), 'Đơn thanh toán qua kênh của nền tảng'),
        $card('Đơn miễn hoa hồng', (string) ($s['waived_orders'] ?? 0), 'Doanh thu miễn: ' . $money($s['waived_revenue_base'] ?? 0)),
        $card('Chênh lệch đối soát kỳ', $money($s['settlement_net'] ?? 0), 'Hoa hồng − bù − thu hộ (dương: đối tác nộp)'),
    ] : [
        $card('Doanh thu tính hoa hồng', $money($s['revenue_base'] ?? 0), ($s['orders'] ?? 0) . ' đơn đã chốt'),
        $card('Hoa hồng phải trả nền tảng', $money($s['commission'] ?? 0), 'Tỉ lệ thực tế ' . ($s['effective_commission_rate'] ?? 0) . '%', '#dc2626'),
        $card('365home bù khuyến mãi cho bạn', $money($s['platform_subsidy'] ?? 0), 'Khoản giảm giá do nền tảng chịu', '#f59e0b'),
        $card('Thực nhận', $money($s['partner_receives'] ?? 0), 'Doanh thu − hoa hồng', '#10b981'),
        $card('Đơn miễn hoa hồng', (string) ($s['waived_orders'] ?? 0), 'Doanh thu miễn: ' . $money($s['waived_revenue_base'] ?? 0)),
        $card('Phải nộp cho nền tảng', $money($s['amount_to_pay_platform'] ?? 0), 'Theo các đơn đã chốt trong kỳ', '#1f4e79'),
        $card('Nền tảng sẽ chi cho bạn', $money($s['amount_platform_pays'] ?? 0), 'Khi bù khuyến mãi/thu hộ vượt hoa hồng'),
    ];

    $th = 'padding:8px 10px;text-align:left;font-size:0.72rem;color:#6b7280;text-transform:uppercase;border-bottom:1px solid #e5e7eb;';
    $thr = $th . 'text-align:right;';
    $td = 'padding:8px 10px;font-size:0.85rem;border-bottom:1px solid #f3f4f6;';
    $tdr = $td . 'text-align:right;white-space:nowrap;';
@endphp
@if (! $stats)
        <div style="border:1px dashed #d1d5db;border-radius:12px;padding:24px;text-align:center;color:#6b7280;">
            {{ $emptyMessage }}
        </div>
    @else
        <div style="font-size:0.85rem;color:#6b7280;">
            Kỳ: <strong>{{ $stats['period']['label'] }}</strong> — tính theo <strong>ngày khách trả phòng</strong> của các đơn đã chốt hoa hồng (đơn hoàn tiền: theo ngày hoàn).
            @if (($s['unsettled_orders'] ?? 0) > 0) <span style="color:#92400e;">· {{ $s['unsettled_orders'] }} đơn trong kỳ chưa vào bảng đối soát (sẽ vào kỳ kế tiếp).</span> @endif
            @if (! $isPlatform && isset($stats['partner']))
                · {{ $stats['partner']['name'] }} · hoa hồng hợp đồng {{ $stats['partner']['commission_rate'] }}%
                @if ($stats['partner']['fee_free_active']) · <span style="color:#10b981;font-weight:700;">đang miễn hoa hồng đến {{ \Illuminate\Support\Carbon::parse($stats['partner']['fee_free_until'])->format('d/m/Y') }}</span> @endif
            @endif
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;">
            @foreach ($cards as $c)
                <div style="border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;background:#fff;">
                    <div style="font-size:0.78rem;color:#6b7280;">{{ $c['label'] }}</div>
                    <div style="font-size:1.35rem;font-weight:800;color:{{ $c['color'] }};margin-top:2px;">{{ $c['value'] }}</div>
                    @if ($c['hint'])<div style="font-size:0.72rem;color:#9ca3af;margin-top:2px;">{{ $c['hint'] }}</div>@endif
                </div>
            @endforeach
        </div>

        <x-filament::section heading="Công nợ đối soát đang mở" description="Không phụ thuộc kỳ lọc.">
            @php $r = $stats['receivables']; @endphp
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;font-size:0.9rem;">
                <div>{{ $isPlatform ? 'Đối tác phải nộp' : 'Bạn phải nộp' }}: <strong>{{ $money($r['to_collect_amount']) }}</strong> ({{ $r['to_collect_count'] }} bảng)</div>
                <div style="color:#dc2626;">Quá hạn: <strong>{{ $money($r['overdue_amount']) }}</strong> ({{ $r['overdue_count'] }} bảng)</div>
                <div>{{ $isPlatform ? '365home phải chi' : '365home sẽ chi cho bạn' }}: <strong>{{ $money($r['to_pay_out_amount']) }}</strong> ({{ $r['to_pay_out_count'] }} bảng)</div>
                <div>Đang khiếu nại: <strong>{{ $r['disputed_count'] }}</strong> bảng</div>
            </div>
        </x-filament::section>

        @if ($isPlatform)
            <x-filament::section heading="Theo đối tác" description="Xếp theo hoa hồng giảm dần.">
                <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;">
                    <thead><tr>
                        <th style="{{ $th }}">Đối tác</th><th style="{{ $thr }}">Đơn</th><th style="{{ $thr }}">Doanh thu tính HH</th><th style="{{ $thr }}">Hoa hồng</th>
                        <th style="{{ $thr }}">Bù KM</th><th style="{{ $thr }}">Thu nhập ròng</th><th style="{{ $thr }}">Miễn HH</th><th style="{{ $thr }}">Đối soát</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($stats['by_partner'] as $row)
                            <tr>
                                <td style="{{ $td }}">{{ $row['name'] ?? $row['partner_id'] }}</td>
                                <td style="{{ $tdr }}">{{ $row['orders'] }}</td>
                                <td style="{{ $tdr }}">{{ $money($row['revenue_base']) }}</td>
                                <td style="{{ $tdr }}">{{ $money($row['commission']) }}</td>
                                <td style="{{ $tdr }}">{{ $money($row['platform_subsidy']) }}</td>
                                <td style="{{ $tdr }}">{{ $money($row['platform_net_income']) }}</td>
                                <td style="{{ $tdr }}">{{ $row['waived_orders'] }}</td>
                                <td style="{{ $tdr }}">{{ $money($row['settlement_net']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" style="{{ $td }}color:#9ca3af;">Chưa có đơn đã chốt hoa hồng trong kỳ.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </x-filament::section>
        @endif

        <x-filament::section heading="Theo tháng">
            <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;">
                <thead><tr>
                    <th style="{{ $th }}">Tháng</th><th style="{{ $thr }}">Đơn</th><th style="{{ $thr }}">Doanh thu tính HH</th><th style="{{ $thr }}">Hoa hồng</th>
                    <th style="{{ $thr }}">Bù KM</th><th style="{{ $thr }}">{{ $isPlatform ? 'Thu nhập ròng' : 'Thực nhận' }}</th>
                </tr></thead>
                <tbody>
                    @forelse ($stats['by_month'] as $row)
                        <tr>
                            <td style="{{ $td }}">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $row['month'])->format('m/Y') }}</td>
                            <td style="{{ $tdr }}">{{ $row['orders'] }}</td>
                            <td style="{{ $tdr }}">{{ $money($row['revenue_base']) }}</td>
                            <td style="{{ $tdr }}">{{ $money($row['commission']) }}</td>
                            <td style="{{ $tdr }}">{{ $money($row['platform_subsidy']) }}</td>
                            <td style="{{ $tdr }}">{{ $money($isPlatform ? $row['platform_net_income'] : $row['partner_receives']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="{{ $td }}color:#9ca3af;">Chưa có dữ liệu.</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </x-filament::section>

        <x-filament::section heading="Theo nơi nhận tiền" description="Tiền khách trả về thẳng đối tác, do 365home thu hộ, hay thu tại quầy.">
            <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;">
                <thead><tr><th style="{{ $th }}">Nơi nhận</th><th style="{{ $thr }}">Đơn</th><th style="{{ $thr }}">Doanh thu tính HH</th><th style="{{ $thr }}">Hoa hồng</th><th style="{{ $thr }}">365home thu hộ</th></tr></thead>
                <tbody>
                    @foreach ($stats['by_collected'] as $row)
                        <tr>
                            <td style="{{ $td }}">{{ $row['label'] }}</td>
                            <td style="{{ $tdr }}">{{ $row['orders'] }}</td>
                            <td style="{{ $tdr }}">{{ $money($row['revenue_base']) }}</td>
                            <td style="{{ $tdr }}">{{ $money($row['commission']) }}</td>
                            <td style="{{ $tdr }}">{{ $money($row['platform_collected']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </x-filament::section>

        <x-filament::section heading="Bảng đối soát có kỳ kết thúc trong khoảng đã chọn">
            <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;">
                <thead><tr><th style="{{ $th }}">Trạng thái</th><th style="{{ $thr }}">Số bảng</th><th style="{{ $thr }}">Hoa hồng</th><th style="{{ $thr }}">Bù KM</th><th style="{{ $thr }}">Phải nộp / (chi)</th></tr></thead>
                <tbody>
                    @foreach ($stats['settlements'] as $row)
                        <tr>
                            <td style="{{ $td }}">{{ $row['label'] }}</td>
                            <td style="{{ $tdr }}">{{ $row['count'] }}</td>
                            <td style="{{ $tdr }}">{{ $money($row['commission_total']) }}</td>
                            <td style="{{ $tdr }}">{{ $money($row['subsidy_total']) }}</td>
                            <td style="{{ $tdr }}">{{ $money($row['net_amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </x-filament::section>

        <x-filament::section heading="Ký quỹ">
            @php $e = $stats['escrow']; @endphp
            @if ($isPlatform)
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;font-size:0.9rem;">
                    <div>Tổng ký quỹ đang giữ: <strong>{{ $money($e['total_balance']) }}</strong></div>
                    <div>Đối tác: <strong>{{ $e['partners_count'] }}</strong></div>
                    <div>Dưới mức tối thiểu: <strong>{{ $e['below_min_count'] }}</strong></div>
                    <div>Tạm ngưng bán: <strong>{{ $e['suspended_count'] }}</strong> · Đã chấm dứt: <strong>{{ $e['terminated_count'] }}</strong></div>
                </div>
            @else
                <div style="font-size:0.9rem;">Số dư ký quỹ: <strong>{{ $money($e['balance']) }}</strong>@if ($e['min_amount'] !== null) · mức tối thiểu <strong>{{ $money($e['min_amount']) }}</strong>@endif</div>
            @endif
        </x-filament::section>
    @endif
