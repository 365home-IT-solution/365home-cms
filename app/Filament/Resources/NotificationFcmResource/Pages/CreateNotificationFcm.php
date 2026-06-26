<?php

namespace App\Filament\Resources\NotificationFcmResource\Pages;

use App\Filament\Resources\NotificationFcmResource;
use App\Models\Customer;
use App\Models\NotificationFcm;
use App\Models\NotificationFcmRecipient;
use App\Services\NotificationFcmService;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Payment\Entities\Order;

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
        $sentFor     = $data['sent_for'] ?? 'users';
        $orderId     = $data['order_id'] ?? null;
        unset($data['customer_ids'], $data['order_id']);

        if ($sentFor === 'all') {
            $customerIds = Customer::whereNotNull('token_device')
                ->where('status', Customer::STATUS_ACTIVE)
                ->pluck('id')
                ->toArray();
        }

        $data['type'] = 'manual';

        $scheduledAt = isset($data['scheduled_at'])
            ? Carbon::parse($data['scheduled_at'])
            : null;

        $isScheduled = $scheduledAt && $scheduledAt->isFuture();

        // Xử lý gửi theo đơn hàng
        if ($sentFor === 'order') {
            $order = $orderId ? Order::with('customer')->find($orderId) : null;

            if ($isScheduled) {
                $data['recipient_ids'] = $order ? ['order_id' => $order->id] : [];
            }

            $record = static::getModel()::create($data);

            if (! $isScheduled && $order) {
                $service = app(NotificationFcmService::class);

                if ($order->customer_id && $order->customer?->token_device) {
                    $service->sendToExisting($record, collect([$order->customer]));
                } elseif ($order->device_token) {
                    $service->sendGuestToExisting($record, $order->device_token);
                }
            }

            return $record;
        }

        if ($isScheduled) {
            $data['recipient_ids'] = $customerIds;
        }

        $record = static::getModel()::create($data);

        if (! $isScheduled) {
            $customers = Customer::whereIn('id', $customerIds)
                ->where('status', Customer::STATUS_ACTIVE)
                ->get();

            app(NotificationFcmService::class)->sendToExisting($record, $customers);
        }

        return $record;
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
