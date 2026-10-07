<?php

namespace Modules\Payment\App\Filament\Resources\PartnerPayOsAccountResource\Pages;

use App\Models\Partner;
use App\Services\Payment\PartnerPayOsChannelService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Modules\Minihouse\App\Support\HomestayBridge;
use Filament\Resources\Pages\ListRecords;
use Modules\Payment\App\Filament\Resources\PartnerPayOsAccountResource;

class ListPartnerPayOsAccounts extends ListRecords
{
    protected static string $resource = PartnerPayOsAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Phân quyền theo TỪNG đối tác (kể cả đối tác chưa có kênh): bật thì chủ đối tác tự nhập kênh PayOS trên app.
            Actions\Action::make('partnerSetupPermission')
                ->label('Phân quyền đối tác tự nhập')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->form([
                    Forms\Components\Select::make('partner_id')
                        ->label('Đối tác Homestay')
                        ->options(fn () => Partner::query()
                            ->where('partner_type', Partner::TYPE_HOMESTAY)
                            ->where('id', '!=', HomestayBridge::PARTNER_ID)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Partner $partner) => [$partner->id => ($partner->legal_name ?: $partner->name) . ($partner->payos_self_setup_enabled ? ' — đang được tự nhập' : '')]))
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set, ?string $state) => $set('allowed', (bool) Partner::find($state)?->payos_self_setup_enabled)),
                    Forms\Components\Toggle::make('allowed')
                        ->label('Cho phép chủ đối tác tự nhập kênh PayOS')
                        ->helperText('Tắt = chỉ 365home nhập hộ. Kênh đang lưu không bị ảnh hưởng. Đối tác tự đổi kênh thì Super Admin được thông báo.'),
                ])
                ->action(function (array $data) {
                    app(PartnerPayOsChannelService::class)->setPartnerSetupAllowed(Partner::findOrFail($data['partner_id']), (bool) $data['allowed'], auth()->user());
                    Notification::make()->success()->title($data['allowed'] ? 'Đã cho phép đối tác tự nhập kênh PayOS' : 'Đã tắt quyền tự nhập của đối tác')->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
