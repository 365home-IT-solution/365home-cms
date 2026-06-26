<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Modules\BladeThemeV1\Services\AccessCode\AccessCodeService;
use Modules\Payment\App\Filament\Resources\OrderResource;
use PayOS\PayOS;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected ?string $payosCheckoutUrl = null;

    protected function afterCreate(): void
    {
        $record = $this->record->fresh(['items.product']);

        // PayOS (chuyển khoản) + amount >= 2000 → redirect sang QR thanh toán (tất cả roles)
        if ($record->payment_method === 'PayOS' && (int) $record->amount >= 2000) {
            $record->update(['status' => 'pending']);

            // Nếu đơn đã có checkout_url (được tạo trước) → dùng lại, không tạo mới
            if (! empty($record->checkout_url)) {
                $this->payosCheckoutUrl = $record->checkout_url;
                return;
            }

            $this->createAdminPayosLink($record);
            return;
        }

        // Tiền mặt hoặc amount < 2000 → luồng bình thường
        if ($record->status !== 'paid') {
            return;
        }

        if ($record->hasAccessCode()) {
            return;
        }

        try {
            $firstItem    = $record->items->sortBy('checkin_date')->first();
            $checkinDate  = $record->items->min('checkin_date');
            $checkoutDate = $record->items->max('checkout_date');
            $product      = $firstItem?->product;

            /** @var AccessCodeService $service */
            $service = app(AccessCodeService::class);
            $code = $service->assignCodeToOrder(
                $record->id,
                $record->category_id,
                $checkinDate,
                $checkoutDate,
                $product,
            );

            Notification::make()
                ->title('Đã gán mã cổng: ' . $code->code)
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Tạo đơn thành công nhưng chưa gán được mã cổng')
                ->body($e->getMessage())
                ->warning()
                ->send();
        }
    }

    private function createAdminPayosLink(\Modules\Payment\Entities\Order $record): void
    {
        try {
            $clientId    = Config::get('payos.client_id');
            $apiKey      = Config::get('payos.api_key');
            $checksumKey = Config::get('payos.checksum_key');

            if (! $clientId || ! $apiKey || ! $checksumKey) {
                Notification::make()
                    ->title('PayOS chưa được cấu hình')
                    ->body('Đơn đã lưu. Vui lòng liên hệ admin để thanh toán.')
                    ->warning()
                    ->send();
                return;
            }

            $payOS   = new PayOS($clientId, $apiKey, $checksumKey);
            $editUrl = static::getResource()::getUrl('edit', ['record' => $record->id]);

            $response = $payOS->createPaymentLink([
                'orderCode'   => (int) $record->order_code,
                'amount'      => (int) $record->amount,
                'description' => 'TT don ' . $record->order_code,
                'returnUrl'   => $editUrl . '?payment_status=success',
                'cancelUrl'   => $editUrl . '?payment_status=cancelled',
                'buyerName'   => $record->buyer_name ?? '',
                'buyerPhone'  => $record->buyer_phone ?? '',
                'expiredAt'   => now()->addMinutes(15)->timestamp,
                'items'       => [[
                    'name'     => 'Dat phong - ' . ($record->items->first()?->name ?? 'Phong'),
                    'quantity' => 1,
                    'price'    => (int) $record->amount,
                ]],
            ]);

            $checkoutUrl = $response['checkoutUrl'] ?? null;

            if (! $checkoutUrl) {
                Notification::make()
                    ->title('Không thể tạo link thanh toán PayOS')
                    ->warning()
                    ->send();
                return;
            }

            // Lưu vào DB để tránh tạo lại link khi admin reload
            $record->update(['checkout_url' => $checkoutUrl]);

            Log::info('Admin PayOS link created', [
                'order_id'   => $record->id,
                'order_code' => $record->order_code,
                'amount'     => $record->amount,
            ]);

            $this->payosCheckoutUrl = $checkoutUrl;

        } catch (\Exception $e) {
            Log::error('Admin PayOS link error', [
                'order_id' => $record->id ?? null,
                'error'    => $e->getMessage(),
            ]);

            Notification::make()
                ->title('Lỗi tạo link thanh toán')
                ->body($e->getMessage())
                ->warning()
                ->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        if ($this->payosCheckoutUrl) {
            return $this->payosCheckoutUrl;
        }

        return static::getResource()::getUrl('index');
    }
}
