<?php

namespace Modules\VehicleRental\App\Services;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Services\NotificationFcmService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Payment\Entities\Order;
use Modules\VehicleRental\App\Models\VehicleBooking;
use Modules\VehicleRental\App\Models\VehicleDocument;

/**
 * Thông báo thuê xe: đẩy FCM + lưu vào GET /api/notifications cho KHÁCH (NotificationFcmService), chuông + FCM cho NHÂN VIÊN
 * (AdminNotificationService). Mọi hàm đều "best effort": lỗi gửi chỉ ghi log, không làm hỏng thao tác nghiệp vụ; tắt hẳn bằng
 * config('vehiclerental.notifications.enabled') (VEHICLE_RENTAL_NOTIFICATIONS=false). Khách không có trong DB → bỏ qua.
 *
 * Loại thông báo khách (field "type" ở GET /api/notifications, kèm "vehicle_booking_code" để mở đúng đơn):
 *  vehicle_booking_confirmed, vehicle_pickup_reminder, vehicle_return_reminder, vehicle_overdue, vehicle_booking_cancelled,
 *  vehicle_booking_expired, vehicle_payment_refund, vehicle_refund_done, vehicle_settled, vehicle_no_show,
 *  vehicle_document_approved, vehicle_document_rejected, vehicle_room_order_cancelled.
 * Loại thông báo nhân viên (viewData.type): vehicle_rental_booking, vehicle_rental_document, vehicle_rental_overdue, vehicle_rental_refund,
 */
