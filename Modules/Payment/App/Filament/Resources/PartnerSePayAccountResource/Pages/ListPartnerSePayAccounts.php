<?php

namespace Modules\Payment\App\Filament\Resources\PartnerSePayAccountResource\Pages;

use App\Models\Partner;
use App\Services\Payment\Pay2SBankHubService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Modules\Payment\App\Filament\Resources\PartnerSePayAccountResource;
use Modules\Payment\Entities\PartnerPay2sLinkSession;

class ListPartnerSePayAccounts extends ListRecords
{
    protected static string $resource = PartnerSePayAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('linkPay2s')
                ->label('Liên kết ngân hàng Pay2S')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->visible(fn () => app(Pay2SBankHubService::class)->configured())
                ->modalHeading('Liên kết ngân hàng Pay2S cho đối tác')
                ->modalDescription('Mở trang của Pay2S để đối tác nhập thông tin ngân hàng và OTP (OTP chỉ nhập trên trang Pay2S). Xong quay lại đây bấm "Cập nhật kết quả liên kết".')
                ->modalSubmitActionLabel('Mở trang liên kết')
                ->form([
                    Forms\Components\Select::make('partner_id')->label('Đối tác')->required()->searchable()->live()
                        ->options(fn () => Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->orderBy('name')->pluck('name', 'id')),
                    Forms\Components\Select::make('category_id')->label('Chi nhánh')->searchable()
                        ->placeholder('Cả đối tác (tài khoản mặc định)')
                        ->helperText('Chọn chi nhánh để liên kết tài khoản ngân hàng riêng cho chi nhánh đó.')
                        ->options(fn (Forms\Get $get) => $get('partner_id') ? \Modules\Category\Entities\Category::query()->where('partner_id', $get('partner_id'))->orderBy('name')->pluck('name', 'id') : []),
                ])
                ->action(function (array $data) {
                    try {
                        $session = app(Pay2SBankHubService::class)->createSession(Partner::findOrFail($data['partner_id']), auth()->id(), filled($data['category_id'] ?? null) ? (int) $data['category_id'] : null);
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    $this->js('window.open(' . json_encode($session->hosted_link_url) . ', "_blank")');
                    Notification::make()->success()->title('Đã mở trang liên kết Pay2S (hiệu lực 15 phút)')->send();
                }),
            Actions\Action::make('syncPay2sLinks')
                ->label('Cập nhật kết quả liên kết')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => app(Pay2SBankHubService::class)->configured())
                ->action(function () {
                    $service = app(Pay2SBankHubService::class);
                    $done = 0;

                    PartnerPay2sLinkSession::query()->where('applied', false)->whereIn('status', PartnerPay2sLinkSession::PENDING)->where('created_at', '>=', now()->subHour())->get()
                        ->each(function (PartnerPay2sLinkSession $session) use ($service, &$done) {
                            $done += $service->refresh($session)->applied ? 1 : 0;
                        });

                    Notification::make()->success()->title($done ? "Đã liên kết {$done} tài khoản" : 'Chưa có liên kết nào hoàn tất')->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
