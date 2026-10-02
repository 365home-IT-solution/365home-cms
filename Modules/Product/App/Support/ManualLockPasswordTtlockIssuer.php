<?php

declare(strict_types=1);

namespace Modules\Product\App\Support;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Category\Entities\Categorizable;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\ManualLockPassword;
use Modules\Product\App\Models\Product;
use Modules\TTLock\App\Services\TTLockService;

// "Cấp mã mở hàng loạt" — dùng chung cho nút trong trang Khóa cổng
// (ManualLockPasswordTtlockBulkAction) và API app (Api\Admin\ManualLockPasswordController).
// Giống Import Excel (mỗi ngày 1 bản ghi "<tên> – dd/mm/YYYY", hiệu lực từ giờ X ngày đó tới giờ Y
// hôm sau) nhưng Pass Cổng do TTLock TỰ SINH và cấp thẳng lên khóa (keyboardPwdType 3 — theo thời
// gian). Đối tác không chọn riêng: ManualLockPassword không có partner_id, luôn suy ra từ chi nhánh.
//
// KHÔNG dựa vào global scope 'partner' (chỉ bật trong panel Filament, không bật ở /api/admin/*) —
// phạm vi chi nhánh lọc tay theo $user ở branches(), mọi thứ khác đều đi qua chi nhánh đã kiểm tra.
class ManualLockPasswordTtlockIssuer
{
    // Mỗi ngày = 1+ lần gọi TTLock (đồng bộ, ~1-2s/lần) — chặn khoảng quá dài kẻo request timeout.
    public const MAX_DAYS = 31;

    /**
     * Chi nhánh user được cấp mã: cùng phạm vi với form "Thêm mới"/Import Excel, và phải có tài
     * khoản TTLock đang hoạt động.
     *
     * @return array<int, string>
     */
    public static function branches(User $user): array
    {
        return collect(self::visibleBranches($user))
            ->filter(fn ($name, $id) => TTLockService::hasAccountForCategory((int) $id))
            ->all();
    }

    /**
     * Mọi chi nhánh user được thấy (không cần TTLock) — dùng cho form/bộ lọc của bảng Khóa thủ công.
     *
     * @return array<int, string>
     */
    public static function visibleBranches(?User $user): array
    {
        $query = Category::query()->where('category_type', 'product')->orderBy('name');

        if ($user && ! $user->isSuperAdmin()) {
            $allowedIds = $user->allowedCategoryIds();

            if (! empty($allowedIds)) {
                $query->whereIn('id', $allowedIds);
            } else {
                $query->where('partner_id', $user->partner_id);
            }
        }

        return $query->pluck('name', 'id')->all();
    }

    /** @return array<int, string> lockId => tên khóa */
    public static function locks(int $categoryId): array
    {
        // Form Filament tính lại options mỗi lần re-render — cache ngắn để không gọi TTLock
        // /v3/lock/list liên tục.
        return Cache::remember("manual_lock_ttlock_locks_{$categoryId}", now()->addMinutes(5), function () use ($categoryId) {
            $ttlock = TTLockService::forCategory($categoryId);

            return collect($ttlock?->getLockList() ?? [])
                ->mapWithKeys(fn (array $lock) => [
                    (int) $lock['lockId'] => $lock['lockAlias'] ?? $lock['lockName'] ?? "Lock #{$lock['lockId']}",
                ])
                ->all();
        });
    }

