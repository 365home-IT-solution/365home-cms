<?php

namespace App\Filament\Resources\NotificationFcmResource\Pages;

use App\Filament\Resources\NotificationFcmResource;
use App\Models\Customer;
use App\Models\NotificationFcmRecipient;
use App\Services\FcmService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateNotificationFcm extends CreateRecord
{
    protected static string $resource = NotificationFcmResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        // Tách customer_ids ra trước khi tạo record (không phải cột trong bảng)
        $customerIds = $data['customer_ids'] ?? [];
        unset($data['customer_ids']);

        $record = static::getModel()::create($data);

        if (empty($customerIds)) {
            return $record;
        }

        $customers = Customer::whereIn('id', $customerIds)
            ->whereNotNull('token_device')
            ->where('status', Customer::STATUS_ACTIVE)
            ->get();

        $fcmService = app(FcmService::class);
        $sentCount  = 0;
        $failCount  = 0;

        foreach ($customers as $customer) {
            $status = 'sent';

            try {
                $fcmService->sendToCustomer($customer, $data['title'], $data['body']);
            } catch (\Throwable) {
                $status = 'failed';
            }

            NotificationFcmRecipient::create([
                'notification_fcm_id' => $record->id,
                'customer_id'         => $customer->id,
                'fcm_token'           => $customer->token_device,
                'status'              => $status,
            ]);

            $status === 'sent' ? $sentCount++ : $failCount++;
        }

        $record->update([
            'sent_count' => $sentCount,
            'fail_count' => $failCount,
        ]);

        return $record;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();
        $total  = $record->sent_count + $record->fail_count;

        if ($total === 0) {
            Notification::make()
                ->warning()
                ->title('Không có thiết bị nào nhận được thông báo')
                ->body('Khách hàng được chọn không có token thiết bị hợp lệ.')
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title("Gửi thành công {$record->sent_count}/{$total} thiết bị")
            ->when($record->fail_count > 0, fn ($n) => $n->body("{$record->fail_count} thiết bị không gửi được."))
            ->send();
    }
}
