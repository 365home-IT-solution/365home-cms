<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Support\Pay2SConfig;
use Illuminate\Validation\ValidationException;
use Modules\Payment\Entities\PaymentCollection;

/**
 * Tạo khoản thu tiền độc lập (màn hình admin "Tạo mã thu tiền"): số tiền + nội dung → mã TTxxxxxx + QR + trang QR công khai để gửi khách.
 * Tiền vào tài khoản Pay2S của 365home; khi Pay2S báo tiền vào, PlatformCollectionService khớp theo mã TTxxxxxx trong nội dung chuyển khoản.
 * Dùng chung cho trang Filament và API admin.
 */
class PaymentCollectionService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const MIN_AMOUNT = 1000;

    public const MAX_AMOUNT = 500_000_000;

    /** Tạo được khoản thu khi Pay2S bật và đã khai báo tài khoản ngân hàng của 365home (không phụ thuộc đang chọn cổng PayOS hay Pay2S). */
    public function available(): bool
    {
        return Pay2SConfig::enabled() && Pay2SConfig::platformAccount(false) !== null;
    }

    /**
     * @param array{amount: int|string, description?: ?string, customer_name?: ?string, customer_phone?: ?string, expires_in_hours?: int|string|null} $data
     */
    public function create(array $data, ?string $userId): PaymentCollection
    {
        $account = Pay2SConfig::platformAccount(false);

        if (! Pay2SConfig::enabled() || ! $account) {
            throw ValidationException::withMessages(['amount' => ['Chưa bật Pay2S hoặc chưa khai báo tài khoản ngân hàng của 365home (CMS → Pay2S — cấu hình chung).']]);
        }

        $amount = (int) $data['amount'];

        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            throw ValidationException::withMessages(['amount' => ['Số tiền phải từ ' . number_format(self::MIN_AMOUNT, 0, ',', '.') . 'đ đến ' . number_format(self::MAX_AMOUNT, 0, ',', '.') . 'đ.']]);
        }

        $hours = (int) ($data['expires_in_hours'] ?? 0);

        return PaymentCollection::query()->create([
            'code' => $this->newCode(),
            'amount' => $amount,
            'description' => filled($data['description'] ?? null) ? mb_substr(trim((string) $data['description']), 0, 255) : null,
            'customer_name' => filled($data['customer_name'] ?? null) ? mb_substr(trim((string) $data['customer_name']), 0, 255) : null,
            'customer_phone' => filled($data['customer_phone'] ?? null) ? mb_substr(trim((string) $data['customer_phone']), 0, 30) : null,
            'bank_code' => $account->bank_code ?: null,
            'bank_bin' => (string) $account->bank_bin,
            'account_number' => (string) $account->account_number,
            'account_holder' => $account->account_holder ?: null,
            'status' => PaymentCollection::PENDING,
            'expires_at' => $hours > 0 ? now()->addHours(min($hours, 24 * 30)) : null,
            'created_by' => $userId,
        ]);
    }

    /** Huỷ khoản chưa thanh toán: QR cũ không còn nhận được (tiền chuyển vào sau đó bị ghi "rejected" để đối soát/hoàn tiền). */
    public function cancel(PaymentCollection $collection): PaymentCollection
    {
        if ($collection->status === PaymentCollection::PAID) {
            throw ValidationException::withMessages(['status' => ['Khoản này đã được thanh toán, không huỷ được.']]);
        }

        $collection->update(['status' => PaymentCollection::CANCELLED, 'cancelled_at' => $collection->cancelled_at ?? now()]);

        return $collection->fresh();
    }

    /** Tiền vào đã khớp mã (gọi từ PlatformCollectionService, trong luồng webhook). Trả lý do từ chối, null = đã ghi nhận. */
    public function markPaid(string $code, int $amount, string $reference): ?string
    {
        $collection = PaymentCollection::query()->where('code', $code)->lockForUpdate()->first();

        if (! $collection) {
            return "Không tìm thấy khoản thu {$code}.";
        }
        if ($collection->status === PaymentCollection::PAID) {
            return "Khoản thu {$code} đã được thanh toán trước đó (chuyển trùng) — cần hoàn tiền thủ công.";
        }
        if ($collection->status === PaymentCollection::CANCELLED) {
            return "Khoản thu {$code} đã bị huỷ nhưng vẫn nhận được tiền — cần hoàn tiền thủ công.";
        }
        if ($amount < (int) $collection->amount) {
            return "Thiếu tiền khoản thu {$code}: cần {$collection->amount}, nhận {$amount} — chưa ghi nhận, xử lý thủ công.";
        }

        $collection->update(['status' => PaymentCollection::PAID, 'paid_at' => now(), 'paid_amount' => $amount, 'bank_reference' => $reference]);

        return null;
    }

    private function newCode(): string
    {
        do {
            $code = 'TT';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (PaymentCollection::query()->where('code', $code)->exists());

        return $code;
    }
}
