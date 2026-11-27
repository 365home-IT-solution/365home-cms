<?php

namespace Modules\Payment\App\Filament\Resources\PaymentCollectionResource\Pages;

use App\Services\Payment\PaymentCollectionService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Payment\App\Filament\Resources\PaymentCollectionResource;

class CreatePaymentCollection extends CreateRecord
{
    protected static string $resource = PaymentCollectionResource::class;

    protected static ?string $title = 'Tạo mã thu tiền';

    protected static bool $canCreateAnother = false;

    public function mount(): void
    {
        parent::mount();

        if (! app(PaymentCollectionService::class)->available()) {
            Notification::make()->warning()->persistent()->title('Chưa tạo được mã thu tiền')
                ->body('Cần bật Pay2S và khai báo tài khoản ngân hàng của 365home ở CMS → Pay2S — cấu hình chung.')->send();
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PaymentCollectionService::class)->create($data, (string) auth()->id());
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(collect($e->errors())->flatten()->first() ?: 'Không tạo được mã thu tiền')->send();

            $this->halt();
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()->success()->title('Đã tạo mã thu tiền')->body('Bấm "Xem QR / gửi khách" ở danh sách để lấy QR và link gửi khách.');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
