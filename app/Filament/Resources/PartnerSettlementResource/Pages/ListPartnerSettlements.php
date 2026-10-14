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
            // Xuất Excel danh sách bảng đối soát của 1 đối tác (mỗi bảng 1 dòng) — cùng file với API GET …/settlements/export.
            Action::make('exportList')
                ->label('Xuất Excel')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->form([
                    Forms\Components\Select::make('partner_id')->label('Đối tác')->required()->searchable()->native(false)
                        ->options(fn () => \App\Models\PartnerSettlement::query()->with('partner:id,name')->get()->pluck('partner.name', 'partner_id')->filter()->unique()->sort())
                        ->default(fn () => auth()->user()?->isSuperAdmin() ? null : auth()->user()?->partner_id)
                        ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false),
                    Forms\Components\DatePicker::make('from')->label('Kỳ kết thúc từ ngày')->native(false),
                    Forms\Components\DatePicker::make('to')->label('Đến ngày')->native(false)->afterOrEqual('from'),
                    Forms\Components\Select::make('status')->label('Trạng thái')->native(false)->options(\App\Models\PartnerSettlement::STATUSES),
                ])
                ->action(function (array $data) {
                    $user = auth()->user();
                    $partner = Partner::findOrFail($user->isSuperAdmin() ? $data['partner_id'] : $user->partner_id);

                    $query = \App\Models\PartnerSettlement::query()->where('partner_id', $partner->id)->orderBy('period_start')->orderBy('id');
                    if (! $user->isSuperAdmin()) {
                        $query->where('status', '!=', \App\Models\PartnerSettlement::STATUS_DRAFT);
                    }
                    $query->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                        ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('period_end', '>=', $v))
                        ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('period_end', '<=', $v));

                    return \Maatwebsite\Excel\Facades\Excel::download(
                        new \App\Exports\PartnerSettlementListExport($partner, $query->get()),
                        'doi-soat-' . \Illuminate\Support\Str::slug($partner->name ?: 'doi-tac') . '-' . now()->format('Ymd') . '.xlsx'
                    );
                }),

            // Bảng đối soát thường tự sinh theo kỳ (lệnh settlements:process); Super Admin sinh tay cho 1 kỳ bất kỳ khi cần.
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
