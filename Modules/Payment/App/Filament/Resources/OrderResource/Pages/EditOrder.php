<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Pages;

use Modules\Payment\App\Filament\Resources\OrderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Modules\BladeThemeV1\Services\AccessCode\AccessCodeService;
use Modules\BladeThemeV1\Services\Zns\ZaloZnsService;
use Modules\BladeThemeV1\Services\OcrSpaceService;
use Modules\Payment\App\Services\CccdScannerService;
use App\Services\CccdDeclarationService;
use App\Services\OrderRealtimeService;
use App\Services\ExtraChargeService;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Payment\App\Filament\Resources\OrderResource\Concerns\HasTimeslotGridSelection;
use Modules\Payment\App\Filament\Resources\OrderResource\Forms\OrderForm;
use Modules\Payment\Entities\OrderGuestCccd;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PayOS\PayOS;

class EditOrder extends EditRecord
{
    use HasTimeslotGridSelection;

    protected static string $resource = OrderResource::class;

    // resources/js/echo-admin.js gắn thẳng window.Echo.private(...).listen() rồi tự gọi
    // Livewire.dispatch('timeslotHoldsChanged') — KHÔNG dùng cú pháp khai báo
    // #[On('echo-private:...')] (từng bị lỗi im lặng do thứ tự nạp script/window.Echo chưa sẵn
    // sàng lúc Livewire boot, khiến real-time không bao giờ tới nơi). Method no-op này chỉ để ép
    // Livewire render lại, lưới NGÀY × KHUNG GIỜ tự đọc lại DB mới nhất qua
    // OrderForm::getTimeslotGridData() (đã tự query TimeslotHold ở đó).
    #[\Livewire\Attributes\On('timeslotHoldsChanged')]
    public function onTimeslotHoldsChanged(): void {}

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $paymentStatus = request()->query('payment_status');

