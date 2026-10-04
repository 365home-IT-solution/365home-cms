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
// Chọn phòng → MỖI PHÒNG 1 mã riêng cho từng ngày (bản ghi chỉ gắn phòng đó), để khách phòng này
// không mở được bằng mã của phòng khác; không chọn phòng → 1 mã chung cho cả chi nhánh như cũ.
//
// KHÔNG dựa vào global scope 'partner' (chỉ bật trong panel Filament, không bật ở /api/admin/*) —
// phạm vi chi nhánh lọc tay theo $user ở branches(), mọi thứ khác đều đi qua chi nhánh đã kiểm tra.
class ManualLockPasswordTtlockIssuer
{
    // Mỗi ngày = 1+ lần gọi TTLock (đồng bộ, ~1-2s/lần) — chặn khoảng quá dài kẻo request timeout.
    public const MAX_DAYS = 31;

    // Tổng số mã 1 lần (số ngày × số phòng) — cùng ngân sách thời gian với MAX_DAYS ở trên.
    public const MAX_CODES = 31;

    // TTLock làm tròn khung giờ mã theo GIỜ và trả CÙNG 1 mã cho cùng khóa + cùng khung giờ — các
    // phòng cùng ngày chỉ có mã khác nhau khi khung giờ trên khóa khác nhau ít nhất 1 tiếng. Mỗi phòng
    // được nới khung giờ thêm tối đa N tiếng (bắt đầu sớm hơn / kết thúc muộn hơn) → (N+1)² khung khác
    // nhau = tối đa 9 phòng/ngày với N = 2.
    public const MAX_SHIFT_HOURS = 2;

    // TTLock errcode "Passcode with this validity period has been generated before and deleted" — mã
    // tự sinh tính theo khóa + khung giờ, đã xoá thì KHÔNG xin lại được cho đúng khung giờ đó nữa.
    private const TTLOCK_PASSCODE_DELETED = -1026;

    /**
     * Số mã sẽ tạo (1 mã / ngày / phòng) — dùng cho kiểm tra MAX_CODES và dòng tóm tắt trên form.
     *
     * @param  array{from_date: string, to_date: string, per_day?: bool, product_ids?: array}  $data
     */
    public static function codeCount(array $data): int
    {
        $days = empty($data['per_day'])
            ? 1
            : Carbon::parse($data['from_date'])->startOfDay()->diffInDays(Carbon::parse($data['to_date'])->startOfDay()) + 1;

        return (int) $days * max(1, count($data['product_ids'] ?? []));
    }

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

        $branchProducts   = self::products($categoryId);
        $branchProductIds = array_map('strval', array_keys($branchProducts));
        $productIds       = array_values(array_intersect(array_map('strval', $data['product_ids'] ?? []), $branchProductIds));

        if (self::codeCount([...$data, 'product_ids' => $productIds]) > self::MAX_CODES) {
            return [...$result, 'message' => 'Tối đa ' . self::MAX_CODES . ' mã mỗi lần (số ngày × số phòng) — hãy chia nhỏ khoảng ngày hoặc số phòng.'];
        }

        // Mỗi phòng đã chọn 1 mã riêng; không chọn phòng = 1 mã chung cả chi nhánh (null).
        // Nhiều phòng thì tên kèm tên phòng để phân biệt (và để chống cấp trùng theo từng phòng).
        $baseName = trim((string) $data['name']);
        $targets  = $productIds
            ? array_map(fn (string $id) => [$id, count($productIds) > 1 ? "{$baseName} – {$branchProducts[$id]}" : $baseName], $productIds)
            : [[null, $baseName]];

        $jobs = [];

        foreach ($targets as [$productId, $targetName]) {
            foreach (self::periods([...$data, 'name' => $targetName]) as [$validFrom, $validUntil, $name]) {
                $jobs[] = [$productId, $validFrom, $validUntil, $name];
            }
        }

