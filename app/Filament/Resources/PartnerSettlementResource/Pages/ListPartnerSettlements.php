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

            Action::make('generate')
                ->label('Sinh bảng đối soát')->icon('heroicon-o-plus')->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
                ->form([
                    Forms\Components\Select::make('partner_id')->label('Đối tác')->required()->searchable()->native(false)
                        ->options(fn () => Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->orderBy('name')->pluck('name', 'id')),
                    Forms\Components\DatePicker::make('period_start')->label('Từ ngày')->required()->native(false)->default(now()->subMonth()->startOfMonth()),
                    Forms\Components\DatePicker::make('period_end')->label('Đến ngày')->required()->native(false)->afterOrEqual('period_start')->default(now()->subMonth()->endOfMonth()),
                    Forms\Components\Toggle::make('only_in_period')->label('Chỉ lấy đơn trong kỳ (bỏ qua đơn của kỳ trước chưa đối soát)')->default(true),
                ])
                ->action(function (array $data) {
                    $partner = Partner::findOrFail($data['partner_id']);
                    $start = Carbon::parse($data['period_start'])->startOfDay();
                    $end = Carbon::parse($data['period_end'])->startOfDay();
                    $service = app(SettlementService::class);
                    $settlement = $service->generate($partner, $start, $end, auth()->user(), (bool) ($data['only_in_period'] ?? true));

                    $settlement
                        ? Notification::make()->success()->title("Đã sinh bảng đối soát {$settlement->code}.")->send()
                        : Notification::make()->warning()->title('Chưa sinh được bảng đối soát')->body($service->whyNoSettlement($partner, $start, $end))->persistent()->send();
                }),
        ];
    }
}
