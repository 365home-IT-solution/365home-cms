<?php

namespace App\Filament\Resources\NotificationFcmResource\Pages;

use App\Filament\Resources\NotificationFcmResource;
use App\Models\Customer;
use App\Models\NotificationFcm;
use App\Models\NotificationFcmRecipient;
use App\Services\FcmService;
use Carbon\Carbon;
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
        $customerIds = $data['customer_ids'] ?? [];
        unset($data['customer_ids']);

        $scheduledAt = isset($data['scheduled_at'])
            ? Carbon::parse($data['scheduled_at'])
            : null;

        $isScheduled = $scheduledAt && $scheduledAt->isFuture();

        if ($isScheduled) {
            // Lưu danh sách người nhận để command dùng khi đến giờ
            $data['recipient_ids'] = $customerIds;
        }

        $record = static::getModel()::create($data);

        if (! $isScheduled && ! empty($customerIds)) {
            $this->sendToCustomers($record, $customerIds);
        }

        return $record;
    }

    private function sendToCustomers(NotificationFcm $record, array $customerIds): void
    {
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
                $fcmService->sendToCustomer($customer, $record->title, $record->body);
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
            'sent_at'    => now(),
        ]);
    }

    protected function afterCreate(): void
    {
        $record      = $this->getRecord();
        $scheduledAt = $record->scheduled_at;

        if ($scheduledAt && $scheduledAt->isFuture()) {
            Notification::make()
                ->info()
                ->title('Đã lên lịch gửi thông báo')
                ->body('Thông báo sẽ được gửi vào lúc ' . $scheduledAt->format('d/m/Y H:i') . '.')
                ->send();

            return;
        }

        $total = $record->sent_count + $record->fail_count;

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