class VehicleRentalNotifier
{
    public function __construct(
        private NotificationFcmService $customerPush,
        private AdminNotificationService $staffPush,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) config('vehiclerental.notifications.enabled', true);
    }

    // ---------------- Khách ----------------

    public function bookingConfirmed(VehicleBooking $b): void
    {
        $body = "Đơn {$b->code}: {$this->vehicleName($b)}. Nhận xe lúc {$this->time($b->start_at)} tại {$this->pickupPlace($b)}.";
        if ($reason = $this->kycBlocker($b)) {
            $body .= " Cần bổ sung giấy tờ trước khi nhận xe: {$reason}";
        }
        $this->toCustomer($b, 'vehicle_booking_confirmed', 'Đặt xe thành công', $body);

        $this->toStaff('vehicle_rental_booking', 'Đơn thuê xe mới',
            "{$b->code}: {$this->vehicleName($b)} — {$this->customerName($b)}, nhận xe {$this->time($b->start_at)}.",
            ['vehicle_booking_code' => $b->code], 'heroicon-o-truck', 'success', $this->bookingUrl($b));
    }

    public function pickupReminder(VehicleBooking $b): void
    {
        $body = "Đơn {$b->code} nhận xe lúc {$this->time($b->start_at)} tại {$this->pickupPlace($b)}. Mang theo CCCD, GPLX và khoản cọc thế chấp " . $this->money($b->deposit_amount) . '.';
        if ($reason = $this->kycBlocker($b)) {
            $body .= " Lưu ý: {$reason}";
        }
        $this->toCustomer($b, 'vehicle_pickup_reminder', 'Sắp đến giờ nhận xe', $body);
    }

    public function returnReminder(VehicleBooking $b): void
    {
        $this->toCustomer($b, 'vehicle_return_reminder', 'Sắp đến giờ trả xe',
            "Đơn {$b->code} cần trả xe lúc {$this->time($b->end_at)} tại {$this->branch($b)}. Trả trễ sẽ bị tính phụ phí.");
    }

    public function overdue(VehicleBooking $b): void
    {
        $this->toCustomer($b, 'vehicle_overdue', 'Đã quá giờ trả xe',
            "Đơn {$b->code} đã quá hạn trả xe ({$this->time($b->end_at)}). Vui lòng trả xe sớm tại {$this->branch($b)} để tránh phát sinh thêm phụ phí.");

        $this->toStaff('vehicle_rental_overdue', 'Khách trả xe trễ',
            "{$b->code} ({$this->vehicleName($b)}) — {$this->customerName($b)} quá hạn trả xe từ {$this->time($b->end_at)}." . $this->gpsLocationText($b),
            ['vehicle_booking_code' => $b->code], 'heroicon-o-clock', 'warning', $this->bookingUrl($b));
    }

    /** Đơn bị huỷ. $byStaff = nhân viên huỷ (khách mới cần được báo; khách tự huỷ thì chỉ báo nhân viên nếu cần hoàn tiền). */
    public function cancelled(VehicleBooking $b, bool $byStaff): void
    {
        if ($byStaff) {
            $body = "Đơn {$b->code} đã bị huỷ" . ($b->cancel_reason ? ": {$b->cancel_reason}." : '.');
            if ($b->refund_amount > 0) {
                $body .= ' Tiền thuê ' . $this->money($b->refund_amount) . ' sẽ được hoàn cho bạn.';
            }
            $this->toCustomer($b, 'vehicle_booking_cancelled', 'Đơn thuê xe đã bị huỷ', $body);
        }

        if ($b->refund_amount > 0) {
            $this->toStaff('vehicle_rental_refund', 'Cần hoàn tiền thuê xe',
                "{$b->code}: hoàn " . $this->money($b->refund_amount) . " cho {$this->customerName($b)} ({$b->cancel_reason}).",
                ['vehicle_booking_code' => $b->code], 'heroicon-o-banknotes', 'danger', $this->bookingUrl($b));
        }
    }

    public function expired(VehicleBooking $b): void
    {
        $this->toCustomer($b, 'vehicle_booking_expired', 'Đơn thuê xe đã hết hạn thanh toán',
            "Đơn {$b->code} chưa được thanh toán trong thời gian giữ xe nên đã bị huỷ. Bạn có thể đặt lại nếu vẫn cần xe.");
    }

    /** Tiền về sau khi hết hạn giữ chỗ và xe đã có người đặt → huỷ + hoàn toàn bộ. */
    public function latePaymentRefund(VehicleBooking $b): void
    {
        $this->toCustomer($b, 'vehicle_payment_refund', 'Thanh toán đến muộn — sẽ hoàn tiền',
            "Đơn {$b->code}: thanh toán của bạn đến sau khi hết hạn giữ xe và xe đã được đặt. 365Home sẽ hoàn " . $this->money($b->refund_amount) . ' cho bạn.');

        $this->toStaff('vehicle_rental_refund', 'Cần hoàn tiền thuê xe',
            "{$b->code}: hoàn " . $this->money($b->refund_amount) . " cho {$this->customerName($b)} (thanh toán đến muộn, xe đã có người đặt).",
            ['vehicle_booking_code' => $b->code], 'heroicon-o-banknotes', 'danger', $this->bookingUrl($b));
    }

    public function refundDone(VehicleBooking $b): void
    {
        $this->toCustomer($b, 'vehicle_refund_done', 'Đã hoàn tiền thuê xe',
            '365Home đã chuyển khoản hoàn ' . $this->money($b->refund_amount) . " cho đơn {$b->code}.");
    }

    public function settled(VehicleBooking $b): void
    {
        $body = "Đơn {$b->code} đã hoàn tất.";
        if ($b->extra_amount > 0) {
            $body .= ' Phụ phí phát sinh ' . $this->money($b->extra_amount) . '.';
        }
        if ($b->deposit_refund_amount > 0) {
            $body .= ' Cọc hoàn lại ' . $this->money($b->deposit_refund_amount) . '.';
        }
        if ($b->extra_due_amount > 0) {
            $body .= ' Khoản vượt cọc ' . $this->money($b->extra_due_amount) . ' đã thu tại quầy.';
        }
        $this->toCustomer($b, 'vehicle_settled', 'Hoàn tất thuê xe', $body . ' Cảm ơn bạn đã sử dụng dịch vụ!');
    }

    public function noShow(VehicleBooking $b): void
    {
        $this->toCustomer($b, 'vehicle_no_show', 'Bạn chưa đến nhận xe',
            "Đơn {$b->code} đã đóng vì bạn không đến nhận xe sau giờ hẹn {$this->time($b->start_at)}. Tiền thuê không được hoàn theo chính sách.");
    }

    public function documentSubmitted(VehicleDocument $doc): void
    {
        $name = $doc->customer?->fullname ?: ($doc->full_name ?: 'Khách');
        $this->toStaff('vehicle_rental_document', 'Hồ sơ giấy tờ chờ duyệt', "{$name} vừa nộp giấy tờ thuê xe.",
            ['customer_id' => (string) $doc->customer_id], 'heroicon-o-identification', 'info', $this->documentUrl($doc));
    }

    public function documentReviewed(VehicleDocument $doc): void
    {
        $customer = $this->findCustomer((string) $doc->customer_id);
        if (! $customer) {
            return;
        }

        if ($doc->status === VehicleDocument::STATUS_APPROVED) {
            $this->push($customer, 'vehicle_document_approved', 'Giấy tờ thuê xe đã được duyệt', 'Giấy tờ của bạn đã được duyệt. Bạn có thể nhận xe tại quầy theo lịch đã đặt.', []);
        } elseif ($doc->status === VehicleDocument::STATUS_REJECTED) {
            $this->push($customer, 'vehicle_document_rejected', 'Giấy tờ thuê xe bị từ chối',
                'Giấy tờ của bạn chưa đạt: ' . ($doc->reject_reason ?: 'vui lòng nộp lại') . '. Hãy nộp lại trong ứng dụng.', []);
        }
    }

    /** Đơn phòng bị huỷ/hoàn tiền: hỏi khách có muốn huỷ đơn thuê xe kèm theo (không tự huỷ). */
    public function roomOrderCancelled(Order $order): void
    {
        $bookings = VehicleBooking::query()->with('vehicle')
            ->where('room_order_id', $order->id)
            ->whereIn('status', VehicleBooking::BLOCKING_STATUSES)->get();

        foreach ($bookings as $b) {
            $this->toCustomer($b, 'vehicle_room_order_cancelled', 'Đơn phòng đã huỷ — bạn còn đơn thuê xe',
                "Đơn phòng #{$order->order_code} đã huỷ. Đơn thuê xe {$b->code} kèm theo vẫn còn hiệu lực — mở ứng dụng để huỷ nếu bạn không cần xe nữa.");
        }
    }

    // ---------------- Hạ tầng ----------------

    /** @return Collection<int, User> Super Admin + vai trò "Nhân viên thuê xe". */
    public function staffRecipients(): Collection
    {
        $names = array_values(array_filter([
            config('filament-shield.super_admin.name'),
            config('vehiclerental.staff_role'),
        ]));
        $existing = Role::query()->whereIn('name', $names)->pluck('name')->all();

        return $existing ? User::role($existing)->get()->unique('id')->values() : collect();
    }

    private function toCustomer(VehicleBooking $b, string $type, string $title, string $body): void
    {
        if (! $this->enabled()) {
            return;
        }
        $customer = $this->findCustomer((string) $b->customer_id);
        if ($customer) {
            $this->push($customer, $type, $title, $body, ['vehicle_booking_code' => $b->code]);
        }
    }

    /** @param  array<string, string>  $extra  giá trị PHẢI là chuỗi (payload data của FCM) */
    private function push(Customer $customer, string $type, string $title, string $body, array $extra): void
    {
        if (! $this->enabled()) {
            return;
        }
        try {
            $this->customerPush->sendToCustomer($customer, $title, $body, $type, $extra);
        } catch (\Throwable $e) {
            Log::warning('Thuê xe: gửi thông báo cho khách thất bại', ['type' => $type, 'customer' => $customer->id, 'error' => $e->getMessage()]);
        }
    }

    /** @param  array<string, string>  $data */
    private function toStaff(string $type, string $title, string $body, array $data, string $icon, string $color, ?string $url): void
    {
        if (! $this->enabled()) {
            return;
        }
        try {
            $users = $this->staffRecipients();
            if ($users->isNotEmpty()) {
                $this->staffPush->notify($users, $title, $body, ['type' => $type] + $data, $icon, $color, $url);
            }
        } catch (\Throwable $e) {
            Log::warning('Thuê xe: gửi thông báo cho nhân viên thất bại', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    private function findCustomer(string $id): ?Customer
    {
        return $id === '' ? null : Customer::query()->find($id);
    }

    private function kycBlocker(VehicleBooking $b): ?string
    {
        $b->loadMissing('vehicle');

        return $b->vehicle ? app(VehicleKycService::class)->ineligibleReason((string) $b->customer_id, $b->vehicle, $b->end_at) : null;
    }

    private function vehicleName(VehicleBooking $b): string
    {
        $b->loadMissing('vehicle');

        return $b->vehicle ? trim("{$b->vehicle->brand} {$b->vehicle->model} ({$b->vehicle->plate_display})") : 'xe';
    }

    /** Điểm giao xe = vị trí hiện tại của xe (GPS); chưa có GPS thì chi nhánh. */
    private function pickupPlace(VehicleBooking $b): string
    {
        $b->loadMissing('vehicle.branch');
        try {
            $p = $b->vehicle ? \Modules\VehicleRental\App\Support\VehicleTransformer::pickupPoint($b) : null;
        } catch (\Throwable) {
            $p = null;
        }
        if ($p && $p['source'] === 'vehicle') {
            return filled($p['address']) ? 'vị trí xe: ' . $p['address'] : 'vị trí xe hiện tại (xem trong đơn)';
        }

        return $this->branch($b);
    }

    private function branch(VehicleBooking $b): string
    {
        $b->loadMissing('vehicle.branch');

        return $b->vehicle?->branch?->name ?: 'chi nhánh 365Home';
    }

    private function customerName(VehicleBooking $b): string
    {
        return $this->findCustomer((string) $b->customer_id)?->fullname ?: 'Khách';
    }

    private function time($dt): string
    {
        return $dt ? $dt->format('H:i d/m/Y') : '';
    }

    private function money(int $v): string
    {
        return number_format($v, 0, ',', '.') . 'đ';
    }

    /** " Vị trí hiện tại: ..." nếu xe đã ghép GPS và có vị trí (chỉ gửi cho nhân viên). */
    private function gpsLocationText(VehicleBooking $b): string
    {
        try {
            $b->loadMissing('vehicle');
            $c = $b->vehicle ? app(VehicleGpsService::class)->current($b->vehicle) : null;
            if (! $c || empty($c['linked']) || empty($c['position'])) {
                return '';
            }

            return ' Vị trí GPS hiện tại: ' . ($c['address'] ?: $c['position']['latitude'] . ', ' . $c['position']['longitude'])
                . ($c['connection_state'] === 'stale' ? ' (dữ liệu GPS đã cũ)' : '') . '.';
        } catch (\Throwable) {
            return '';
        }
    }

    private function bookingUrl(VehicleBooking $b): ?string
    {
        try {
            return \Modules\VehicleRental\App\Filament\Resources\VehicleBookingResource::getUrl('view', ['record' => $b], panel: 'admin');
        } catch (\Throwable) {
            return null;
        }
    }

    private function documentUrl(VehicleDocument $doc): ?string
    {
        try {
            return \Modules\VehicleRental\App\Filament\Resources\VehicleDocumentResource::getUrl('view', ['record' => $doc], panel: 'admin');
        } catch (\Throwable) {
            return null;
        }
    }
}