        foreach ($jobs as [$productId, $validFrom, $validUntil, $name]) {
            $label = $validFrom->format('d/m/Y') . ($productId && count($productIds) > 1 ? " – {$branchProducts[$productId]}" : '');

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
            $shiftNote   = '';
            // Mã đã cài lên khóa nào (keyboardPwdId) + khung giờ đã nới bao nhiêu — lưu vào
            // ttlock_passcodes để SỬA thời gian / XÓA đồng bộ được xuống khóa (xem syncPeriodToLocks()).
            $passcodes   = [];
            $shift       = [0, 0];

            // Khóa đầu tiên tự sinh mã, các khóa sau thêm ĐÚNG mã đó (giống TTLock IssuePasscode).
            foreach ($lockIds as $lockId) {
                if ($code !== null) {
                    $res = $ttlock->addCustomPasscode($lockId, $code, $startMs, $endMs, $name, 3);

                    if ($res) {
                        $passcodes[] = self::passcodeEntry($lockId, (int) $res['keyboardPwdId'], $shift);
                    } else {
                        $failedLocks[] = $lockId . ($ttlock->lastErrorMessage ? " ({$ttlock->lastErrorMessage})" : '');
                    }

                    continue;
                }

                // Mã trùng phòng khác (xem MAX_SHIFT_HOURS) → thử khung giờ nới thêm theo giờ.
                // TUYỆT ĐỐI không xoá mã trùng khỏi khóa — đó chính là mã của phòng kia.
                // Bắt đầu từ khung thứ <số phòng đã có mã ngày này> để đỡ gọi TTLock thừa.
                $shifts  = self::hourShifts();
                $first   = self::issuedCount($categoryId, $validFrom) % count($shifts);
                $lastDup = null;
                $deleted = false;

                foreach ([...array_slice($shifts, $first), ...array_slice($shifts, 0, $first)] as [$earlier, $later]) {
                    $tryStartMs = $startMs - $earlier * 3_600_000;
                    $tryEndMs   = $endMs + $later * 3_600_000;
                    $res        = $ttlock->generatePasscode($lockId, $tryStartMs, $tryEndMs, $name, 3);

                    if (! $res) {
                        // LỖI THẬT 2026-10-04 (production): mã của khung giờ này đã sinh rồi bị xoá →
                        // TTLock không cấp lại, phải đổi khung giờ (xem TTLOCK_PASSCODE_DELETED).
                        if ($ttlock->lastErrorCode === self::TTLOCK_PASSCODE_DELETED) {
                            $deleted = true;

                            continue;
                        }

                        break;
                    }

                    if (self::codeInUse($categoryId, (string) $res['code'], $validFrom, $validUntil)) {
                        $lastDup = (string) $res['code'];

                        continue;
                    }

                    // Các khóa sau cấp cùng khung giờ với khóa đầu.
                    $code        = (string) $res['code'];
                    $startMs     = $tryStartMs;
                    $endMs       = $tryEndMs;
                    $shift       = [$earlier, $later];
                    $passcodes[] = self::passcodeEntry($lockId, (int) $res['keyboardPwdId'], $shift);
                    $shiftNote = $earlier || $later
                        ? " — trên khóa: sớm {$earlier}h, muộn {$later}h để khác mã phòng khác"
                        : '';

                    break;
                }

                // Hết khung giờ nới mà vẫn dính mã đã xoá → tự sinh số ngẫu nhiên rồi thêm như mã tự
                // chọn, đúng khung giờ gốc (giống ContractTtlockService::issueRandom(); cần khóa online
                // qua gateway).
                if ($code === null && $deleted) {
                    // Thất bại thì báo lý do của addCustomPasscode (VD khóa offline), không phải "hết khung giờ".
                    $lastDup = null;

                    for ($attempt = 0; $attempt < 3; $attempt++) {
                        $candidate = (string) random_int(100000, 999999);

                        if (self::codeInUse($categoryId, $candidate, $validFrom, $validUntil)) {
                            continue;
                        }

                        $res = $ttlock->addCustomPasscode($lockId, $candidate, $startMs, $endMs, $name, 3);

                        if ($res) {
                            $code        = $candidate;
                            $passcodes[] = self::passcodeEntry($lockId, (int) $res['keyboardPwdId'], $shift);

                            break;
                        }
                    }
                }

                if ($code === null) {
                    $reason = $lastDup !== null
                        ? 'TTLock hết khung giờ để sinh mã khác các phòng đã có mã ngày này (tối đa ' . count($shifts) . ' phòng/ngày trên cùng khóa)'
                        : $ttlock->lastErrorMessage;

                    $failedLocks[] = $lockId . ($reason ? " ({$reason})" : '');
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
                'notes'         => 'Cấp tự động qua TTLock (khóa ' . implode(', ', $lockIds) . ')' . $shiftNote,
                'valid_from'    => $validFrom,
                'valid_until'   => $validUntil,
                'is_active'     => (bool) ($data['is_active'] ?? true),
                'ttlock_passcodes' => $passcodes,
            ]);

            if ($productId) {
                $record->products()->sync([$productId]);
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

    // =========================================================================================
    // Đồng bộ SỬA / XÓA bộ mật khẩu xuống khóa TTLock — dùng cho API sửa/xóa
    // (Api\Admin\ManualLockPasswordController). Mã TTLock nằm TRÊN KHÓA, sửa/xóa bản ghi mà không
    // sửa/xóa trên khóa thì mã cũ vẫn mở được cửa.
    // =========================================================================================

    /** @param array{0: int, 1: int} $shift */
    private static function passcodeEntry(int $lockId, int $keyboardPwdId, array $shift): array
    {
        return ['lock_id' => $lockId, 'keyboard_pwd_id' => $keyboardPwdId, 'earlier_h' => $shift[0], 'later_h' => $shift[1]];
    }

    // Bộ mật khẩu được cấp qua TTLock (có ghi khóa trong ttlock_passcodes, hoặc bản ghi cũ trước khi
    // có cột đó — nhận ra qua ghi chú "Cấp tự động qua TTLock (khóa ...)").
    public static function isTtlockBacked(ManualLockPassword $record): bool
    {
        return ! empty($record->ttlock_passcodes)
            || str_starts_with((string) $record->notes, 'Cấp tự động qua TTLock (khóa ');
    }

    /**
     * Danh sách mã trên khóa của bộ mật khẩu. Bản ghi cấp TRƯỚC khi có cột ttlock_passcodes thì tự
     * tìm lại trên khóa (ghi trong notes) theo đúng số mã + tên, rồi lưu luôn để lần sau khỏi tìm.
     *
     * @return list<array{lock_id: int, keyboard_pwd_id: int, earlier_h: int, later_h: int}>
     */
    public static function resolvePasscodes(ManualLockPassword $record, TTLockService $ttlock): array
    {
        if (! empty($record->ttlock_passcodes)) {
            return $record->ttlock_passcodes;
        }

        if (! preg_match('/^Cấp tự động qua TTLock \(khóa ([\d,\s]+)\)/u', (string) $record->notes, $m)) {
            return [];
        }

        $shift = preg_match('/sớm (\d+)h, muộn (\d+)h/u', (string) $record->notes, $s) ? [(int) $s[1], (int) $s[2]] : [0, 0];
        $code  = rtrim((string) $record->gate_password, '#');
        $found = [];

        foreach (array_filter(array_map('intval', explode(',', $m[1]))) as $lockId) {
            $match = collect($ttlock->listKeyboardPwds($lockId))
                ->first(fn (array $p) => (string) ($p['keyboardPwd'] ?? '') === $code
                    && ($p['keyboardPwdName'] ?? $record->name) === $record->name);

            if ($match) {
                $found[] = self::passcodeEntry($lockId, (int) $match['keyboardPwdId'], $shift);
            }
        }

        if ($found) {
            $record->forceFill(['ttlock_passcodes' => $found])->saveQuietly();
        }

        return $found;
    }

    /**
     * Đổi thời gian hiệu lực của mã trên MỌI khóa (giữ nguyên số mã). Khung giờ trên khóa = khung
     * mới + đúng độ nới lúc cấp (earlier_h/later_h) + đệm 30' của TTLockService::generatePasscode().
     *
     * @return array{ok: bool, errors: list<string>}
     */
    public static function syncPeriodToLocks(ManualLockPassword $record, Carbon $validFrom, Carbon $validUntil): array
    {
        $ttlock = TTLockService::forCategory($record->category_id);

        if (! $ttlock) {
            return ['ok' => false, 'errors' => ['Chi nhánh chưa có tài khoản TTLock hoạt động — không cập nhật được mã trên khóa.']];
        }

        $passcodes = self::resolvePasscodes($record, $ttlock);

        if (! $passcodes) {
            return ['ok' => false, 'errors' => ['Không tìm thấy mã này trên khóa TTLock (có thể đã bị xóa trong app TTLock).']];
        }

        $errors = [];

        foreach ($passcodes as $p) {
            $startMs = $validFrom->copy()->subHours($p['earlier_h'] ?? 0)->subMinutes(30)->getTimestampMs();
            $endMs   = $validUntil->copy()->addHours($p['later_h'] ?? 0)->addMinutes(30)->getTimestampMs();

            if (! $ttlock->modifyPasscode((int) $p['lock_id'], (int) $p['keyboard_pwd_id'], $startMs, $endMs, (string) $record->name)) {
                $errors[] = "Khóa {$p['lock_id']}: TTLock không cập nhật được thời gian mã.";
            }
        }

        return ['ok' => ! $errors, 'errors' => $errors];
    }

    /**
     * Xóa mã khỏi MỌI khóa. Không tìm thấy mã trên khóa nữa (đã xóa trong app TTLock) thì coi như
     * đã xóa xong.
     *
     * @return array{ok: bool, errors: list<string>}
     */
    public static function deleteFromLocks(ManualLockPassword $record): array
    {
        $ttlock = TTLockService::forCategory($record->category_id);

        if (! $ttlock) {
            return ['ok' => false, 'errors' => ['Chi nhánh chưa có tài khoản TTLock hoạt động — không xóa được mã trên khóa.']];
        }

        $errors = [];

        foreach (self::resolvePasscodes($record, $ttlock) as $p) {
            if (! $ttlock->deletePasscode((int) $p['lock_id'], (int) $p['keyboard_pwd_id'])) {
                $errors[] = "Khóa {$p['lock_id']}: TTLock không xóa được mã.";
            }
        }

        return ['ok' => ! $errors, 'errors' => $errors];
    }

    /**
     * Các cách nới khung giờ [sớm hơn, muộn hơn] (giờ), ít nới nhất trước: [0,0], [0,1], [1,0], [1,1], [0,2]...
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function hourShifts(): array
    {
        $shifts = [];

        foreach (range(0, self::MAX_SHIFT_HOURS) as $earlier) {
            foreach (range(0, self::MAX_SHIFT_HOURS) as $later) {
                $shifts[] = [$earlier, $later];
            }
        }

        usort($shifts, fn ($a, $b) => [max($a), array_sum($a)] <=> [max($b), array_sum($b)]);

        return $shifts;
    }

    // Số bộ mật khẩu chi nhánh đã có cho đúng ngày bắt đầu này (≈ số phòng đã cấp mã ngày đó).
    private static function issuedCount(int $categoryId, Carbon $validFrom): int
    {
        return ManualLockPassword::query()
            ->where('category_id', $categoryId)
            ->where('valid_from', $validFrom)
            ->count();
    }

    // Mã đã được dùng cho bộ mật khẩu khác của chi nhánh có khoảng hiệu lực chồng lên khoảng này.
    private static function codeInUse(int $categoryId, string $code, Carbon $validFrom, Carbon $validUntil): bool
    {
        return ManualLockPassword::query()
            ->where('category_id', $categoryId)
            ->where('gate_password', "{$code}#")
            ->where('valid_from', '<', $validUntil)
            ->where('valid_until', '>', $validFrom)
            ->exists();
    }
}
