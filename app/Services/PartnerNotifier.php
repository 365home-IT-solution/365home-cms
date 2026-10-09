<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\LockNotificationMail;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

// Điểm gửi thông báo TÀI CHÍNH cho đối tác (ký quỹ, đối soát, tiền đặt phòng): thông báo trong app + push (qua
// AdminNotificationService) và EMAIL tới email đối tác + email các tài khoản chủ đối tác. Lỗi gửi chỉ báo cáo,
// không làm hỏng nghiệp vụ đang chạy.
class PartnerNotifier
{
    public function __construct(private AdminNotificationService $notifications) {}

    /** @param array<string, mixed> $data */
    public function send(Partner $partner, string $type, string $title, string $body, array $data = [], string $color = 'warning', bool $email = true): void
    {
        try {
            $this->notifications->notify(
                User::query()->where('partner_id', $partner->id)->get(),
                $title,
                $body,
                ['type' => $type, 'partner_id' => $partner->id, 'partner_type' => $partner->partner_type, ...$data],
                'heroicon-o-banknotes',
                $color,
                $this->urlFor($partner, $type, $data),
            );
        } catch (\Throwable $e) {
            report($e);
        }

        if ($email) {
            $this->email($partner, $title, $body);
        }
    }

    /** Mỗi thông báo mở đúng màn: ký quỹ → màn Ký quỹ của đối tác; đối soát → bảng đối soát (hoặc danh sách); đơn thanh toán → không có màn riêng. */
    private function urlFor(Partner $partner, string $type, array $data): ?string
    {
        try {
            if (str_starts_with($type, 'escrow_')) {
                return \App\Filament\Resources\PartnerEscrowResource::getUrl('view', ['record' => $partner->id]);
            }

            // Yêu cầu hoàn tiền khách: mở danh sách yêu cầu (đối tác bấm "Đã hoàn tiền cho khách" ngay tại đó).
            if (str_starts_with($type, 'refund_claim_')) {
                return \App\Filament\Resources\PartnerRefundClaimResource::getUrl('index');
            }

            // Thông báo gắn với 1 đơn (tiền về tài khoản, yêu cầu hoàn tiền): mở đúng đơn.
            if (filled($data['order_code'] ?? null) && ! str_starts_with($type, 'settlement_')) {
                $orderId = \Modules\Payment\Entities\Order::withoutGlobalScopes()->where('order_code', $data['order_code'])->value('id');

                return $orderId ? \Modules\Payment\App\Filament\Resources\OrderResource::getUrl('edit', ['record' => $orderId]) : null;
            }

            if (str_starts_with($type, 'settlement_')) {
                return isset($data['settlement_id'])
                    ? \App\Filament\Resources\PartnerSettlementResource::getUrl('view', ['record' => $data['settlement_id']])
                    : \App\Filament\Resources\PartnerSettlementResource::getUrl('index');
            }
        } catch (\Throwable $e) {
            // Chưa dựng được panel (vd chạy ở console/queue) — thông báo vẫn gửi, chỉ không kèm nút "Xem".
        }

        return null;
    }

    public function email(Partner $partner, string $title, string $body): void
    {
        $recipients = User::query()->where('partner_id', $partner->id)->role('partner')->pluck('email')
            ->push($partner->email)
            ->filter(fn ($address) => filled($address) && filter_var($address, FILTER_VALIDATE_EMAIL))
            ->map(fn ($address) => mb_strtolower(trim((string) $address)))
            ->unique()->values()->all();

        if ($recipients === []) {
            return;
        }

        try {
            Mail::to($recipients)->send(new LockNotificationMail(
                $title,
                '<p>Kính gửi ' . e($partner->legal_name ?: $partner->name) . ',</p><p>' . nl2br(e($body)) . '</p><p>Xem chi tiết trong ứng dụng quản trị 365home.</p>'
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