        if ($paymentStatus === 'success') {
            Notification::make()
                ->title('Thanh toán thành công')
                ->body('Đơn hàng đang được xử lý. Trạng thái sẽ cập nhật tự động khi PayOS xác nhận.')
                ->success()
                ->send();
        } elseif ($paymentStatus === 'cancelled') {
            // Webhook có thể chưa kịp fire — cập nhật trực tiếp khi admin quay lại
            if ($this->record->status === 'pending') {
                $this->record->update(['status' => 'failed']);
            }
            Notification::make()
                ->title('Thanh toán đã bị huỷ')
                ->body('Đơn đã lưu nhưng chưa được thanh toán. Trạng thái: Hủy thanh toán.')
                ->warning()
                ->send();
        }
    }

    // Repeater 'orderItems' KHÔNG còn dùng ->relationship('items') (xem OrderForm.php) — phải tự
    // tay nạp lại state ban đầu từ các order_item thật. Logic gộp nhóm/khớp khung giờ dùng chung
    // với action "Xem chi tiết" ở trang danh sách (xem OrderForm::buildOrderItemsFormState()), vì
    // Repeater không có ->relationship() thì BẤT KỲ nơi nào render OrderForm::form() cho 1 đơn đã
    // tồn tại cũng phải tự đổ dữ liệu vào 'orderItems', không riêng gì trang sửa đơn này.
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['orderItems'] = OrderForm::buildOrderItemsFormState($this->record);

        // Nạp lại khách đi cùng đã lưu (order_guest_cccds, guest_index=2,3,4...) vào Repeater
        // 'guest_cccds' — field ảo, không map cột nào nên phải tự đổ lại giống orderItems ở trên.
        $data['guest_cccds'] = $this->record->guestCccds
            ->sortBy('guest_index')
            ->values()
            ->map(fn ($guest) => [
                'cccd_front' => $guest->cccd_front,
                'cccd_back'  => $guest->cccd_back,
            ])
            ->all();

        // 'booking_partner_id' là field ẢO (dehydrated(false), chỉ dùng để LỌC "Chi nhánh" theo
        // đúng đối tác — xem OrderForm.php) nên KHÔNG có sẵn trong $data (không map cột nào). Nếu
        // không tự điền lại giá trị này ngay ở đây, field hiện rỗng khi mở sửa đơn đã tạo, khiến
        // "Chi nhánh" bên dưới không lọc được options() (rỗng vì thiếu partner), làm Select không
        // tìm được nhãn cho category_id đã lưu và hiện NHẦM thành số ID thô thay vì tên chi nhánh.
        $data['booking_partner_id'] = $this->record->partner_id;

        return $data;
    }

    // Repeater 'orderItems' không còn tự sync qua ->relationship('items') — xóa hết order_item cũ
    // rồi tạo lại từ dữ liệu form mới nhất (đơn giản, đáng tin cậy hơn dò diff từng dòng khi 1
    // dòng có thể tách thành nhiều order_item qua 'selected_slots'). RoomCleaningLog.order_item_id
    // đã cấu hình nullOnDelete nên không lỗi khi order_item bị xóa.
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $orderItems = $data['orderItems'] ?? [];
        $orderServices = $data['orderServices'] ?? ($this->data['orderServices'] ?? null);
        $guestCccds = $data['guest_cccds'] ?? [];
        unset($data['orderItems'], $data['orderServices'], $data['guest_cccds']);

        // Đồng bộ lại partner_id nếu admin đổi "Chi nhánh" khi sửa đơn — category LUÔN xác định
        // đúng đối tác sở hữu, giống hệt lý do áp dụng ở CreateOrder::mutateFormDataBeforeCreate().
        // Thiếu bước này thì đổi chi nhánh sang 1 đối tác khác vẫn giữ nguyên partner_id cũ (sai
        // đối tác hưởng doanh thu/hoa hồng cho đơn đó).
        if (! empty($data['category_id'])) {
            $category = \Modules\Category\Entities\Category::find($data['category_id']);
            if ($category) {
                $data['partner_id'] = $category->partner_id;
            }
        }

        $newStatus = (string) ($data['status'] ?? $record->status);
        $newAmount = (int) ($data['amount'] ?? $record->amount ?? 0);
        $oldAmount = (int) ($record->amount ?? 0);

        if (! in_array($newStatus, ['paid', 'deposit'], true)) {
            $data['full_amount'] = $newAmount;
            $data['extra_charge_amount'] = null;
            $data['extra_charge_payos_code'] = null;
            $data['extra_charge_checkout_url'] = null;
            $data['extra_charge_qr_code'] = null;
            $data['extra_charge_payment_method'] = null;
            $data['extra_charge_paid_at'] = null;
            $data['extra_charge_expired_at'] = null;

            if ($newAmount !== $oldAmount) {
                $data['checkout_url'] = null;
                $data['qr_code'] = null;
                $data['current_payos_code'] = null;
            }
        }
        $record->update($data);

        // Khách đi cùng (order_guest_cccds, guest_index=2,3,4...) — updateOrCreate() theo guest_index
        // để GIỮ NGUYÊN cccd_data đã quét trước đó nếu ảnh không đổi (không xoá-tạo-lại như
        // orderItems bên dưới, vì sẽ buộc quét OCR lại mỗi lần sửa đơn dù ảnh không đổi gì). Xoá
        // đúng các guest_index không còn trong form nữa (admin giảm số khách/xoá dòng).
        $keepGuestIndexes = [];
        foreach (array_values(is_array($guestCccds) ? $guestCccds : []) as $i => $guest) {
            $guestIndex = $i + 2;
            $keepGuestIndexes[] = $guestIndex;
            $front = $guest['cccd_front'] ?? null;
            $back  = $guest['cccd_back'] ?? null;

            if (! $front && ! $back) {
                continue;
            }

            OrderGuestCccd::updateOrCreate(
                ['order_id' => $record->id, 'guest_index' => $guestIndex],
                ['cccd_front' => $front, 'cccd_back' => $back]
            );
        }

        $staleGuestCccds = OrderGuestCccd::where('order_id', $record->id)->where('guest_index', '>=', 2);
        if (! empty($keepGuestIndexes)) {
            $staleGuestCccds->whereNotIn('guest_index', $keepGuestIndexes);
        }
        $staleGuestCccds->delete();

        $expandedItems = OrderForm::expandOrderItemsForPersistence(is_array($orderItems) ? $orderItems : []);

        $record->items()->delete();

        foreach ($expandedItems as $item) {
            unset($item['id']);
            $record->items()->create($item);
        }

        if (is_array($orderServices)) {
            $record->services()->delete();

            foreach ($orderServices as $service) {
                $serviceId = $service['service_id'] ?? null;
                $serviceId = filled($serviceId) ? (int) $serviceId : null;
                $price = (int) ($service['price'] ?? 0);
                $quantity = max(1, (int) ($service['quantity'] ?? 1));
                $subtotal = (int) ($service['subtotal'] ?? ($price * $quantity));
                $name = $service['service_name'] ?? null;

                if (! $serviceId && blank($name) && $subtotal <= 0) {
                    continue;
                }

                if (blank($name) && $serviceId) {
                    $name = \Modules\BladeThemeV1\App\Models\AdditionService::find($serviceId)?->name;
                }

                $record->services()->create([
                    'service_id' => $serviceId,
                    'service_name' => $name ?: 'Dich vu',
                    'price' => $price,
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                ]);
            }
        }

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            // =========================================================
            // TEST: Giả lập thanh toán cọc thành công (không cần QR)
            // =========================================================
            Actions\Action::make('simulateDepositPaid')
                ->label('⚡ Giả lập: Cọc đã thanh toán')
                ->icon('heroicon-m-beaker')
                ->color('warning')
                ->visible(function () {
                    $record = $this->record;
                    return app()->isLocal()
                        || config('app.debug')
                        || (auth()->user()?->isSuperAdmin() ?? false);
                })
                ->visible(fn () => $this->record->status === 'pending')
                ->requiresConfirmation()
                ->modalHeading('Giả lập thanh toán cọc thành công')
                ->modalDescription(function () {
                    $r = $this->record;
                    $depositPct = $r->deposit_percent;
                    $fullAmt    = (int) $r->full_amount;
                    $amountPaid = $r->depositDueAmount();
                    if ($depositPct) {
                        $remaining  = $fullAmt - $amountPaid;
                        return "Giả lập khách đã quét QR và thanh toán CỌC {$depositPct}% = "
                            . number_format($amountPaid, 0, ',', '.') . "đ.\n"
                            . "Còn lại " . number_format($remaining, 0, ',', '.') . "đ thanh toán khi nhận phòng.\n"
                            . "Hệ thống sẽ: đánh dấu PAID → gán mã cổng → gửi ZNS cho khách.";
                    }
                    return "Giả lập khách đã thanh toán " . number_format($amountPaid, 0, ',', '.') . "đ.\n"
                        . "Hệ thống sẽ: đánh dấu PAID → gán mã cổng → gửi ZNS cho khách.";
                })
                ->modalSubmitActionLabel('Xác nhận giả lập')
                ->action(function () {
                    $record = $this->record->fresh(['items']);

                    try {
                        // 1. Đánh dấu đã thanh toán
                        $record->update(['status' => 'paid']);

                        // 2. Gán mã cổng
                        $firstItem    = $record->items->sortBy('checkin_date')->first();
                        $checkinDate  = $record->items->min('checkin_date');
                        $checkoutDate = $record->items->max('checkout_date');
                        $product      = $firstItem?->product;

                        $accessCodeService = app(AccessCodeService::class);
                        $accessCode = $accessCodeService->assignCodeToOrder(
                            $record->id,
                            $record->category_id,
                            $checkinDate,
                            $checkoutDate,
                            $product,
                        );

                        // 3. Gửi ZNS cho khách
                        try {
                            $znsService = app(ZaloZnsService::class);
                            $znsService->sendBookingSuccessNotification($record, $accessCode);
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::warning('simulateDepositPaid: ZNS failed', ['error' => $e->getMessage()]);
                        }

                        $depositPct  = $record->deposit_percent;
                        $fullAmt     = (int) $record->full_amount;
                        $amountPaid  = $record->depositDueAmount();
                        $remaining   = $fullAmt - $amountPaid;

                        $body = $depositPct
                            ? "Đã nhận cọc {$depositPct}% = " . number_format($amountPaid, 0, ',', '.') . "đ. "
                              . "Còn lại " . number_format($remaining, 0, ',', '.') . "đ khi nhận phòng."
                            : "Đã thanh toán đủ " . number_format($amountPaid, 0, ',', '.') . "đ. Mã cổng đã gửi qua Zalo.";

                        Notification::make()
                            ->title('Giả lập thành công')
                            ->body($body)
                            ->success()
                            ->send();

                        $this->redirect($this->getResource()::getUrl('edit', ['record' => $record]));

                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Lỗi giả lập')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            // =========================================================
            // Thanh toán lại — đơn PayOS bị "failed"/"cancelled_payment": tạo QR mới gửi khách,
            // đơn tự chuyển về "pending" chờ thanh toán. Khi khách quét & trả tiền, webhook PayOS
            // chung (PaymentController::handlePaidStatus) tự chuyển "paid"/"deposit" như bình
            // thường — action này chỉ lo phần tạo lại QR + đổi trạng thái.
            // =========================================================
            Actions\Action::make('retryPayment')
                ->label('Thanh toán lại')
                ->icon('heroicon-m-qr-code')
                ->color('warning')
                ->visible(fn () => in_array($this->record->status, ['failed', 'cancelled_payment'], true)
                    && $this->record->payment_method === 'PayOS')
                ->requiresConfirmation()
                ->modalHeading('Tạo lại QR thanh toán')
                ->modalDescription(function () {
                    $dueNow = $this->record->depositDueAmount();
                    return 'Tạo QR PayOS mới cho số tiền ' . number_format($dueNow, 0, ',', '.')
                        . 'đ và gửi lại cho khách. Đơn sẽ chuyển về trạng thái "Đang xử lý" (pending).';
                })
                ->modalSubmitActionLabel('Tạo QR mới')
                ->action(function () {
                    $record = $this->record->fresh(['items']);
                    $dueNow = $record->depositDueAmount();

                    if ($dueNow < 2000) {
                        Notification::make()->title('Số tiền thanh toán không đủ tối thiểu')->danger()->send();
                        return;
                    }

                    $clientId    = Config::get('payos.client_id');
                    $apiKey      = Config::get('payos.api_key');
                    $checksumKey = Config::get('payos.checksum_key');

                    if (! $clientId || ! $apiKey || ! $checksumKey) {
                        Notification::make()->title('Cổng thanh toán chưa được cấu hình')->danger()->send();
                        return;
                    }

                    $payOS = new PayOS($clientId, $apiKey, $checksumKey);

                    $oldPayosCode = $record->current_payos_code ?? (int) $record->order_code;
                    try {
                        $payOS->cancelPaymentLink((int) $oldPayosCode);
                    } catch (\Throwable $e) {
                        Log::info('EditOrder retryPayment: cancel old PayOS link skipped', [
                            'order_code' => $record->order_code,
                            'reason'     => $e->getMessage(),
                        ]);
                    }

                    $itemName     = $record->items->first()?->name ?? 'Đặt phòng';
                    $newPayosCode = (int) (intval(substr(strval(microtime(true) * 10000), -6)) . rand(10, 99));
                    $expiredAt    = now()->addMinutes(15);

                    try {
                        $response = $payOS->createPaymentLink([
                            'orderCode'   => $newPayosCode,
                            'amount'      => $dueNow,
                            'description' => 'TT lai don ' . $record->order_code,
                            'returnUrl'   => route('payment.success') . '?orderCode=' . $record->order_code,
                            'cancelUrl'   => route('payment.cancel') . '?orderCode=' . $record->order_code,
                            'buyerName'   => $record->buyer_name ?? '',
                            'buyerPhone'  => $record->buyer_phone ?? '',
                            'expiredAt'   => $expiredAt->timestamp,
                            'items'       => [['name' => $itemName, 'quantity' => 1, 'price' => $dueNow]],
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('EditOrder retryPayment: createPaymentLink failed', [
                            'order_code' => $record->order_code,
                            'error'      => $e->getMessage(),
                        ]);
                        Notification::make()->title('Không thể tạo lại link thanh toán')->body($e->getMessage())->danger()->send();
                        return;
                    }

                    $checkoutUrl = $response['checkoutUrl'] ?? null;
                    $qrCode      = $response['qrCode'] ?? null;

                    if (! $checkoutUrl) {
                        Notification::make()->title('Không thể tạo lại link thanh toán')->danger()->send();
                        return;
                    }

                    $record->update([
                        'status'             => 'pending',
                        'checkout_url'       => $checkoutUrl,
                        'qr_code'            => $qrCode,
                        'expired_at'         => $expiredAt,
                        'current_payos_code' => $newPayosCode,
                    ]);

                    AuditLogger::log(
                        'update', 'Order', $record, [],
                        ['Tạo lại QR thanh toán' => number_format($dueNow, 0, ',', '.') . 'đ'],
                        'Đơn #' . $record->order_code,
                    );

                    Notification::make()
                        ->title('Đã tạo QR thanh toán mới')
                        ->body('Đơn chuyển về "Đang xử lý" — gửi QR mới cho khách để thanh toán lại.')
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('edit', ['record' => $record]));
                }),

            Actions\Action::make('assignAccessCode')
                ->label(fn () => $this->record->hasAccessCode() ? 'Cấp lại mã cổng' : 'Gán mã cổng')
                ->icon(fn () => $this->record->hasAccessCode() ? 'heroicon-m-arrow-path' : 'heroicon-m-key')
                ->color(fn () => $this->record->hasAccessCode() ? 'warning' : 'success')
                ->visible(function (): bool {
                    if (!(auth()->user()?->can('update_order') ?? false)) return false;
                    if (!in_array($this->record->status, ['paid', 'deposit'])) return false;
                    $product = $this->record->items->first()?->product;
                    return $product && ($product->has_manual_lock || $product->lock_id !== null);
                })
                ->requiresConfirmation()
                ->modalHeading(fn () => $this->record->hasAccessCode() ? 'Cấp lại mã cổng' : 'Gán mã cổng cho đơn hàng')
                ->modalDescription(fn () => $this->record->hasAccessCode()
                    ? 'Hệ thống sẽ thu hồi mã cổng hiện tại và cấp mã mới. Khách hàng sẽ được thông báo realtime.'
                    : 'Hệ thống sẽ tự động tìm mã cổng khả dụng và gán cho đơn này. Khách hàng sẽ được thông báo realtime.')
                ->modalSubmitActionLabel(fn () => $this->record->hasAccessCode() ? 'Xác nhận cấp lại' : 'Xác nhận gán')
                ->action(function () {
                    $record = $this->record->fresh(['items.product', 'accessCodes']);

                    try {
                        $service = app(AccessCodeService::class);

                        // Thu hồi mã cũ nếu đã có
                        if ($record->hasAccessCode()) {
                            $service->releaseCode($record->id);
                        }

                        $firstItem    = $record->items->sortBy('checkin_date')->first();
                        $checkinDate  = $record->items->min('checkin_date');
                        $checkoutDate = $record->items->max('checkout_date');
                        $product      = $firstItem?->product;

                        $code = $service->assignCodeToOrder(
                            $record->id,
                            $record->category_id,
                            $checkinDate,
                            $checkoutDate,
                            $product,
                        );

                        // Notify realtime + FCM đến khách theo loại khóa
                        $notifService = app(\App\Services\NotificationFcmService::class);
                        $extra        = ['order_code' => (string) $record->order_code, 'type' => 'order_access_code'];
                        $notifTitle   = null;
                        $notifBody    = null;
                        $wsPayload    = ['access_code_assigned' => true];
                        $adminMsg     = 'Đã gán mã cổng';

                        if ($product && $product->has_manual_lock) {
                            // Phòng mật khẩu thủ công → gửi mã thực tế
                            $manualPwd = \Modules\Product\App\Models\ManualLockPassword::getForProductAndDate($product, $checkinDate);
                            if ($manualPwd && ($manualPwd->gate_password || $manualPwd->room_password)) {
                                $parts = [];
                                if ($manualPwd->gate_password) $parts[] = 'Mã cổng: ' . $manualPwd->gate_password;
                                if ($manualPwd->room_password) $parts[] = 'Mã phòng: ' . $manualPwd->room_password;
                                $notifTitle = "Đơn #{$record->order_code}: Mã cổng đã được cấp";
                                $notifBody  = implode(' | ', $parts);
                                $wsPayload  = [
                                    'access_code_assigned' => true,
                                    'lock_type'            => 'manual',
                                    'gate_password'        => $manualPwd->gate_password,
                                    'room_password'        => $manualPwd->room_password,
                                ];
                                $adminMsg = 'Đã cấp: ' . implode(' | ', $parts);
                            }
                        } elseif ($product && $product->lock_id && \App\Services\TTLockService::forCategory($record->category_id)) {
                            // Phòng TTLock
                            $notifTitle = "Đơn #{$record->order_code}: Mã cổng đã sẵn sàng";
                            $notifBody  = 'Bạn có thể mở cửa trực tiếp từ ứng dụng.';
                            $wsPayload  = ['access_code_assigned' => true, 'lock_type' => 'ttlock'];
                            $adminMsg   = 'Đã cấp TTLock: ' . ($code?->code ?? '-');
                        } elseif ($code) {
                            // Pool AccessCode (không có thông báo khách — phòng không cần hiển thị code)
                            $adminMsg = 'Đã gán mã cổng: ' . $code->code;
                        }

                        if ($notifTitle && $notifBody) {
                            $this->pushClientNotification($record, $notifService, $notifTitle, $notifBody, 'order_access_code', $extra);
                            app(\App\Services\OrderRealtimeService::class)->broadcastOrderUpdate(
                                (string) $record->order_code,
                                $wsPayload,
                                $record->customer_id ? (int) $record->customer_id : null,
                            );
                        }

                        Notification::make()
                            ->title($adminMsg)
                            ->success()
                            ->send();

                        $this->record = $record->fresh();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Không thể gán mã cổng')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('changeManualCode')
                ->label('Đổi mã cổng (thủ công)')
                ->icon('heroicon-m-key')
                ->color('info')
                ->visible(function () {
                    if (! (auth()->user()?->can('update_order') ?? false)) {
                        return false;
                    }
                    if (! in_array($this->record->status, ['paid', 'deposit'])) {
                        return false;
                    }
                    // Chỉ hiện khi đơn có access code nhập tay (không có ttlock_keyboard_pwd_id)
                    return $this->record->accessCodes()
                        ->whereNull('ttlock_keyboard_pwd_id')
                        ->exists();
                })
                ->form([
                    \Filament\Forms\Components\Select::make('new_access_code_id')
                        ->label('Chọn mã cổng mới')
                        ->options(function () {
                            return \Modules\AccessCode\Entities\AccessCode::where('status', 'active')
                                ->where('category_id', $this->record->category_id)
                                ->whereNull('ttlock_keyboard_pwd_id')
                                ->whereDoesntHave('orders', fn ($q) => $q->where('orders.id', $this->record->id))
                                ->get()
                                ->mapWithKeys(fn ($c) => [$c->id => $c->code . ($c->gate_location ? " ({$c->gate_location})" : '')])
                                ->toArray();
                        })
                        ->required()
                        ->searchable(),
                ])
                ->requiresConfirmation()
                ->modalHeading('Đổi mã cổng thủ công')
                ->modalDescription('Mã cổng cũ sẽ bị thu hồi và mã mới sẽ được gán cho đơn này. Khách hàng sẽ được thông báo realtime.')
                ->modalSubmitActionLabel('Xác nhận đổi mã')
                ->action(function (array $data) {
                    $record = $this->record->fresh(['items', 'accessCodes']);

                    try {
                        $newCode = \Modules\AccessCode\Entities\AccessCode::findOrFail($data['new_access_code_id']);

                        // Thu hồi mã cũ (chỉ manual, không gọi TTLock API)
                        $record->accessCodes()
                            ->whereNull('ttlock_keyboard_pwd_id')
                            ->each(fn ($ac) => $ac->orders()->detach($record->id));

                        // Gán mã mới
                        $newCode->assignToOrder($record->id);

                        // Notify realtime đến client
                        try {
                            app(OrderRealtimeService::class)->broadcastCodeChanged(
                                (string) $record->order_code,
                                (string) $newCode->code,
                                'manual',
                                $record->customer_id,
                            );
                        } catch (\Throwable) {}

                        Notification::make()
                            ->title("Đã đổi mã cổng thành: {$newCode->code}")
                            ->success()
                            ->send();

                        $this->redirect($this->getResource()::getUrl('edit', ['record' => $record]));
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Không thể đổi mã cổng')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('changeTtlockCode')
                ->label('Tạo mã mới (TTLock)')
                ->icon('heroicon-m-arrow-path')
                ->color('info')
                ->visible(function () {
                    if (! (auth()->user()?->can('update_order') ?? false)) {
                        return false;
                    }
                    if (! in_array($this->record->status, ['paid', 'deposit'])) {
                        return false;
                    }
                    // Chỉ hiện khi đơn có access code TTLock
                    return $this->record->accessCodes()
                        ->whereNotNull('ttlock_keyboard_pwd_id')
                        ->exists();
                })
                ->requiresConfirmation()
                ->modalHeading('Tạo mã TTLock mới')
                ->modalDescription('Mã cũ sẽ bị xóa khỏi khóa, một mã 6 số mới sẽ được tạo và cập nhật lên TTLock. Khách hàng sẽ được thông báo realtime.')
                ->modalSubmitActionLabel('Xác nhận tạo mã mới')
                ->action(function () {
                    $record = $this->record->fresh(['items.product', 'accessCodes']);

                    try {
                        $accessCodeService = app(\Modules\BladeThemeV1\Services\AccessCode\AccessCodeService::class);

                        // Thu hồi mã TTLock cũ (gọi TTLock API delete + xóa record)
                        $accessCodeService->releaseCode($record->id);

                        // Cấp mã TTLock mới
                        $firstItem    = $record->items->where('extra_fee', 0)->first() ?? $record->items->first();
                        $checkinDate  = $firstItem?->checkin_date;
                        $checkoutDate = $firstItem?->checkout_date;
                        $product      = $firstItem?->product;

                        $newCode = $accessCodeService->assignCodeToOrder(
                            $record->id,
                            $record->category_id,
                            $checkinDate,
                            $checkoutDate,
                            $product,
                        );

                        if (! $newCode) {
                            Notification::make()
                                ->title('Phòng này dùng khóa thủ công, không cần tạo mã TTLock.')
                                ->warning()
                                ->send();
                            return;
                        }

                        // Notify realtime đến client
                        try {
                            app(OrderRealtimeService::class)->broadcastCodeChanged(
                                (string) $record->order_code,
                                (string) $newCode->code,
                                'ttlock',
                                $record->customer_id,
                            );
                        } catch (\Throwable) {}

                        Notification::make()
                            ->title("Đã tạo mã TTLock mới: {$newCode->code}")
                            ->success()
                            ->send();

                        $this->redirect($this->getResource()::getUrl('edit', ['record' => $record]));
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Không thể tạo mã TTLock mới')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('scanCccdQr')
                ->label('[TEST] Quét QR CCCD')
                ->icon('heroicon-m-qr-code')
                ->color('gray')
                ->visible(fn () => (bool) ($this->record->cccd_front || $this->record->cccd_back))
                ->action(function () {
                    $record = $this->record->fresh();
                    $data   = app(CccdScannerService::class)->scanOrder($record);

                    if (! $data) {
                        Notification::make()
                            ->title('Không đọc được QR CCCD')
                            ->body('Ảnh quá nhỏ hoặc QR bị mờ. Vui lòng upload lại ảnh gốc chất lượng cao (không resize).')
                            ->warning()
                            ->send();
                        return;
                    }

                    $note = implode("\n", array_filter([
                        $data['cccd']        ? "Số CCCD:   {$data['cccd']}"        : null,
                        $data['full_name']   ? "Họ và tên: {$data['full_name']}"   : null,
                        $data['dob']         ? "Ngày sinh: {$data['dob']}"         : null,
                        $data['gender']      ? "Giới tính: {$data['gender']}"      : null,
                        $data['address']     ? "Địa chỉ:   {$data['address']}"     : null,
                    ]));

                    $updateFields = [
                        'cccd_data'      => $data,
                        'note_for_admin' => $note,
                    ];

                    if (! empty($data['full_name'])) {
                        $updateFields['buyer_name'] = $data['full_name'];
                    }
                    if (! empty($data['address'])) {
                        $updateFields['buyer_address'] = $data['address'];
                    }

                    $record->update($updateFields);

                    // Cập nhật ngay "Khai báo lưu trú" với họ tên/số CCCD vừa quét được — không
                    // đợi admin bấm "Lưu" cả form (afterSave() chỉ chạy khi submit toàn bộ form).
                    app(CccdDeclarationService::class)->upsertFromOrder($record->fresh(['items']));

                    $this->refreshFormData(['note_for_admin', 'cccd_data', 'buyer_name', 'buyer_address']);

                    Notification::make()
                        ->title('Quét CCCD thành công')
                        ->body($note)
                        ->success()
                        ->send();
                }),

            // Khách đi cùng (order_guest_cccds, guest_index=2,3,4...) — quét riêng vì ảnh lưu ở
            // bảng khác, dùng scanPaths() thay vì scanOrder() (chỉ đọc đúng cột chính trên orders).
            // Quét TẤT CẢ khách đi cùng đang có ảnh, không riêng 1 người như trước.
            Actions\Action::make('scanCccdQrGuests')
                ->label('Quét QR CCCD khách đi cùng')
                ->icon('heroicon-m-qr-code')
                ->color('gray')
                ->visible(fn () => $this->record->guestCccds()->where(fn ($q) => $q->whereNotNull('cccd_front')->orWhereNotNull('cccd_back'))->exists())
                ->action(function () {
                    $record = $this->record->fresh();
                    $notes  = [];

                    foreach ($record->guestCccds as $guest) {
                        if (! $guest->cccd_front && ! $guest->cccd_back) {
                            continue;
                        }

                        $data = app(CccdScannerService::class)->scanPaths($guest->cccd_front, $guest->cccd_back);

                        if (! $data) {
                            continue;
                        }

                        $guest->update(['cccd_data' => $data]);

                        $notes[] = "Khách #{$guest->guest_index}: " . implode(', ', array_filter([
                            $data['full_name'] ?? null,
                            $data['cccd'] ?? null,
                        ]));
                    }

                    if (empty($notes)) {
                        Notification::make()
                            ->title('Không đọc được QR CCCD khách đi cùng nào')
                            ->body('Ảnh quá nhỏ hoặc QR bị mờ. Vui lòng upload lại ảnh gốc chất lượng cao (không resize).')
                            ->warning()
                            ->send();
                        return;
                    }

                    $note = implode("\n", $notes);

                    // Cập nhật ngay "Khai báo lưu trú" — không đợi admin bấm "Lưu" cả form.
                    app(CccdDeclarationService::class)->upsertFromOrder($record->fresh(['items']));

                    $this->refreshFormData(['guest_cccds']);

                    Notification::make()
                        ->title('Quét CCCD khách đi cùng thành công')
                        ->body($note)
                        ->success()
                        ->send();
                }),

            Actions\Action::make('retryOcr')
                ->label('Thu thập lại CCCD')
                ->icon('heroicon-m-arrow-path')
                ->color('primary')
                ->visible(fn () => auth()->user()?->can('update_order') ?? false)
                ->requiresConfirmation()
                ->modalHeading('Thu thập lại thông tin OCR từ CCCD')
                ->modalDescription('Hệ thống sẽ quét lại ảnh CCCD mặt trước và cập nhật thông tin vào trường "Thông tin người dùng". Bạn có chắc chắn muốn tiếp tục?')
                ->modalSubmitActionLabel('Xác nhận')
                ->action(function () {
                    // Refresh the record to get the latest data
                    $record = $this->record->fresh();

                    if (!$record->cccd_front) {
                        Notification::make()
                            ->title('Không tìm thấy ảnh CCCD')
                            ->body('Vui lòng upload ảnh CCCD mặt trước trước khi thu thập OCR.')
                            ->danger()
                            ->send();
                        return;
                    }

                    try {
                        $ocrService = app(OcrSpaceService::class);

                        if (!$ocrService->isConfigured()) {
                            Notification::make()
                                ->title('OCR chưa được cấu hình')
                                ->body('Vui lòng cấu hình OCR_SPACE_API_KEY trong file .env')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Get the full path to the CCCD image
                        $cccdPath = Storage::disk('public')->path($record->cccd_front);

                        if (!file_exists($cccdPath)) {
                            Notification::make()
                                ->title('File không tồn tại')
                                ->body("Không tìm thấy file: {$record->cccd_front}")
                                ->danger()
                                ->send();
                            return;
                        }

                        // Extract text from CCCD front
                        $frontText = $ocrService->extractTextFromImage($cccdPath);

                        if (empty($frontText)) {
                            Notification::make()
                                ->title('OCR thất bại')
                                ->body('Không thể trích xuất văn bản từ ảnh CCCD. Vui lòng kiểm tra log để biết chi tiết.')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Format CCCD info (Name, ID, Home address)
                        $formattedInfo = $ocrService->formatCccdInfo($frontText);

                        // Update the record
                        $record->update([
                            'note_for_admin' => $formattedInfo
                        ]);

                        // Refresh form data to show the updated value immediately
                        $this->refreshFormData([
                            'note_for_admin'
                        ]);

                        Notification::make()
                            ->title('Thu thập OCR thành công')
                            ->body('Thông tin CCCD đã được cập nhật vào trường "Thông tin người dùng".')
                            ->success()
                            ->send();

                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Lỗi khi thu thập OCR')
                            ->body('Chi tiết: ' . $e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('regenerateExtraChargeQr')
                ->label('Tạo lại QR phát sinh')
                ->icon('heroicon-m-qr-code')
                ->color('warning')
                ->visible(function () {
                    if (! (auth()->user()?->can('update_order') ?? false)) {
                        return false;
                    }
                    $r = $this->record;
                    return $r->extra_charge_amount
                        && is_null($r->extra_charge_paid_at)
                        && $r->status === 'paid';
                })
                ->requiresConfirmation()
                ->modalHeading('Tạo lại QR thanh toán phát sinh')
                ->modalDescription(function () {
                    $amount = number_format((int) $this->record->extra_charge_amount, 0, ',', '.');
                    return "Tạo lại link PayOS mới cho khoản phát sinh {$amount}đ của đơn #{$this->record->order_code}? Link cũ sẽ bị hủy.";
                })
                ->modalSubmitActionLabel('Tạo lại')
                ->action(function () {
                    $record = $this->record->fresh();

                    if (! $record->extra_charge_amount || ! is_null($record->extra_charge_paid_at)) {
                        Notification::make()
                            ->title('Không có khoản phát sinh cần tạo lại QR')
                            ->warning()
                            ->send();
                        return;
                    }

                    try {
                        $result = app(ExtraChargeService::class)->createExtraChargePayOS($record, (int) $record->extra_charge_amount);

                        // Notify khách QR mới
                        $notifService = app(\App\Services\NotificationFcmService::class);
                        $amount       = number_format((int) $record->extra_charge_amount, 0, ',', '.');
                        $title        = "Đơn #{$record->order_code}: QR thanh toán phát sinh mới";
                        $body         = "Link thanh toán {$amount}đ đã được tạo lại. Vui lòng thanh toán trong 60 phút.";
                        $extra        = [
                            'order_code'   => (string) $record->order_code,
                            'type'         => 'order_extra_charge',
                            'checkout_url' => $result['checkout_url'],
                            'amount'       => (int) $record->extra_charge_amount,
                        ];
                        $this->pushClientNotification($record, $notifService, $title, $body, 'order_extra_charge', $extra);

                        app(\App\Services\OrderRealtimeService::class)->broadcastOrderUpdate(
                            (string) $record->order_code,
                            ['extra_charge' => ['is_expired' => false, 'qr_code' => $result['qr_code']]],
                            $record->customer_id ? (int) $record->customer_id : null,
                        );

                        Notification::make()
                            ->title('Đã tạo lại QR thanh toán phát sinh')
                            ->body('Link mới: ' . $result['checkout_url'])
                            ->actions([
                                \Filament\Notifications\Actions\Action::make('open')
                                    ->label('Mở link')
                                    ->url($result['checkout_url'])
                                    ->openUrlInNewTab(),
                            ])
                            ->success()
                            ->persistent()
                            ->send();

                        $this->syncRecordAndForm($record);
                        $this->refreshFormData(['extra_charge_checkout_url', 'extra_charge_expired_at']);

                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Không thể tạo lại QR')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('collectExtraChargeCash')
                ->label('Thu tiền mặt (phát sinh)')
                ->icon('heroicon-m-banknotes')
                ->color('success')
                ->visible(function () {
                    if (! (auth()->user()?->can('update_order') ?? false)) {
                        return false;
                    }
                    $r = $this->record;
                    // Chỉ hiện cho đơn paid: extra charge là khoản thu riêng sau thanh toán.
                    // Deposit: extra đã gộp vào remaining, admin thu qua nút trong form.
                    return $r->extra_charge_amount
                        && is_null($r->extra_charge_paid_at)
                        && $r->status === 'paid';
                })
                ->requiresConfirmation()
                ->modalHeading('Xác nhận thu tiền mặt')
                ->modalDescription(function () {
                    $amount = number_format((int) $this->record->extra_charge_amount, 0, ',', '.');
                    return "Xác nhận đã thu {$amount}đ tiền mặt cho khoản phát sinh của đơn #{$this->record->order_code}?";
                })
                ->modalSubmitActionLabel('Xác nhận đã thu')
                ->action(function () {
                    $record = $this->record->fresh();

                    if (! $record->extra_charge_amount || ! is_null($record->extra_charge_paid_at)) {
                        Notification::make()
                            ->title('Không có khoản phát sinh chờ thanh toán')
                            ->warning()
                            ->send();
                        return;
                    }

                    try {
                        app(\App\Services\ExtraChargeService::class)->markExtraChargeAsCash($record, (int) $record->extra_charge_amount);

                        $notifService = app(\App\Services\NotificationFcmService::class);
                        $title        = "Đơn #{$record->order_code}: khoản phát sinh đã thanh toán";
                        $body         = 'Khoản phát sinh ' . number_format((int) $record->extra_charge_amount, 0, ',', '.') . 'đ đã được ghi nhận (tiền mặt).';
                        $extra        = ['order_code' => (string) $record->order_code, 'type' => 'order_extra_charge_paid'];

                        $this->pushClientNotification($record, $notifService, $title, $body, 'order_extra_charge_paid', $extra);

                        app(\App\Services\OrderRealtimeService::class)->broadcastOrderUpdate(
                            (string) $record->order_code,
                            ['extra_charge' => ['is_paid' => true, 'paid_at' => now()->toISOString(), 'payment_method' => 'cod']],
                            $record->customer_id ? (int) $record->customer_id : null,
                        );

                        Notification::make()
                            ->title('Đã ghi nhận thu tiền mặt')
                            ->body(number_format((int) $record->extra_charge_amount, 0, ',', '.') . 'đ')
                            ->success()
                            ->send();

                        $this->syncRecordAndForm($record);
                        $this->refreshFormData(['extra_charge_amount', 'extra_charge_paid_at', 'extra_charge_payment_method']);

                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Lỗi khi ghi nhận thu tiền')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\DeleteAction::make(),
        ];
    }

    private function sendStatusChangeNotification(\Modules\Payment\Entities\Order $order): void
    {
        $messages = [
            'paid'               => ['Đặt phòng thành công!',      "Đơn #{$order->order_code} đã được xác nhận thanh toán."],
            'confirmed'          => ['Đơn đã được xác nhận',       "Đơn #{$order->order_code} đã được xác nhận bởi chúng tôi."],
            'deposit'            => ['Đã nhận tiền cọc',           "Đơn #{$order->order_code} đã nhận cọc " . ($order->deposit_percent ?? '') . "%."],
            'cancelled'          => ['Đơn đã bị hủy',              "Đơn #{$order->order_code} đã bị hủy. Liên hệ chúng tôi nếu có thắc mắc."],
            'cancelled_payment'  => ['Thanh toán hết hạn',         "Đơn #{$order->order_code} đã hết hạn thanh toán."],
            'failed'             => ['Thanh toán thất bại',        "Đơn #{$order->order_code} thanh toán không thành công."],
            'shipped'            => ['Đơn đang được xử lý',        "Đơn #{$order->order_code} đang được xử lý."],
        ];

        [$title, $body] = $messages[$order->status] ?? [null, null];

        if (! $title) {
            return;
        }

        $extra = ['order_code' => (string) $order->order_code, 'type' => 'order_status_changed'];
        $notifService = app(\App\Services\NotificationFcmService::class);

        try {
            // Guest (không có customer_id) → dùng device_token lưu trên order
            if (is_null($order->customer_id) && $order->device_token) {
                $notifService->sendToGuestToken($order->device_token, $title, $body, 'order_status_changed', $extra);
                return;
            }

            // Customer đăng nhập
            if ($order->customer_id) {
                $customer = $order->customer;
                if ($customer) {
                    $notifService->sendToCustomer($customer, $title, $body, 'order_status_changed', $extra);
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('EditOrder: sendStatusChangeNotification failed', [
                'order_id' => $order->id,
                'status'   => $order->status,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /** Ngày checkout cũ trước khi save (để so sánh) */
    protected ?string $oldCheckoutDate = null;
    protected ?string $oldCheckinDate  = null;
    protected ?string $oldStatus       = null;
    protected ?int    $oldGuestCount    = null;
    protected array   $oldServices      = [];
    protected int     $oldServicesTotal = 0;
    protected int     $oldItemsTotal    = 0;
    protected int     $oldItemsCount    = 0;
    protected array   $oldItemSnapshot  = [];
    protected array   $oldServiceDetails = [];
    /**
     * Khoản phát sinh/hoàn tiền CÒN ĐANG CHỜ XỬ LÝ (chưa xác nhận thanh toán/hoàn) NGAY TRƯỚC lần
     * lưu này — dương = đang nợ thêm, âm = đang cần hoàn. Dùng làm MỐC SO SÁNH để tính đúng
     * INCREMENTAL diff (chỉ riêng lần sửa này thay đổi bao nhiêu) cho "Lịch sử thanh toán", KHÔNG
     * dùng $cumulativeDiff (tổng chênh lệch tích luỹ từ full_amount gốc) cho mục đích hiển thị lịch
     * sử — nếu không, thêm khung X (+206.8) rồi bớt lại khung X sẽ hiện "Bớt khung X +100.000đ"
     * (vẫn dương, vì dịch vụ khác còn giữ), gây hiểu lầm "2 dòng thêm/bớt độc lập không liên quan"
     * dù thực chất lần sau đã LÀM GIẢM so với lần trước (xem handlePriceDiff()).
     */
    protected int $previousNetAdjustment = 0;

    /**
     * Ghi nhớ ngày cũ, trạng thái cũ, guest_count cũ và services cũ trước khi lưu để so sánh sau.
     */
    protected function beforeSave(): void
    {
        $firstItem = $this->record->items()->where('extra_fee', 0)->first();
        $this->oldCheckoutDate  = $firstItem?->checkout_date?->toDateTimeString();
        $this->oldCheckinDate   = $firstItem?->checkin_date?->toDateTimeString();
        $this->oldStatus        = $this->record->status;
        $this->oldGuestCount    = (int) $this->record->guest_count;
        $this->oldServicesTotal = (int) $this->record->services()->sum('subtotal');
        $this->oldServices      = $this->record->services()
            ->get(['service_id', 'quantity'])
            ->map(fn ($s) => ['service_id' => $s->service_id, 'quantity' => $s->quantity])
            ->toArray();
        $this->oldServiceDetails = $this->record->services()
            ->get(['service_id', 'service_name', 'quantity'])
            ->mapWithKeys(fn ($s) => [$this->serviceChangeKey($s) => [
                'name' => $s->service_name ?: 'Dịch vụ',
                'quantity' => (int) ($s->quantity ?? 1),
            ]])
            ->all();
        // Tổng giá các khung giờ/phòng TRƯỚC khi lưu (không tính dòng phụ thu khách extra_fee>0)
        // — dùng để phát hiện + tính chênh lệch khi admin thêm/bớt khung giờ cho đơn đã cọc/thanh
        // toán (xem handlePriceDiff() bên dưới).
        $oldItems = $this->record->items()->where('extra_fee', 0)->get();
        $this->oldItemsTotal = (int) $oldItems->sum('price');
        $this->oldItemsCount = (int) $oldItems->count();
        $this->oldItemSnapshot = $oldItems
            ->mapWithKeys(fn ($item) => [$this->itemChangeKey($item) => $this->formatItemChangeLabel($item)])
            ->all();

        // Chỉ tính khoản CÒN PENDING (chưa xác nhận thu/hoàn) làm mốc — khoản ĐÃ xác nhận xong
        // (extra_charge_paid_at/extra_refund_paid_at khác null) coi như đã "chốt sổ", không phải
        // là phần "đang chờ xử lý" nữa nên không được cộng vào mốc so sánh cho lần sửa mới.
        $pendingCharge = ((int) ($this->record->extra_charge_amount ?? 0) > 0 && is_null($this->record->extra_charge_paid_at))
            ? (int) $this->record->extra_charge_amount
            : 0;
        $pendingRefund = ((int) ($this->record->extra_refund_amount ?? 0) > 0 && is_null($this->record->extra_refund_paid_at))
            ? (int) $this->record->extra_refund_amount
            : 0;
        $this->previousNetAdjustment = $pendingCharge - $pendingRefund;
    }

    /**
     * Sau khi lưu đơn, gửi thông báo nếu trạng thái thay đổi và cập nhật TTLock nếu ngày thay đổi.
     */
    protected function afterSave(): void
    {
        $record = $this->record->fresh(['items.product', 'accessCodes', 'services']);

        // Đơn đã lưu xong (khung giờ mới thêm/đổi giờ đã thành order_item THẬT) — hold real-time
        // (xem TimeslotHoldService) không cần giữ nữa, trả lại ngay cho admin khác.
        app(\App\Services\TimeslotHoldService::class)->releaseAllForUser(auth()->user());

        // Tự động quét OCR CCCD từng khách đi cùng NẾU đã có ảnh nhưng cccd_data còn trống —
        // CreateOrder::afterCreate() đã tự làm việc này khi TẠO đơn mới, nhưng EditOrder trước đây
        // KHÔNG có bước tương tự, chỉ có nút "Quét QR CCCD khách thứ 2" admin phải tự bấm tay riêng
        // (dễ quên/không biết phải bấm). Hậu quả: khách đặt 1 người rồi SỬA đơn thêm khung giờ qua
        // đêm + tăng số khách + upload đủ CCCD khách đi cùng, nhưng "Khai báo lưu trú" của họ KHÔNG
        // BAO GIỜ được tạo — vì upsertFromOrder() bên dưới chỉ tạo bản ghi khi cccd_data đã có dữ
        // liệu (xem CccdDeclarationService::upsertFromOrder()).
        foreach ($record->guestCccds as $guest) {
            if (! blank($guest->cccd_data) || (! $guest->cccd_front && ! $guest->cccd_back)) {
                continue;
            }

            try {
                $scanned = app(CccdScannerService::class)->scanPaths($guest->cccd_front, $guest->cccd_back);

                if ($scanned) {
                    $guest->update(['cccd_data' => $scanned]);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('afterSave: auto-scan CCCD khách đi cùng thất bại', [
                    'order_id'    => $record->id,
                    'guest_index' => $guest->guest_index,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        // Đồng bộ lại "Khai báo lưu trú" mỗi khi đơn được sửa trong CMS admin (đổi phòng/ngày
        // nhận-trả/CCCD...) — cùng service dùng cho luồng app khách hàng, để dữ liệu khai báo luôn
        // khớp với thông tin đơn mới nhất, không riêng gì lúc tạo mới.
        app(CccdDeclarationService::class)->upsertFromOrder($record);

        // ── Gửi push notification khi status thay đổi ────────────────────────
        if ($this->oldStatus && $record->status !== $this->oldStatus) {
            $this->sendStatusChangeNotification($record);
        }

        // ── Broadcast realtime + xử lý chênh lệch giá khi thay đổi ─────────
        $guestCountChanged = (int) $record->guest_count !== $this->oldGuestCount;

        $newServicesSnapshot = $record->services
            ->map(fn ($s) => ['service_id' => $s->service_id, 'quantity' => $s->quantity])
            ->toArray();
        $servicesChanged = $newServicesSnapshot !== $this->oldServices;

        // Thêm/bớt khung giờ (order_item) làm tổng giá phòng thay đổi — so sánh tổng giá các
        // khung giờ hiện tại với tổng giá cũ đã ghi nhớ ở beforeSave().
        $newItemsTotal = (int) $record->items->where('extra_fee', 0)->sum('price');
        $itemsChanged  = $newItemsTotal !== $this->oldItemsTotal;
        $newItemsCount = (int) $record->items->where('extra_fee', 0)->count();

        if ($guestCountChanged || $servicesChanged || $itemsChanged) {
            $changes = [];

            if ($guestCountChanged) {
                $changes['guest_count'] = ['from' => $this->oldGuestCount, 'to' => $record->guest_count];
            }
            if ($servicesChanged) {
                $changes['services'] = $record->services->map(fn ($s) => [
                    'service_name' => $s->service_name,
                    'quantity'     => $s->quantity,
                    'subtotal'     => $s->subtotal,
                ])->values()->toArray();
            }
            if ($itemsChanged) {
                $changes['items_total'] = ['from' => $this->oldItemsTotal, 'to' => $newItemsTotal];
            }

            // Broadcast realtime đến client
            try {
                app(OrderRealtimeService::class)->broadcastOrderUpdate(
                    (string) $record->order_code,
                    [
                        'guest_count' => $record->guest_count,
                        'services'    => $record->services->map(fn ($s) => [
                            'service_name' => $s->service_name,
                            'quantity'     => $s->quantity,
                            'subtotal'     => $s->subtotal,
                        ])->values()->toArray(),
                        'changes' => $changes,
                    ],
                    $record->customer_id ? (int) $record->customer_id : null,
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('afterSave: broadcastOrderUpdate failed', [
                    'order_id' => $record->id,
                    'error'    => $e->getMessage(),
                ]);
            }

            // FCM + WS notification đến khách về thay đổi đơn
            $this->sendOrderUpdateNotification($record, $changes);

            // Xử lý chênh lệch giá (chỉ khi đơn đã thanh toán ít nhất 1 lần)
            if (in_array($record->status, ['paid', 'deposit'])) {
                $this->handlePriceDiff($record, $this->buildChangeSummary(
                    $record,
                    $guestCountChanged,
                    $servicesChanged,
                    $newItemsCount,
                    $newServicesSnapshot,
                ));
            }
        }

        // Refresh $this->record NGAY TẠI ĐÂY, KHÔNG đặt sau các early-return riêng của phần TTLock
        // bên dưới — trước đây dòng này nằm ở CUỐI hàm, sau 2 chỗ "return" chỉ dành cho nhánh
        // TTLock (ngày check-in/check-out không đổi, hoặc đơn chưa có mã cổng — TRƯỜNG HỢP RẤT
        // PHỔ BIẾN khi chỉ thêm/bớt khung giờ mà KHÔNG đổi ngày, hoặc đơn còn ở trạng thái paid mà
        // chưa gán mã cổng). Gặp 1 trong 2 điều kiện đó, hàm return SỚM, $this->record không bao
        // giờ được refresh — khiến Section "Phát sinh thêm"/"Hoàn tiền chưa xử lý" và badge tab vẫn
        // đọc dữ liệu CŨ (extra_charge_amount chưa set) ở lần render này, dù DB đã lưu đúng. Admin
        // phải bấm thêm 1 lần nữa (Lưu lại, hoặc thao tác bất kỳ khiến trang render lại từ đầu) mới
        // thấy — đúng y hệt hiện tượng "bấm 1 lần không thấy gì, bấm 2 lần mới hiện". Xem thêm ghi
        // chú ở syncRecordAndForm() — CHỈ gán $this->record thôi vẫn chưa đủ, còn phải "mở khoá"
        // Form đã cache để nó trỏ đúng sang bản ghi mới.
        $this->syncRecordAndForm($this->record->fresh());

        $firstItem    = $record->items->where('extra_fee', 0)->first() ?? $record->items->first();
        $newCheckout  = $firstItem?->checkout_date?->toDateTimeString();
        $newCheckin   = $firstItem?->checkin_date?->toDateTimeString();

        $checkoutChanged = $newCheckout && $newCheckout !== $this->oldCheckoutDate;
        $checkinChanged  = $newCheckin  && $newCheckin  !== $this->oldCheckinDate;

        // Reset flag thông báo nếu ngày thay đổi
        if ($checkoutChanged || $checkinChanged) {
            $record->items()->where('extra_fee', 0)->update([
                'expiry_notified'   => false,
                'checkout_notified' => false,
            ]);
        }

        // Chỉ gọi TTLock API khi ngày thực sự thay đổi và đơn có mã cổng
        if ((!$checkoutChanged && !$checkinChanged) || $record->accessCodes->isEmpty()) {
            return;
        }

        $checkinDate  = $firstItem?->checkin_date;
        $checkoutDate = $firstItem?->checkout_date;

        if (!$checkinDate || !$checkoutDate) {
            return;
        }

        try {
            $service = app(AccessCodeService::class);
            $updated = $service->updateCodeDatesForOrder(
                $record->id,
                $checkinDate,
                $checkoutDate,
                $firstItem?->product,
            );

            if ($updated) {
                Notification::make()
                    ->title('Đã cập nhật thời gian mã cổng trên TTLock')
                    ->success()
                    ->send();
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('afterSave: updateCodeDates failed', [
                'order_id' => $record->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mô tả cụ thể thay đổi giá để hiển thị ở tab thanh toán.
     */
    private function buildChangeSummary(
        \Modules\Payment\Entities\Order $record,
        bool $guestCountChanged,
        bool $servicesChanged,
        int $newItemsCount,
        array $newServicesSnapshot,
    ): array {
        $summary = [];

        $newItemSnapshot = $record->items
            ->where('extra_fee', 0)
            ->mapWithKeys(fn ($item) => [$this->itemChangeKey($item) => $this->formatItemChangeLabel($item)])
            ->all();

        $addedItems = array_values(array_diff_key($newItemSnapshot, $this->oldItemSnapshot));
        $removedItems = array_values(array_diff_key($this->oldItemSnapshot, $newItemSnapshot));

        if (! empty($addedItems)) {
            $summary[] = 'Thêm khung ' . implode(', ', $addedItems);
        }
        if (! empty($removedItems)) {
            $summary[] = 'Bớt khung ' . implode(', ', $removedItems);
        }

        $itemsCountDelta = $newItemsCount - $this->oldItemsCount;
        if (empty($addedItems) && empty($removedItems) && $itemsCountDelta !== 0) {
            $summary[] = $itemsCountDelta > 0
                ? "Thêm {$itemsCountDelta} khung giờ"
                : 'Bớt ' . abs($itemsCountDelta) . ' khung giờ';
        }

        if ($servicesChanged) {
            $newServices = $record->services
                ->mapWithKeys(fn ($s) => [$this->serviceChangeKey($s) => [
                    'name' => $s->service_name ?: 'Dịch vụ',
                    'quantity' => (int) ($s->quantity ?? 1),
                ]])
                ->all();

            foreach (array_diff_key($newServices, $this->oldServiceDetails) as $service) {
                $summary[] = 'Thêm dịch vụ ' . $service['name'] . ' x' . $service['quantity'];
            }

            foreach (array_intersect_key($newServices, $this->oldServiceDetails) as $key => $service) {
                $oldQuantity = (int) ($this->oldServiceDetails[$key]['quantity'] ?? 0);
                $newQuantity = (int) ($service['quantity'] ?? 0);

                if ($newQuantity > $oldQuantity) {
                    $summary[] = 'Tăng dịch vụ ' . $service['name'] . ': ' . $oldQuantity . ' → ' . $newQuantity;
                } elseif ($newQuantity < $oldQuantity) {
                    $summary[] = 'Giảm dịch vụ ' . $service['name'] . ': ' . $oldQuantity . ' → ' . $newQuantity;
                }
            }

            foreach (array_diff_key($this->oldServiceDetails, $newServices) as $service) {
                $summary[] = 'Bớt dịch vụ ' . $service['name'];
            }
        }

        if ($guestCountChanged) {
            $guestDelta = (int) $record->guest_count - (int) $this->oldGuestCount;
            $summary[] = $guestDelta > 0
                ? "Thêm {$guestDelta} người"
                : 'Giảm ' . abs($guestDelta) . ' người';
        }

        return $summary;
    }

    private function itemChangeKey($item): string
    {
        $checkin = $item->checkin_date ? \Carbon\Carbon::parse($item->checkin_date)->format('Y-m-d H:i:s') : '';
        $checkout = $item->checkout_date ? \Carbon\Carbon::parse($item->checkout_date)->format('Y-m-d H:i:s') : '';

        return implode('|', [
            (string) ($item->product_id ?? ''),
            $checkin,
            $checkout,
            (string) ((int) ($item->price ?? 0)),
        ]);
    }

    private function formatItemChangeLabel($item): string
    {
        $room = trim((string) ($item->product?->name ?: $item->name ?: 'Phòng'));
        $roomWithoutSlot = preg_replace('/\s*-\s*\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}(?:\s*\([^)]*\))?\s*$/u', '', $room);
        $room = filled($roomWithoutSlot) ? trim($roomWithoutSlot) : $room;

        $checkin = $item->checkin_date ? \Carbon\Carbon::parse($item->checkin_date)->format('d/m H:i') : null;
        $checkout = $item->checkout_date ? \Carbon\Carbon::parse($item->checkout_date)->format('d/m H:i') : null;
        $time = $checkin && $checkout
            ? $checkin . ' - ' . $checkout
            : trim((string) ($item->slot_label ?? ''));

        return $time ? $room . ': ' . $time : $room;
    }

    private function serviceChangeKey($service): string
    {
        return (string) (($service->service_id ?? null) ?: ($service->service_name ?? spl_object_id($service)));
    }

    private function handlePriceDiff(\Modules\Payment\Entities\Order $order, array $changeSummary = []): void
    {
        try {
            $extraChargeService = app(ExtraChargeService::class);
            // $cumulativeDiff = TỔNG chênh lệch tích luỹ so với full_amount GỐC (dùng để biết ĐANG
            // NỢ/CẦN HOÀN bao nhiêu tính đến bây giờ — QR/remaining PHẢI dùng số này).
            // $incrementalDiff = riêng LẦN SỬA NÀY làm tăng/giảm bao nhiêu so với lần sửa trước (so
            // với $previousNetAdjustment ghi ở beforeSave()) — dùng cho audit log/lịch sử để mỗi
            // dòng phản ánh ĐÚNG tác động của chính lần sửa đó, không lẫn với các lần trước.
            $cumulativeDiff  = $extraChargeService->calculateDiff($order);
            $incrementalDiff = $cumulativeDiff - $this->previousNetAdjustment;

            if ($incrementalDiff === 0) {
                return;
            }

            // Ghi lại LỊCH SỬ mỗi lần phát sinh/hoàn tiền — orders.extra_charge_amount chỉ là 1
            // cột duy nhất, bị GHI ĐÈ mỗi lần có thay đổi mới nên không tự lưu được lịch sử nhiều
            // lần phát sinh qua thời gian. Dùng lại AuditLog (đã có sẵn hạ tầng) để quản lý xem
            // được đầy đủ: mỗi lần sửa đơn làm tăng/giảm bao nhiêu, ai sửa, lúc nào — hiển thị lại
            // ở "Lịch sử thanh toán" (xem OrderForm::buildPaymentTimelineSteps()).
            AuditLogger::log(
                action: 'price_adjustment',
                module: 'Order',
                record: $order,
                new: ['diff' => $incrementalDiff, 'status' => $order->status, 'change_summary' => $changeSummary],
                label: "Đơn #{$order->order_code}",
            );

            $notifService = app(\App\Services\NotificationFcmService::class);
            $extra        = ['order_code' => (string) $order->order_code, 'type' => 'order_extra_charge'];

            // ── Đơn deposit: cộng vào remaining ──────────────────────────────
            if ($order->status === 'deposit') {
                // applyDiffToDeposit CỘNG DỒN diff vào extra_charge_amount hiện có — phải truyền
                // $incrementalDiff (thay đổi RIÊNG lần này), không phải $cumulativeDiff (đã bao gồm
                // cả phần cũ), nếu không sẽ cộng dồn 2 lần phần cũ.
                $extraChargeService->applyDiffToDeposit($order, $incrementalDiff);

                // full_amount CỐ ĐỊNH = tổng giá thật, không đổi — remaining = (tổng - đã cọc) + phát sinh
                $order->refresh();
                $newRealTotal = (int) $order->full_amount;
                $depositPaid  = $order->depositPaidAmount();
                $extraCharge  = (int) ($order->extra_charge_amount ?? 0);
                $newRemaining = max(0, $newRealTotal - $depositPaid) + $extraCharge;

                $label = $incrementalDiff > 0
                    ? 'Phần còn lại tăng thêm ' . number_format($incrementalDiff, 0, ',', '.') . 'đ'
                    : 'Phần còn lại giảm ' . number_format(abs($incrementalDiff), 0, ',', '.') . 'đ';

                // Notify admin (Filament)
                Notification::make()
                    ->title($label)
                    ->body('Số tiền còn lại: ' . number_format($newRemaining, 0, ',', '.') . 'đ. Khách thanh toán khi check-out.')
                    ->info()
                    ->send();

                // WS realtime → app khách cập nhật deposit block ngay
                try {
                    app(OrderRealtimeService::class)->broadcastOrderUpdate(
                        (string) $order->order_code,
                        [
                            'deposit' => [
                                'remaining_amount' => $newRemaining,
                                'qr_code'          => null,
                                'checkout_url'     => null,
                            ],
                            'summary' => [
                                'grand_total' => $newRealTotal,
                            ],
                        ],
                        $order->customer_id ? (int) $order->customer_id : null,
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('handlePriceDiff: deposit broadcastOrderUpdate failed', [
                        'order_id' => $order->id,
                        'error'    => $e->getMessage(),
                    ]);
                }

                // FCM notify khách
                $clientTitle = "Đơn #{$order->order_code}: số tiền còn lại đã thay đổi";
                $clientBody  = $incrementalDiff > 0
                    ? 'Đơn hàng vừa được cập nhật (dịch vụ/số người/khung giờ) làm tăng ' . number_format($incrementalDiff, 0, ',', '.') . 'đ. Số tiền còn lại: ' . number_format($newRemaining, 0, ',', '.') . 'đ.'
                    : 'Điều chỉnh giảm ' . number_format(abs($incrementalDiff), 0, ',', '.') . 'đ. Số tiền còn lại: ' . number_format($newRemaining, 0, ',', '.') . 'đ.';

                $this->pushClientNotification($order, $notifService, $clientTitle, $clientBody, 'order_deposit_updated', $extra);

                return;
            }

            // ── Đơn đã paid, tổng đã về ĐÚNG bằng lúc đặt (thêm rồi bớt lại triệt tiêu nhau) ───
            // Không còn nợ/cần hoàn gì cả — xoá sạch cờ pending CŨ (nếu có) để panel "Phát sinh
            // thêm"/"Hoàn tiền chưa xử lý" không tiếp tục hiện 1 khoản không còn tồn tại.
            if ($cumulativeDiff === 0) {
                $order->update([
                    'extra_charge_amount' => null, 'extra_charge_payos_code' => null,
                    'extra_charge_checkout_url' => null, 'extra_charge_qr_code' => null,
                    'extra_charge_payment_method' => null, 'extra_charge_paid_at' => null,
                    'extra_charge_expired_at' => null,
                    'extra_refund_amount' => null, 'extra_refund_method' => null, 'extra_refund_paid_at' => null,
                ]);

                Notification::make()
                    ->title('Tổng tiền đã trở về đúng số đã thanh toán')
                    ->body('Không còn khoản phát sinh/hoàn tiền nào cần xử lý.')
                    ->success()
                    ->send();

                return;
            }

            // ── Đơn đã paid: tạo PayOS mới cho khoản phát sinh ───────────────
            if ($cumulativeDiff > 0) {
                $result = $extraChargeService->createExtraChargePayOS($order, $cumulativeDiff);

                // Notify admin (Filament)
                Notification::make()
                    ->title('Phát sinh thêm ' . number_format($incrementalDiff, 0, ',', '.') . 'đ')
                    ->body('Tổng cần thu thêm hiện tại: ' . number_format($cumulativeDiff, 0, ',', '.') . 'đ. Link thanh toán: ' . $result['checkout_url'])
                    ->actions([
                        \Filament\Notifications\Actions\Action::make('view_link')
                            ->label('Mở link PayOS')
                            ->url($result['checkout_url'])
                            ->openUrlInNewTab(),
                    ])
                    ->warning()
                    ->persistent()
                    ->send();

                // FCM + WS → khách
                $clientTitle = "Đơn #{$order->order_code}: phát sinh thêm " . number_format($incrementalDiff, 0, ',', '.') . 'đ';
                $clientBody  = 'Đơn hàng vừa được cập nhật (dịch vụ/số người/khung giờ). Vui lòng thanh toán khoản phát sinh.';
                $this->pushClientNotification($order, $notifService, $clientTitle, $clientBody, 'order_extra_charge',
                    array_merge($extra, ['amount' => $cumulativeDiff])
                );

                try {
                    app(OrderRealtimeService::class)->broadcastOrderUpdate(
                        (string) $order->order_code,
                        ['extra_charge' => ['amount' => $cumulativeDiff, 'qr_code' => $result['qr_code'], 'is_paid' => false]],
                        $order->customer_id ? (int) $order->customer_id : null,
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('handlePriceDiff: paid extra charge broadcastOrderUpdate failed', [
                        'order_id' => $order->id, 'error' => $e->getMessage(),
                    ]);
                }
            } else {
                // Giảm giá (huỷ khung giờ/dịch vụ) trên đơn ĐÃ paid — PHẢI lưu lại khoản cần hoàn
                // (extra_refund_amount), KHÔNG chỉ bắn Notification thoáng qua như trước — nếu
                // không lưu, admin bỏ lỡ thông báo là mất dấu hoàn toàn, không còn cách nào biết đơn
                // này đang nợ khách 1 khoản hoàn tiền. Xem panel "Hoàn tiền chưa xử lý" ở OrderForm.
                $extraChargeService->recordPendingRefund($order, abs($cumulativeDiff));

                Notification::make()
                    ->title('Tổng tiền giảm ' . number_format(abs($incrementalDiff), 0, ',', '.') . 'đ')
                    ->body('Tổng cần hoàn khách hiện tại: ' . number_format(abs($cumulativeDiff), 0, ',', '.') . 'đ — xem panel "Hoàn tiền chưa xử lý" ở tab Thông tin thanh toán.')
                    ->info()
                    ->persistent()
                    ->send();

                $clientTitle = "Đơn #{$order->order_code}: tổng tiền đã giảm";
                $clientBody  = 'Đơn hàng đã được điều chỉnh (dịch vụ/số người/khung giờ), giảm ' . number_format(abs($incrementalDiff), 0, ',', '.') . 'đ.';
                $this->pushClientNotification($order, $notifService, $clientTitle, $clientBody, 'order_extra_charge', $extra);

                try {
                    $order->refresh();
                    app(OrderRealtimeService::class)->broadcastOrderUpdate(
                        (string) $order->order_code,
                        ['summary' => ['grand_total' => (int) $order->full_amount + (int) ($order->extra_charge_amount ?? 0)]],
                        $order->customer_id ? (int) $order->customer_id : null,
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('handlePriceDiff: paid price reduction broadcastOrderUpdate failed', [
                        'order_id' => $order->id, 'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('handlePriceDiff failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);

            Notification::make()
                ->title('Không thể tạo link thanh toán phát sinh')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * QUAN TRỌNG: chỉ gán lại $this->record = $record->fresh() là CHƯA ĐỦ để Section/Placeholder
     * (vd "Phát sinh thêm", "Hoàn tiền chưa xử lý", badge tab) hiện đúng dữ liệu MỚI ngay trong
     * CÙNG lần render — Filament CACHE object Form MỘT LẦN mỗi request (xem
     * InteractsWithForms::$cachedForms), và Form đó tự "khoá" record qua ->model($record) LÚC ĐƯỢC
     * BUILD LẦN ĐẦU (thường là ngay khi bấm Lưu, TRƯỚC khi handlePriceDiff() kịp chạy). Các closure
     * ->visible(fn ($record) => ...) đọc $record qua ComponentContainer::getRecord(), tức là đọc
     * đúng con "record đã khoá" đó — gán lại $this->record KHÔNG tự động cập nhật lại được, phải
     * gọi thẳng $this->getForm('form')->model($record) để "mở khoá" và trỏ Form sang bản ghi mới.
     * Thiếu bước này là lý do CHÍNH XÁC của hiện tượng "bấm 1 lần không thấy gì, bấm thêm 1 lần
     * (mở request MỚI, Form được cache lại từ đầu) mới hiện đúng".
     */
    private function syncRecordAndForm(\Modules\Payment\Entities\Order $record): void
    {
        $this->record = $record;

        if ($this->hasCachedForm('form')) {
            $this->getForm('form')->model($record);
        }
    }

    /**
     * 5 action Livewire public gọi thẳng từ "Lịch sử thanh toán" (wire:click ngay trên dòng phát
     * sinh/hoàn tiền mới nhất — xem OrderForm.php phần payment_timeline) — admin xử lý khoản phát
     * sinh/hoàn tiền NGAY tại chỗ, không cần đi tìm Section "Phát sinh thêm"/"Hoàn tiền chưa xử lý"
     * ở dưới. Dùng chung ExtraChargeService với các Action trong Section đó nên hành vi giống hệt.
     */
    public function quickCreateExtraChargeQr(): void
    {
        $record = $this->record->fresh();
        $amount = (int) ($record->extra_charge_amount ?? 0);

        if ($amount <= 0 || ! is_null($record->extra_charge_paid_at)) {
            Notification::make()->warning()->title('Không có khoản phát sinh cần tạo QR')->send();
            return;
        }

        try {
            $result = app(ExtraChargeService::class)->createExtraChargePayOS($record, $amount);

            AuditLogger::log(
                'update', 'Order', $record, [],
                ['Tạo QR phát sinh' => number_format($amount, 0, ',', '.') . 'đ'],
                'Đơn #' . $record->order_code,
            );

            Notification::make()
                ->success()
                ->title('Đã tạo QR thanh toán phát sinh')
                ->body('Link: ' . $result['checkout_url'])
                ->actions([
                    \Filament\Notifications\Actions\Action::make('open')
                        ->label('Mở link')
                        ->url($result['checkout_url'])
                        ->openUrlInNewTab(),
                ])
                ->persistent()
                ->send();

            $this->syncRecordAndForm($record);
            $this->refreshFormData(['extra_charge_checkout_url', 'extra_charge_qr_code', 'extra_charge_expired_at', 'extra_charge_payment_method', 'extra_charge_paid_at']);
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Lỗi: ' . $e->getMessage())->send();
        }
    }

    public function quickMarkExtraChargeTransfer(): void
    {
        $record = $this->record->fresh();
        $amount = (int) ($record->extra_charge_amount ?? 0);

        if ($amount <= 0 || ! is_null($record->extra_charge_paid_at)) {
            Notification::make()->warning()->title('Không có khoản phát sinh chờ thanh toán')->send();
            return;
        }

        try {
            app(ExtraChargeService::class)->markExtraChargeAsTransfer($record, $amount);

            AuditLogger::log(
                'update', 'Order', $record, [],
                ['Xác nhận chuyển khoản phát sinh' => number_format($amount, 0, ',', '.') . 'đ'],
                'Đơn #' . $record->order_code,
            );

            Notification::make()->success()->title('Đã ghi nhận chuyển khoản')->body(number_format($amount, 0, ',', '.') . 'đ')->send();

            $this->syncRecordAndForm($record);
            $this->refreshFormData(['extra_charge_amount', 'extra_charge_paid_at', 'extra_charge_payment_method']);
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Lỗi: ' . $e->getMessage())->send();
        }
    }

    public function quickMarkExtraChargeCash(): void
    {
        $record = $this->record->fresh();
        $amount = (int) ($record->extra_charge_amount ?? 0);

        if ($amount <= 0 || ! is_null($record->extra_charge_paid_at)) {
            Notification::make()->warning()->title('Không có khoản phát sinh chờ thanh toán')->send();
            return;
        }

        try {
            app(ExtraChargeService::class)->markExtraChargeAsCash($record, $amount);

            AuditLogger::log(
                'update', 'Order', $record, [],
                ['Xác nhận thu tiền mặt phát sinh' => number_format($amount, 0, ',', '.') . 'đ'],
                'Đơn #' . $record->order_code,
            );

            Notification::make()->success()->title('Đã ghi nhận thu tiền mặt')->body(number_format($amount, 0, ',', '.') . 'đ')->send();

            $this->syncRecordAndForm($record);
            $this->refreshFormData(['extra_charge_amount', 'extra_charge_paid_at', 'extra_charge_payment_method']);
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Lỗi: ' . $e->getMessage())->send();
        }
    }

    public function quickMarkRefundTransfer(): void
    {
        $record = $this->record->fresh();
        $amount = (int) ($record->extra_refund_amount ?? 0);

        if ($amount <= 0 || ! is_null($record->extra_refund_paid_at)) {
            Notification::make()->warning()->title('Không có khoản hoàn tiền chờ xử lý')->send();
            return;
        }

        try {
            app(ExtraChargeService::class)->markRefundAsDone($record, $amount, 'bank_transfer');

            AuditLogger::log(
                'update', 'Order', $record, [],
                ['Đã hoàn tiền (chuyển khoản)' => number_format($amount, 0, ',', '.') . 'đ'],
                'Đơn #' . $record->order_code,
            );

            Notification::make()->success()->title('Đã ghi nhận hoàn tiền')->body(number_format($amount, 0, ',', '.') . 'đ')->send();

            $this->syncRecordAndForm($record);
            $this->refreshFormData(['extra_refund_amount', 'extra_refund_paid_at', 'extra_refund_method']);
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Lỗi: ' . $e->getMessage())->send();
        }
    }

    public function quickMarkRefundCash(): void
    {
        $record = $this->record->fresh();
        $amount = (int) ($record->extra_refund_amount ?? 0);

        if ($amount <= 0 || ! is_null($record->extra_refund_paid_at)) {
            Notification::make()->warning()->title('Không có khoản hoàn tiền chờ xử lý')->send();
            return;
        }

        try {
            app(ExtraChargeService::class)->markRefundAsDone($record, $amount, 'cash');

            AuditLogger::log(
                'update', 'Order', $record, [],
                ['Đã hoàn tiền (tiền mặt)' => number_format($amount, 0, ',', '.') . 'đ'],
                'Đơn #' . $record->order_code,
            );

            Notification::make()->success()->title('Đã ghi nhận hoàn tiền')->body(number_format($amount, 0, ',', '.') . 'đ')->send();

            $this->syncRecordAndForm($record);
            $this->refreshFormData(['extra_refund_amount', 'extra_refund_paid_at', 'extra_refund_method']);
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Lỗi: ' . $e->getMessage())->send();
        }
    }

    /**
     * Gửi FCM + WS notification đến khách khi admin cập nhật guest_count / services.
     */
    private function sendOrderUpdateNotification(\Modules\Payment\Entities\Order $order, array $changes): void
    {
        $parts = [];
        if (isset($changes['guest_count'])) {
            $parts[] = 'Số người: ' . $changes['guest_count']['to'];
        }
        if (isset($changes['services'])) {
            $names   = array_column($changes['services'], 'service_name');
            $parts[] = 'Dịch vụ: ' . implode(', ', $names);
        }

        if (empty($parts)) {
            return;
        }

        $title = "Đơn #{$order->order_code} đã được cập nhật";
        $body  = implode(' · ', $parts);
        $extra = ['order_code' => (string) $order->order_code, 'type' => 'order_updated'];

        $this->pushClientNotification(
            $order,
            app(\App\Services\NotificationFcmService::class),
            $title,
            $body,
            'order_updated',
            $extra,
        );
    }

    /**
     * Push FCM + WS notification đến khách (customer hoặc guest).
     * Tương tự sendStatusChangeNotification nhưng dùng được cho mọi loại notification.
     */
    private function pushClientNotification(
        \Modules\Payment\Entities\Order $order,
        \App\Services\NotificationFcmService $notifService,
        string $title,
        string $body,
        string $type,
        array $extra = [],
    ): void {
        try {
            if (is_null($order->customer_id) && $order->device_token) {
                $notifService->sendToGuestToken($order->device_token, $title, $body, $type, $extra);
                return;
            }

            if ($order->customer_id) {
                $customer = $order->customer;
                if ($customer) {
                    $notifService->sendToCustomer($customer, $title, $body, $type, $extra);
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('pushClientNotification failed', [
                'order_id' => $order->id,
                'type'     => $type,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
