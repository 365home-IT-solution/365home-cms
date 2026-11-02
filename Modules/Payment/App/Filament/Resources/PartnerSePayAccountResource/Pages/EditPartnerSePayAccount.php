<?php

namespace Modules\Payment\App\Filament\Resources\PartnerSePayAccountResource\Pages;

use App\Services\Payment\Pay2SBankHubService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;
use Modules\Payment\App\Filament\Resources\PartnerSePayAccountResource;
use Modules\Payment\Entities\PartnerPay2sLinkSession;

class EditPartnerSePayAccount extends EditRecord
{
    protected static string $resource = PartnerSePayAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('checkLink')
                ->label('Kiểm tra liên kết')
                ->icon('heroicon-o-check-badge')
                ->color('gray')
                ->hidden(fn () => $this->record->isManual())
                ->action(function () {
                    $result = app(Pay2SBankHubService::class)->verify($this->record->refresh());

                    $lines = collect($result['checks'])->map(fn ($c) => ($c['ok'] === true ? '✅ ' : ($c['ok'] === false ? '❌ ' : '➖ ')) . e($c['label']) . ': ' . e($c['message']))->implode('<br>');

                    Notification::make()
                        ->title($result['linked'] ? 'Tài khoản đã liên kết thành công' : 'Tài khoản CHƯA liên kết xong')
                        ->body(new HtmlString($lines))
                        ->color($result['linked'] ? 'success' : 'danger')
                        ->persistent()
                        ->send();
                }),
            Actions\Action::make('linkPay2s')
                ->label('Liên kết ngân hàng Pay2S')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->visible(fn () => app(Pay2SBankHubService::class)->configured() && ! $this->record->isManual())
                ->requiresConfirmation()
                ->modalHeading('Mở trang liên kết ngân hàng của Pay2S?')
                ->modalDescription('Đối tác nhập thông tin ngân hàng và OTP trên trang Pay2S (hiệu lực 15 phút). Xong quay lại đây bấm "Cập nhật kết quả liên kết". Tài khoản liên kết xong sẽ ghi đè số tài khoản và token webhook của tài khoản này (' . $this->record->scopeLabel() . ').')
                ->action(function () {
                    try {
                        $session = app(Pay2SBankHubService::class)->createSession($this->record->partner, auth()->id(), $this->record->category_id);
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    $this->js('window.open(' . json_encode($session->hosted_link_url) . ', "_blank")');
                    Notification::make()->success()->title('Đã mở trang liên kết Pay2S')->send();
                }),
            Actions\Action::make('syncPay2sLink')
                ->label('Cập nhật kết quả liên kết')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => app(Pay2SBankHubService::class)->configured() && ! $this->record->isManual())
                ->action(function () {
                    $service = app(Pay2SBankHubService::class);
                    $session = PartnerPay2sLinkSession::query()->where('partner_id', $this->record->partner_id)->where('category_id', $this->record->category_id)->latest('id')->first();

                    if (! $session) {
                        Notification::make()->warning()->title('Chưa có phiên liên kết nào cho đối tác này')->send();

                        return;
                    }

                    $session = $service->refresh($session);

                    if ($session->applied) {
                        $this->record->refresh();
                        $this->fillForm();
                        Notification::make()->success()->title('Đã liên kết xong — thông tin tài khoản đã được cập nhật')->send();
                    } elseif ($session->isPending()) {
                        Notification::make()->warning()->title('Đối tác chưa hoàn tất bước OTP (trạng thái: ' . $session->status . ')')->send();
                    } else {
                        Notification::make()->danger()->title('Liên kết không thành công (' . $session->status . ')')->body($session->error_message)->send();
                    }
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return PartnerSePayAccountResource::normalize($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