    /** @return array<string, string> productId => tên phòng */
    public static function products(int $categoryId): array
    {
        // Phòng có thể gắn vào chi nhánh hoặc khu vực con của nó (VD chi nhánh 169 → 171..174).
        $categoryIds = [$categoryId];
        $level       = [$categoryId];

        while ($level = Category::whereIn('parent_id', $level)->pluck('id')->diff($categoryIds)->all()) {
            $categoryIds = [...$categoryIds, ...$level];
        }

        $productIds = Categorizable::where('categorizable_type', Product::class)
            ->whereIn('category_id', $categoryIds)
            ->pluck('categorizable_id');

        return Product::query()
            ->whereIn('id', $productIds)
            ->where('is_activated', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @param  array{from_date: string, to_date: string, from_time: string, until_time: string, name: string, per_day?: bool}  $data
     * @return list<array{0: Carbon, 1: Carbon, 2: string}> [valid_from, valid_until, name]
     */
    public static function periods(array $data): array
    {
        $from = Carbon::parse($data['from_date'])->startOfDay();
        $to   = Carbon::parse($data['to_date'])->startOfDay();
        [$fh, $fm] = array_map('intval', explode(':', (string) $data['from_time']));
        [$uh, $um] = array_map('intval', explode(':', (string) $data['until_time']));
        $name = trim((string) $data['name']);

        if (empty($data['per_day'])) {
            return [[$from->copy()->setTime($fh, $fm), $to->copy()->setTime($uh, $um), $name]];
        }

        $periods = [];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $periods[] = [
                $day->copy()->setTime($fh, $fm),
                $day->copy()->addDay()->setTime($uh, $um),
                $name . ' – ' . $day->format('d/m/Y'),
            ];
        }

        return $periods;
    }

    /**
     * Cấp mã. $data: category_id, lock_ids[], product_ids[]?, name, from_date, to_date, from_time,
     * until_time, per_day, room_same_as_gate, is_active.
     *
     * @return array{ok: bool, message: ?string, created: list<ManualLockPassword>, skipped: int, errors: list<string>, warnings: list<string>}
     */
    public static function issue(User $user, array $data): array
    {
        $result = ['ok' => false, 'message' => null, 'created' => [], 'skipped' => 0, 'errors' => [], 'warnings' => []];

        $categoryId = (int) $data['category_id'];
        $ttlock     = array_key_exists($categoryId, self::branches($user)) ? TTLockService::forCategory($categoryId) : null;

        if (! $ttlock) {
            return [...$result, 'message' => 'Chi nhánh không hợp lệ hoặc chưa có tài khoản TTLock hoạt động.'];
        }

        // Chỉ nhận khóa / phòng thật sự thuộc chi nhánh này (không tin dữ liệu client gửi lên).
        $lockIds = array_values(array_intersect(
            array_map('intval', $data['lock_ids'] ?? []),
            array_keys(self::locks($categoryId)),
        ));

        if (! $lockIds) {
            return [...$result, 'message' => 'Không có khóa hợp lệ nào thuộc chi nhánh này.'];
        }

        $branchProductIds = array_map('strval', array_keys(self::products($categoryId)));
        $productIds       = array_values(array_intersect(array_map('strval', $data['product_ids'] ?? []), $branchProductIds));

        foreach (self::periods($data) as [$validFrom, $validUntil, $name]) {
            $label = $validFrom->format('d/m/Y');

            // Bấm/gọi 2 lần cùng khoảng → không cấp trùng mã lên khóa.
            $exists = ManualLockPassword::query()
                ->where('category_id', $categoryId)
                ->where('name', $name)
                ->where('valid_from', $validFrom)
                ->exists();

            if ($exists) {
                $result['skipped']++;
                continue;
            }

            $startMs     = $validFrom->getTimestampMs();
            $endMs       = $validUntil->getTimestampMs();
            $code        = null;
            $failedLocks = [];

            // Khóa đầu tiên tự sinh mã, các khóa sau thêm ĐÚNG mã đó (giống TTLock IssuePasscode).
            foreach ($lockIds as $lockId) {
                $res = $code === null
                    ? $ttlock->generatePasscode($lockId, $startMs, $endMs, $name, 3)
                    : $ttlock->addCustomPasscode($lockId, $code, $startMs, $endMs, $name, 3);

                if ($res && $code === null) {
                    $code = (string) $res['code'];
                } elseif (! $res) {
                    $failedLocks[] = $lockId . ($ttlock->lastErrorMessage ? " ({$ttlock->lastErrorMessage})" : '');
                }
            }

            if ($code === null) {
                $result['errors'][] = "{$label}: không sinh được mã — " . implode('; ', $failedLocks);
                continue;
            }

            if ($failedLocks) {
                $result['warnings'][] = "{$label} (mã {$code}#): lỗi khóa " . implode('; ', $failedLocks);
            }

            // Lưu kèm "#" — khóa TTLock nhập mã xong phải bấm # (giống các bộ mật khẩu đang có).
            $record = ManualLockPassword::create([
                'name'          => $name,
                'gate_password' => "{$code}#",
                'room_password' => ! empty($data['room_same_as_gate']) ? "{$code}#" : null,
                'category_id'   => $categoryId,
                'notes'         => 'Cấp tự động qua TTLock (khóa ' . implode(', ', $lockIds) . ')',
                'valid_from'    => $validFrom,
                'valid_until'   => $validUntil,
                'is_active'     => (bool) ($data['is_active'] ?? true),
            ]);

            if ($productIds) {
                $record->products()->sync($productIds);
            }

            $result['created'][] = $record;
        }

        // Bật has_manual_lock để đơn của phòng tự lấy mật khẩu này. Không chọn phòng (áp dụng cả chi
        // nhánh) thì bật cho các phòng của chi nhánh chưa gắn khóa TTLock riêng (lock_id) — phòng có
        // lock_id vẫn đi luồng mã TTLock theo đơn như cũ.
        if ($result['created']) {
            ManualLockPassword::markProductsAsManualLock($productIds ?: Product::query()
                ->whereIn('id', $branchProductIds)
                ->whereNull('lock_id')
                ->pluck('id'));
        }

        Log::info('ManualLockPasswordTtlockIssuer', [
            'category_id' => $categoryId,
            'lock_ids'    => $lockIds,
            'created'     => count($result['created']),
            'skipped'     => $result['skipped'],
            'errors'      => $result['errors'],
            'warnings'    => $result['warnings'],
            'admin'       => $user->email,
        ]);

        $result['ok'] = $result['created'] || $result['skipped'];

        return $result;
    }
}
