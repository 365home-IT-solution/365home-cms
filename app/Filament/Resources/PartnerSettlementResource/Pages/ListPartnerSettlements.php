<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerSettlementResource\Pages;

use App\Filament\Resources\PartnerSettlementResource;
use App\Models\Partner;
use App\Services\SettlementService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListPartnerSettlements extends ListRecords
{
    protected static string $resource = PartnerSettlementResource::class;

    // Bảng xem trước của action "Áp lại hoa hồng": mỗi đối tác — số đơn sẽ sửa, hoa hồng trước/sau, các bảng bị ảnh hưởng và số đơn bị bỏ qua (kèm lý do).
    private function repricePreview(Forms\Get $get): \Illuminate\Support\HtmlString
    {
        $ids  = (array) $get('partner_ids');
        $from = $get('period_start');
        $to   = $get('period_end');
        $rate = $get('rate');

        if (! $ids || ! $from || ! $to || ! is_numeric($rate)) {
            return new \Illuminate\Support\HtmlString('<span class="text-sm text-gray-500">Chọn đối tác, khoảng ngày và tỷ lệ để xem trước.</span>');
        }

        $service = app(\App\Services\CommissionRepriceService::class);
        $money   = fn (int $v) => number_format($v, 0, ',', '.') . 'đ';
        $html    = '';

        foreach (Partner::query()->whereIn('id', $ids)->orderBy('name')->get() as $partner) {
            $plan   = $service->plan($partner, Carbon::parse($from), Carbon::parse($to), (float) $rate, (bool) $get('include_sent'));
            $apply  = $plan->filter(fn ($r) => $r['skip'] === null);
            $skips  = $plan->filter(fn ($r) => $r['skip'] !== null)->groupBy('skip')->map->count();
            $bills  = $apply->pluck('settlement.code')->filter()->unique()->implode(', ');
            $before = (int) $apply->sum('old_commission');
            $after  = (int) $apply->sum(fn ($r) => $r['new_commission'] ?? $r['old_commission']);

            $html .= '<div class="mb-3 text-sm"><strong>' . e($partner->name) . '</strong>: sửa <strong>' . $apply->count() . '</strong>/' . $plan->count() . ' đơn'
                . ' · hoa hồng đơn đã chốt ' . $money($before) . ' → <strong>' . $money($after) . '</strong> (' . ($after >= $before ? '+' : '−') . $money(abs($after - $before)) . ')'
                . ($bills ? '<br>Bảng bị tính lại: ' . e($bills) : '')
                . ($skips->isNotEmpty() ? '<br><span class="text-gray-500">Bỏ qua: ' . e($skips->map(fn ($n, $why) => "{$n} đơn — {$why}")->implode('; ')) . '</span>' : '')
                . '</div>';
        }

        return new \Illuminate\Support\HtmlString($html);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Bảng đối soát của TẤT CẢ đối tác trong 1 chu kỳ (mỗi đối tác có bảng 1 dòng) — cùng file với API
            // GET /api/admin/settlements/cycle/export.
            Action::make('exportCycle')
                ->label('Xuất Excel theo chu kỳ')->icon('heroicon-o-table-cells')->color('gray')
                ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
                ->form(function () {
                    [$from, $to] = app(SettlementService::class)->lastClosedPeriod('biweekly', now());

                    return [
                        Forms\Components\DatePicker::make('period_start')->label('Từ ngày')->required()->native(false)->default($from),
                        Forms\Components\DatePicker::make('period_end')->label('Đến ngày')->required()->native(false)->afterOrEqual('period_start')->default($to),
                    ];
                })
                ->action(function (array $data) {
                    $start = Carbon::parse($data['period_start']);
                    $end = Carbon::parse($data['period_end']);

                    return \Maatwebsite\Excel\Facades\Excel::download(
                        \App\Exports\CycleSettlementExport::for(auth()->user(), $start, $end),
                        \App\Exports\CycleSettlementExport::fileName($start, $end)
                    );
                }),

            // Áp lại tỷ lệ hoa hồng cho các đơn ĐÃ phát sinh (vd 20% → 30% cho cả tháng) — xem
            // App\Services\CommissionRepriceService. Có xem trước ngay trong form, chỉ Super Admin.
            Action::make('repriceCommission')
                ->label('Áp lại hoa hồng')->icon('heroicon-o-calculator')->color('warning')
                ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
                ->modalHeading('Áp lại tỷ lệ hoa hồng cho đơn đã phát sinh')
                ->modalDescription('Sửa tỷ lệ và số hoa hồng trên từng đơn trong khoảng ngày rồi tính lại các bảng đối soát bị ảnh hưởng. Doanh thu không đổi. Đơn thuộc bảng đã chốt, đã xuất hoá đơn hoặc miễn phí tháng đầu luôn được bỏ qua.')
                ->modalSubmitActionLabel('Áp dụng')
                ->modalWidth('3xl')
                ->form([
                    Forms\Components\Select::make('partner_ids')->label('Đối tác')->multiple()->required()->searchable()->preload()->live()
                        ->options(fn () => Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->orderBy('name')->pluck('name', 'id')),
                    Forms\Components\Grid::make(3)->schema([
                        Forms\Components\DatePicker::make('period_start')->label('Từ ngày')->required()->native(false)->live()->default(now()->startOfMonth()),
                        Forms\Components\DatePicker::make('period_end')->label('Đến ngày')->required()->native(false)->live()->afterOrEqual('period_start')->default(now()->endOfMonth()),
                        Forms\Components\TextInput::make('rate')->label('Tỷ lệ hoa hồng mới (%)')->required()->numeric()->minValue(0)->maxValue(100)->live(onBlur: true)->default(30),
                    ]),
                    Forms\Components\Toggle::make('include_sent')->label('Sửa cả bảng ĐÃ GỬI đối tác (đối tác sẽ nhận thông báo điều chỉnh)')->live()->default(false),
                    Forms\Components\Toggle::make('update_partner_rate')->label('Đồng thời đổi tỷ lệ trong hồ sơ đối tác (đơn tạo sau cũng theo tỷ lệ mới)')->default(true),
                    Forms\Components\Placeholder::make('preview')->label('Xem trước')->content(fn (Forms\Get $get) => $this->repricePreview($get)),
                    Forms\Components\Checkbox::make('confirmed')->label('Tôi đã xem trước và xác nhận áp dụng')->accepted()->required(),
                ])
                ->action(function (array $data) {
                    $service = app(\App\Services\CommissionRepriceService::class);
                    $from    = Carbon::parse($data['period_start']);
                    $to      = Carbon::parse($data['period_end']);
                    $orders  = 0;
                    $before  = 0;
                    $after   = 0;
                    $bills   = [];

                    foreach (Partner::query()->whereIn('id', $data['partner_ids'])->get() as $partner) {
                        $result  = $service->apply($partner, $from, $to, (float) $data['rate'], (bool) ($data['include_sent'] ?? false), (bool) ($data['update_partner_rate'] ?? true));
                        $orders += $result['orders'];
                        $before += $result['commission_before'];
                        $after  += $result['commission_after'];
                        $bills   = array_merge($bills, array_column($result['settlements'], 'code'));
                    }

                    Notification::make()->success()->persistent()->title("Đã áp lại hoa hồng {$data['rate']}% cho {$orders} đơn")
                        ->body('Hoa hồng các đơn đã chốt: ' . number_format($before, 0, ',', '.') . 'đ → ' . number_format($after, 0, ',', '.') . 'đ. Bảng đã tính lại: ' . ($bills ? implode(', ', $bills) : 'không có') . '.')
                        ->send();
                }),

            Action::make('generate')
                ->label('Sinh bảng đối soát')->icon('heroicon-o-plus')->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
                ->form([
                    Forms\Components\Select::make('partner_id')->label('Đối tác')->required()->searchable()->native(false)
                        ->options(fn () => Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->orderBy('name')->pluck('name', 'id')),
                    Forms\Components\DatePicker::make('period_start')->label('Từ ngày')->required()->native(false)->default(now()->subMonth()->startOfMonth()),
                    Forms\Components\DatePicker::make('period_end')->label('Đến ngày')->required()->native(false)->afterOrEqual('period_start')->default(now()->subMonth()->endOfMonth()),
                    Forms\Components\Toggle::make('only_in_period')->label('Chỉ lấy đơn trong kỳ (bỏ qua đơn của kỳ trước chưa đối soát)')->default(true)
                        ->hidden(fn (Forms\Get $get) => (bool) $get('merge_existing')),
                    // Cuối tháng muốn đối soát cả tháng dù đã có bảng nửa tháng (vd 01/10–15/10): gộp các bảng nằm trong khoảng ngày vào 1 bảng cả kỳ.
                    Forms\Components\Toggle::make('merge_existing')->label('Gộp các bảng đã có trong khoảng ngày này (vd đã có 01/10–15/10, sinh cả tháng 01/10–31/10)')->live()->default(false)
                        ->helperText('Các bảng cũ trong khoảng sẽ bị thay bằng 1 bảng NHÁP mới gom toàn bộ đơn. Bảng đã chốt, đã xuất hoá đơn hoặc có khiếu nại không gộp được.'),
                    Forms\Components\Toggle::make('merge_sent')->label('Cho phép gộp cả bảng ĐÃ GỬI đối tác (đối tác sẽ nhận thông báo bảng đã bị thay)')->default(false)
                        ->visible(fn (Forms\Get $get) => (bool) $get('merge_existing')),
                ])
                ->action(function (array $data) {
                    $partner = Partner::findOrFail($data['partner_id']);
                    $start = Carbon::parse($data['period_start'])->startOfDay();
                    $end = Carbon::parse($data['period_end'])->startOfDay();
                    $service = app(SettlementService::class);

                    if ($data['merge_existing'] ?? false) {
                        try {
                            $merged = $service->generateMerged($partner, $start, $end, auth()->user(), (bool) ($data['merge_sent'] ?? false));
                            Notification::make()->success()->persistent()->title("Đã sinh bảng cả kỳ {$merged->code}")
                                ->body('Bảng nháp mới gồm ' . $merged->orders_count . ' đơn — kiểm tra rồi bấm "Gửi cho đối tác".')->send();
                        } catch (\DomainException $e) {
                            Notification::make()->warning()->title('Chưa sinh được bảng cả kỳ')->body($e->getMessage())->persistent()->send();
                        }

                        return;
                    }

                    $settlement = $service->generate($partner, $start, $end, auth()->user(), (bool) ($data['only_in_period'] ?? true));

                    $settlement
                        ? Notification::make()->success()->title("Đã sinh bảng đối soát {$settlement->code}.")->send()
                        : Notification::make()->warning()->title('Chưa sinh được bảng đối soát')->body($service->whyNoSettlement($partner, $start, $end))->persistent()->send();
                }),
        ];
    }
}
