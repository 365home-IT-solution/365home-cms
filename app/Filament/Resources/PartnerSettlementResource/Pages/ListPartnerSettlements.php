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
            // Bảng đối soát thường tự sinh theo kỳ (lệnh settlements:process); Super Admin sinh tay cho 1 kỳ bất kỳ khi cần.
            Action::make('generate')
                ->label('Sinh bảng đối soát')->icon('heroicon-o-plus')->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
                ->form([
                    Forms\Components\Select::make('partner_id')->label('Đối tác')->required()->searchable()->native(false)
                        ->options(fn () => Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->orderBy('name')->pluck('name', 'id')),
                    Forms\Components\DatePicker::make('period_start')->label('Từ ngày')->required()->native(false)->default(now()->subMonth()->startOfMonth()),
                    Forms\Components\DatePicker::make('period_end')->label('Đến ngày')->required()->native(false)->afterOrEqual('period_start')->default(now()->subMonth()->endOfMonth()),
                ])
                ->action(function (array $data) {
                    $settlement = app(SettlementService::class)->generate(Partner::findOrFail($data['partner_id']), Carbon::parse($data['period_start'])->startOfDay(), Carbon::parse($data['period_end'])->startOfDay(), auth()->user());

                    $settlement
                        ? Notification::make()->success()->title("Đã sinh bảng đối soát {$settlement->code}.")->send()
                        : Notification::make()->warning()->title('Kỳ này không có đơn nào cần đối soát hoặc đã có bảng đối soát.')->send();
                }),
        ];
    }
}
