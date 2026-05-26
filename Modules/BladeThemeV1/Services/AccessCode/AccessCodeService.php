<?php

namespace Modules\BladeThemeV1\Services\AccessCode;

use Modules\TTLock\App\Services\TTLockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\AccessCode\Entities\AccessCode;
use Modules\Payment\Entities\Order;

class AccessCodeService
{

    /**
     * Lấy mã access code VALID cho chi nhánh
     */
    public function getValidCodeForBranch($categoryId, $checkinDate = null, $checkoutDate = null)
    {
        $query = AccessCode::where('status', 'active')
            ->forBranch($categoryId)
            ->lockForUpdate();

        if ($checkinDate && $checkoutDate) {
            // Mã phải còn hiệu lực trong toàn bộ khoảng thời gian ở phòng
            // valid_from <= checkin (hoặc không giới hạn ngày bắt đầu)
            $query->where(function ($q) use ($checkinDate) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $checkinDate);
            })
            // valid_until >= checkout (hoặc không giới hạn ngày kết thúc)
            ->where(function ($q) use ($checkoutDate) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $checkoutDate);
            });
        } else {
            // Không có checkin/checkout → chỉ cần mã đang active và hiệu lực ngay bây giờ
            $query->where(function ($q) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', now());
            });
        }

        return $query->first();
    }

    /**
     * Gán mã cổng cho đơn hàng.
     * - Nếu product có lock_id: generate mã mới qua TTLock API (mỗi đơn 1 mã riêng)
     * - Nếu không có product hoặc không có lock_id: lấy từ danh sách nhập tay (legacy)
     *
     * @param  int        $orderId
     * @param  int|null   $categoryId
     * @param  mixed      $checkinDate
     * @param  mixed      $checkoutDate
     * @param  mixed|null $product   Product model (có lock_id / lock_id_checkout)
     */
    public function assignCodeToOrder($orderId, $categoryId, $checkinDate = null, $checkoutDate = null, $product = null)
    {
        // Nếu product có lock_id → generate mã TTLock riêng cho đơn này
        if ($product && $product->lock_id) {
            return $this->generateTTLockCodeForOrder($orderId, $categoryId, $product, $checkinDate, $checkoutDate);
        }

        // Fallback: lấy từ danh sách nhập tay
        return DB::transaction(function () use ($orderId, $categoryId, $checkinDate, $checkoutDate) {
            $code = $this->getValidCodeForBranch($categoryId, $checkinDate, $checkoutDate);

            if (!$code) {
                Log::warning('No valid access code found for branch when assigning to order', [
                    'order_id'    => $orderId,
                    'category_id' => $categoryId,
                    'checkin'     => (string) $checkinDate,
                    'checkout'    => (string) $checkoutDate,
                ]);

                throw new \Exception("Không tìm thấy mã access code khả dụng cho chi nhánh này trong khoảng thời gian đã chọn.");
            }

            $code->assignToOrder($orderId);
            $code->refresh();

            return $code;
        });
    }

    /**
     * Tạo mã TTLock mới và gán cho đơn hàng.
     * Mỗi đơn nhận 1 mã riêng (period, hiệu lực từ checkin → checkout).
     * Nếu TTLock thất bại → fallback về danh sách nhập tay.
     */
    protected function generateTTLockCodeForOrder($orderId, $categoryId, $product, $checkinDate, $checkoutDate)
    {
        $startMs = $checkinDate
            ? (int) (Carbon::parse($checkinDate)->timestamp * 1000)
            : (int) round(microtime(true) * 1000);
        $endMs = $checkoutDate
            ? (int) (Carbon::parse($checkoutDate)->timestamp * 1000)
            : 0;

        $name = "Order #{$orderId}";

        // Tự sinh mã 6 chữ số để đảm bảo đồng nhất
        $generatedCode = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        $ttlock = TTLockService::forCategory($categoryId);

        if (!$ttlock) {
            Log::info('No TTLock account for category, falling back to manual pool', [
                'order_id'    => $orderId,
                'category_id' => $categoryId,
            ]);
            return DB::transaction(function () use ($orderId, $categoryId, $checkinDate, $checkoutDate) {
                $code = $this->getValidCodeForBranch($categoryId, $checkinDate, $checkoutDate);
                if (!$code) {
                    throw new \Exception(
                        "Chi nhánh này chưa được cấu hình tài khoản TTLock và không có mã dự phòng nào trong hệ thống."
                    );
                }
                $code->assignToOrder($orderId);
                $code->refresh();
                return $code;
            });
        }

        // Cấp mã vào khóa checkin (lock_id)
        $checkinResult = $ttlock->addCustomPasscode(
            lockId:    (int) $product->lock_id,
            code:      $generatedCode,
            startDate: $startMs,
            endDate:   $endMs,
            name:      $name,
            pwdType:   3,
        );

        if (!$checkinResult) {
            Log::error('TTLock addCustomPasscode (checkin) failed, falling back to manual pool', [
                'order_id' => $orderId,
                'lock_id'  => $product->lock_id,
            ]);
            // Fallback về danh sách nhập tay
            return DB::transaction(function () use ($orderId, $categoryId, $checkinDate, $checkoutDate) {
                $code = $this->getValidCodeForBranch($categoryId, $checkinDate, $checkoutDate);
                if (!$code) {
                    throw new \Exception(
                        "TTLock API không phản hồi và không có mã dự phòng nào trong hệ thống.\n"
                        . "Vui lòng thử lại sau vài giây hoặc thêm mã thủ công trong mục Pass Cổng."
                    );
                }
                $code->assignToOrder($orderId);
                $code->refresh();
                return $code;
            });
        }

        $pwdIdCheckin   = $checkinResult['keyboardPwdId'];
        $pwdIdCheckout  = null;

        // Nếu có khóa checkout riêng → thêm cùng mã vào khóa đó
        $lockIdCheckout = $product->lock_id_checkout ?? null;
        if ($lockIdCheckout && (int) $lockIdCheckout !== (int) $product->lock_id) {
            $checkoutResult = $ttlock->addCustomPasscode(
                lockId:    (int) $lockIdCheckout,
                code:      $generatedCode,
                startDate: $startMs,
                endDate:   $endMs,
                name:      $name,
                pwdType:   3,
            );
            $pwdIdCheckout = $checkoutResult['keyboardPwdId'] ?? null;
        }

        // Tạo AccessCode record và gán cho đơn
        return DB::transaction(function () use ($orderId, $categoryId, $generatedCode, $pwdIdCheckin, $pwdIdCheckout, $checkinDate, $checkoutDate) {
            $accessCode = AccessCode::create([
                'code'                             => $generatedCode,
                'category_id'                      => $categoryId,
                'status'                           => 'active',
                'valid_from'                       => $checkinDate,
                'valid_until'                      => $checkoutDate,
                'ttlock_keyboard_pwd_id'           => $pwdIdCheckin,
                'ttlock_keyboard_pwd_id_checkout'  => $pwdIdCheckout,
                'notes'                            => 'Auto-generated via TTLock API',
            ]);

            $accessCode->assignToOrder($orderId);
            $accessCode->refresh();
            return $accessCode;
        });
    }

    /**
     * Lấy mã access code khả dụng cho chi nhánh cụ thể
     */
    public function getAvailableCodeForBranch($categoryId)
    {
        return AccessCode::where('status', 'active')
            ->where('category_id', $categoryId)
            // ✅ Bỏ ->whereNull('order_id') vì cột này không tồn tại
            ->lockForUpdate()
            ->first();
    }

    /**
     * Kiểm tra số lượng mã còn lại theo chi nhánh
     */
    public function getAvailableCodesCountByBranch($categoryId)
    {
        return AccessCode::where('status', 'active')
            ->where('category_id', $categoryId)
            // ✅ Bỏ ->whereNull('order_id') vì cột này không tồn tại
            ->count();
    }

    /**
     * Thống kê mã theo chi nhánh
     */
    public function getBranchesStatistics()
    {
        return DB::table('cms_access_codes') // ✅ Đúng tên bảng
            ->join('cms_categories', 'cms_access_codes.category_id', '=', 'cms_categories.id')
            ->select(
                'cms_access_codes.category_id',
                'cms_categories.name as branch_name',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN cms_access_codes.status = "active" THEN 1 ELSE 0 END) as active'),
                DB::raw('SUM(CASE WHEN cms_access_codes.status = "expired" THEN 1 ELSE 0 END) as expired'),
                DB::raw('SUM(CASE WHEN cms_access_codes.status = "disabled" THEN 1 ELSE 0 END) as disabled'),
                DB::raw('SUM(cms_access_codes.usage_count) as total_usage')
            )
            ->groupBy('cms_access_codes.category_id', 'cms_categories.name')
            ->get();
    }

    /**
     * Hủy mã khi hủy đơn hàng.
     * - Mã tự động (có ttlock_keyboard_pwd_id): xóa khỏi TTLock và xóa record AccessCode.
     * - Mã nhập tay: chỉ xóa liên kết pivot.
     */
    public function releaseCode($orderId)
    {
        try {
            $accessCodes = AccessCode::whereHas(
                'orders', fn ($q) => $q->where('orders.id', $orderId)
            )->get();

            foreach ($accessCodes as $ac) {
                if ($ac->ttlock_keyboard_pwd_id) {
                    // Mã tự động → xóa khỏi TTLock
                    $lockIds = $this->getLockIdForOrder($orderId);
                    $ttlock  = TTLockService::forCategory($ac->category_id);
                    if ($lockIds && $ttlock) {
                        $ttlock->deletePasscode((int) $lockIds['checkin'], (int) $ac->ttlock_keyboard_pwd_id);
                        if ($ac->ttlock_keyboard_pwd_id_checkout && $lockIds['checkout']) {
                            $ttlock->deletePasscode((int) $lockIds['checkout'], (int) $ac->ttlock_keyboard_pwd_id_checkout);
                        }
                    }
                    // Xóa AccessCode record tự động (không còn dùng cho đơn nào)
                    $ac->orders()->detach($orderId);
                    $ac->delete();
                } else {
                    // Mã nhập tay → chỉ bỏ liên kết
                    $ac->orders()->detach($orderId);
                }
            }

            // Đảm bảo xóa sạch pivot nếu còn sót
            DB::table('access_code_order')->where('order_id', $orderId)->delete();

            Log::info('Access code released', ['order_id' => $orderId, 'codes_processed' => $accessCodes->count()]);
            return true;

        } catch (\Exception $e) {
            Log::error('releaseCode error', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Lấy lock_id checkin và checkout từ sản phẩm của đơn hàng.
     */
    protected function getLockIdForOrder($orderId): ?array
    {
        $order   = Order::with('items.product')->find($orderId);
        $items   = $order?->items;
        $product = ($items instanceof \Illuminate\Support\Collection ? $items->first() : null)?->product;
        if (!$product) {
            return null;
        }
        return [
            'checkin'  => $product->lock_id,
            'checkout' => $product->lock_id_checkout,
        ];
    }

    /**
     * Cập nhật thời gian hiệu lực của mã cổng khi checkin/checkout thay đổi.
     * - Mã TTLock (có keyboardPwdId): gọi TTLock modifyPasscode
     * - Mã thủ công: chỉ cập nhật valid_from/valid_until trong DB
     *
     * @param  int    $orderId
     * @param  mixed  $checkinDate
     * @param  mixed  $checkoutDate
     * @param  mixed|null $product   Product model
     */
    public function updateCodeDatesForOrder($orderId, $checkinDate, $checkoutDate, $product = null): bool
    {
        $accessCodes = AccessCode::whereHas(
            'orders', fn($q) => $q->where('orders.id', $orderId)
        )->get();

        if ($accessCodes->isEmpty()) {
            return false;
        }

        $startMs = $checkinDate
            ? (int) (Carbon::parse($checkinDate)->timestamp * 1000)
            : (int) round(microtime(true) * 1000);
        $endMs = $checkoutDate
            ? (int) (Carbon::parse($checkoutDate)->timestamp * 1000)
            : 0;

        $success = true;

        foreach ($accessCodes as $ac) {
            // Cập nhật DB
            $ac->update([
                'valid_from'  => $checkinDate,
                'valid_until' => $checkoutDate,
            ]);

            // Nếu là mã TTLock → xóa + tạo lại với cùng mã nhưng thời gian mới
            if ($ac->ttlock_keyboard_pwd_id) {
                $lockIds = $this->getLockIdForOrder($orderId);
                $ttlock  = TTLockService::forCategory($ac->category_id);
                if (!$lockIds || !$ttlock) {
                    continue;
                }

                $name = "Order #{$orderId}";
                $code = (string) $ac->code;

                // --- Khóa checkin: xóa mã cũ, thêm lại mã mới ---
                $ttlock->deletePasscode(
                    lockId:        (int) $lockIds['checkin'],
                    keyboardPwdId: (int) $ac->ttlock_keyboard_pwd_id,
                );

                $newCheckin = $ttlock->addCustomPasscode(
                    lockId:    (int) $lockIds['checkin'],
                    code:      $code,
                    startDate: $startMs,
                    endDate:   $endMs,
                    name:      $name,
                );

                if ($newCheckin) {
                    $ac->update(['ttlock_keyboard_pwd_id' => $newCheckin['keyboardPwdId']]);
                } else {
                    Log::warning('TTLock re-add (checkin) failed', [
                        'order_id' => $orderId,
                        'code'     => $code,
                    ]);
                    $success = false;
                }

                // --- Khóa checkout: xóa mã cũ, thêm lại mã mới ---
                if ($ac->ttlock_keyboard_pwd_id_checkout && $lockIds['checkout']) {
                    $ttlock->deletePasscode(
                        lockId:        (int) $lockIds['checkout'],
                        keyboardPwdId: (int) $ac->ttlock_keyboard_pwd_id_checkout,
                    );

                    $newCheckout = $ttlock->addCustomPasscode(
                        lockId:    (int) $lockIds['checkout'],
                        code:      $code,
                        startDate: $startMs,
                        endDate:   $endMs,
                        name:      $name,
                    );

                    if ($newCheckout) {
                        $ac->update(['ttlock_keyboard_pwd_id_checkout' => $newCheckout['keyboardPwdId']]);
                    } else {
                        Log::warning('TTLock re-add (checkout) failed', [
                            'order_id' => $orderId,
                            'code'     => $code,
                        ]);
                        $success = false;
                    }
                }
            }
        }

        return $success;
    }

    /**
     * Thêm mã mới (Admin nhập thủ công)
     */
    public function addCodeFromDevice(
        $categoryId,
        $code,
        $validFrom = null,
        $validUntil = null,
        $deviceId = null,
        $gateLocation = null,
        $notes = null
    ) {
        if (AccessCode::where('code', $code)->exists()) {
            throw new \Exception("Mã '{$code}' đã tồn tại trong hệ thống.");
        }

        return AccessCode::create([
            'code'          => $code,
            'category_id'   => $categoryId,
            'status'        => 'active',
            'valid_from'    => $validFrom,
            'valid_until'   => $validUntil,
            'gate_location' => $gateLocation,
            'notes'         => $notes,
        ]);
    }

    /**
     * Import nhiều mã cùng lúc (từ CSV hoặc form)
     */
    public function bulkAddCodes($categoryId, array $codes)
    {
        $created = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($codes as $codeData) {
            try {
                if (AccessCode::where('code', $codeData['code'])->exists()) {
                    $skipped++;
                    $errors[] = "Mã '{$codeData['code']}' đã tồn tại";
                    continue;
                }

                AccessCode::create([
                    'code'          => $codeData['code'],
                    'category_id'   => $categoryId,
                    'status'        => 'active',
                    'valid_from'    => $codeData['valid_from'] ?? null,
                    'valid_until'   => $codeData['valid_until'] ?? null,
                    'gate_location' => $codeData['gate_location'] ?? null,
                    'notes'         => $codeData['notes'] ?? null,
                ]);

                $created++;
            } catch (\Exception $e) {
                $errors[] = "Lỗi '{$codeData['code']}': " . $e->getMessage();
                $skipped++;
            }
        }

        return [
            'success' => true,
            'created' => $created,
            'skipped' => $skipped,
            'errors'  => $errors,
        ];
    }

    /**
     * Auto expire các mã hết hạn
     */
    public function expireOldCodes()
    {
        $expired = AccessCode::where('status', 'active')
            ->where('valid_until', '<', now())
            ->get();

        foreach ($expired as $code) {
            $code->markAsExpired();
        }

        return $expired->count();
    }

    /**
     * Kiểm tra mã sắp hết hạn
     */
    public function checkExpiringCodes($daysThreshold = 7)
    {
        return AccessCode::active()
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', now()->addDays($daysThreshold))
            ->where('valid_until', '>=', now())
            ->get();
    }
}