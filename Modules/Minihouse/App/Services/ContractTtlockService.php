<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ContractTtlockPasscode;
use Modules\Minihouse\App\Models\Room;
use Modules\TTLock\App\Services\TTLockService;

// Tự cấp/sửa/xoá MÃ MỞ TTLock (passcode) theo ĐÚNG vòng đời Hợp đồng — trả lời câu hỏi "khoá TTLock
// tồn tại vĩnh viễn hay theo ngày thuê": trước đây KHÔNG có liên kết nào cả, "Gán khoá" chỉ gán CỨNG
// 1 ổ khoá vật lý cho phòng (vĩnh viễn tới khi tự đổi tay — đúng, không đổi), còn MÃ MỞ THẬT SỰ
// (Cấp mã mở) phải tự tay tạo, mặc định "Vĩnh viễn" nên khách cũ có thể vẫn mở được cửa sau khi đã
// trả phòng nếu nhân viên quên xoá tay. Service này tự lo phần đó:
//   - Hợp đồng có end_date -> mã mở CÓ HẠN đúng bằng [start_date, end_date] của hợp đồng.
//   - Hợp đồng CHƯA có end_date (thuê tiếp, chưa biết ngày đi) -> mã "Vĩnh viễn" (không có gì để giới
//     hạn), nhưng LUÔN bị XOÁ NGAY khi hợp đồng kết thúc/thanh lý/huỷ (không đợi tới ngày nào cả).
//   - Đổi/gỡ ổ khoá của phòng, gia hạn/rút ngắn hợp đồng: mã tự cấp lại/sửa hạn/xoá theo đúng hiện
//     trạng, không cần nhân viên tự vào "Cấp mã mở"/"Xoá mã mở" tay nữa.
// Gọi từ ContractObserver (created/updated/deleted) và RoomLockActions/RoomLockController (đổi khoá
// của phòng). Toàn bộ lỗi gọi TTLock (mất mạng, token hết hạn...) CHỈ ghi log, KHÔNG ném exception —
// các hàm TTLockService::* bản thân cũng tự try/catch trả về null/false, không làm hỏng luồng lưu
// Hợp đồng nếu TTLock đang lỗi.
class ContractTtlockService
{
    // Gọi lại mỗi khi Hợp đồng tạo/sửa (đổi status/room_id/start_date/end_date) — tự quyết định
    // cấp mới/sửa hạn/xoá cho khớp hiện trạng, không làm gì nếu không có gì đổi.
    public static function syncForContract(Contract $contract): void
    {
        $room = $contract->room_id ? Room::withoutGlobalScope('activeBuilding')->find($contract->room_id) : null;

        $shouldHaveCode = $contract->status === Contract::STATUS_ACTIVE && $room && $room->lock_id;

        if (! $shouldHaveCode) {
            self::purgeForContract($contract);

            return;
        }

        $ttlock = TTLockService::forBuilding((int) $room->building_id);

        if (! $ttlock) {
            return; // Toà nhà chưa cấu hình TTLock — không có gì để đồng bộ.
        }

        $targetLockIds = self::targetLockIdsFor($room);
        $existing      = ContractTtlockPasscode::where('contract_id', $contract->id)->get()->keyBy('lock_id');

        // Ổ khoá không còn được gán cho phòng nữa (đổi/gỡ khoá) -> xoá mã cũ trên đúng ổ đó.
        foreach ($existing as $lockId => $row) {
            if (! in_array((int) $lockId, $targetLockIds, true)) {
                self::revokeRow($ttlock, $row);
            }
        }

        $startDate = self::resolveStartDate($contract);
        $endDate   = self::resolveEndDate($contract);
        $startMs   = $startDate->getTimestampMs();
        $endMs     = $endDate?->getTimestampMs() ?? 0;
        $name      = 'HD-' . $contract->id . ($room->code ? " {$room->code}" : '');

        // Dùng CHUNG 1 mã cho mọi ổ của cùng hợp đồng (khách chỉ cần nhớ 1 số) — lấy mã đã cấp từ
        // trước nếu có (VD hợp đồng đã có mã ở ổ ngoài, giờ mới gán thêm ổ trong).
        $code = $existing->first()?->code;

        foreach ($targetLockIds as $lockId) {
            $row = $existing->get($lockId);

            if ($row) {
                self::resyncDatesIfChanged($ttlock, $row, $startDate, $endDate, $startMs, $endMs, $name);

                continue;
            }

            $code === null
                ? self::issueFirst($ttlock, $contract, $room, $lockId, $startMs, $endMs, $name, $code)
                : self::issueAdditional($ttlock, $contract, $room, $lockId, $code, $startMs, $endMs, $name);
        }
    }

    // Đổi/gỡ ổ khoá của 1 Phòng (RoomLockActions/RoomLockController) — nếu phòng đang có Hợp đồng
    // hiệu lực, cấp/thu hồi mã theo đúng cấu hình khoá MỚI ngay, không đợi ai sửa lại Hợp đồng.
    public static function syncForRoom(?string $roomId): void
    {
        if (! $roomId) {
            return;
        }

        $contract = Contract::query()->where('room_id', $roomId)->where('status', Contract::STATUS_ACTIVE)->first();

        if ($contract) {
            self::syncForContract($contract);
        }
    }

    // Còn đổi mã được không — dùng CHUNG cho cả nút "Đổi mã mở" của nhân viên (EditContract) LẪN
    // khách thuê tự đổi ở Portal/API: hợp đồng phải ĐANG HIỆU LỰC VÀ CHƯA QUA ngày kết thúc (chưa có
    // end_date thì luôn coi là còn hạn), và phòng phải đã gán ít nhất 1 ổ khoá TTLock.
    public static function canChangeCode(Contract $contract): bool
    {
        if ($contract->status !== Contract::STATUS_ACTIVE) {
            return false;
        }

        if ($contract->end_date && now()->gt($contract->end_date->copy()->setTime(23, 59, 59))) {
            return false;
        }

        $room = $contract->room_id ? Room::withoutGlobalScope('activeBuilding')->find($contract->room_id) : null;

        return (bool) ($room && $room->lock_id);
    }

    // Mã đang dùng của hợp đồng (mọi ổ khoá của cùng hợp đồng luôn chung 1 mã — xem syncForContract()),
    // null nếu chưa từng cấp mã.
    public static function currentCode(Contract $contract): ?string
    {
        return ContractTtlockPasscode::where('contract_id', $contract->id)->value('code');
    }

    // Đổi hẳn sang 1 mã MỚI — cho nút "Đổi mã mở" (nhân viên) và khách thuê tự đổi ở Portal/API. Xoá
    // hết mã cũ trên TTLock (mọi ổ khoá của hợp đồng) rồi cấp lại, giữ nguyên hạn dùng hiện tại.
    //
    // $customCode: để trống = TTLock tự sinh ngẫu nhiên (như trước); truyền vào (4-9 chữ số) = dùng
    // ĐÚNG số khách/nhân viên tự chọn — LỖI THẬT đã gặp: khách muốn tự đặt 1 mã dễ nhớ, không phải
    // mã ngẫu nhiên hệ thống đưa ra.
    //
    // @return array{success: bool, message: string, code?: string}
    public static function regenerateCode(Contract $contract, ?string $customCode = null): array
    {
        if ($customCode !== null && $customCode !== '' && ! preg_match('/^\d{4,9}$/', $customCode)) {
            return ['success' => false, 'message' => 'Mã tự chọn phải là 4-9 chữ số.'];
        }

        if (! self::canChangeCode($contract)) {
            return ['success' => false, 'message' => 'Hợp đồng đã hết hạn/không còn hiệu lực, hoặc phòng chưa gán khoá TTLock — không thể đổi mã.'];
        }

        $room   = Room::withoutGlobalScope('activeBuilding')->find($contract->room_id);
        $ttlock = TTLockService::forBuilding((int) $room->building_id);

        if (! $ttlock) {
            return ['success' => false, 'message' => 'Toà nhà chưa cấu hình tài khoản TTLock.'];
        }

        // Mã tự chọn đã có xe... à không, đã có XE thì thôi, đã có KHOÁ khác đang dùng trùng số trong
        // cùng toà thì TTLock từ chối (mỗi khoá không tự kiểm tra trùng lẫn nhau) — soát trước cho gọn,
        // đỡ để khách bấm xong mới biết trùng.
        if (filled($customCode) && ContractTtlockPasscode::where('building_id', $room->building_id)->where('code', $customCode)->where('contract_id', '!=', $contract->id)->exists()) {
            return ['success' => false, 'message' => 'Mã này đang được dùng cho phòng khác trong cùng toà nhà, chọn mã khác.'];
        }

        foreach (ContractTtlockPasscode::where('contract_id', $contract->id)->get() as $row) {
            self::revokeRow($ttlock, $row);
        }

        $targetLockIds = self::targetLockIdsFor($room);
        $startDate     = self::resolveStartDate($contract);
        $endDate       = self::resolveEndDate($contract);
        $startMs       = $startDate->getTimestampMs();
        $endMs         = $endDate?->getTimestampMs() ?? 0;
        $name          = 'HD-' . $contract->id . ($room->code ? " {$room->code}" : '');

        $code = $customCode ?: null;

        foreach ($targetLockIds as $lockId) {
            $code === null
                ? self::issueFirst($ttlock, $contract, $room, $lockId, $startMs, $endMs, $name, $code)
                : self::issueAdditional($ttlock, $contract, $room, $lockId, $code, $startMs, $endMs, $name);
        }

        $new = ContractTtlockPasscode::where('contract_id', $contract->id)->first();

        if (! $new) {
            // $ttlock->lastErrorMessage giữ đúng errmsg gốc TTLock trả về (VD "Passcode is too
            // simple. Please avoid consecutive or repeated digits." khi khách tự chọn mã kiểu
            // "123456"/"111111") — LỖI THẬT gặp 2026-09-28: hiển thị "kiểm tra kết nối mạng" cho
            // trường hợp này khiến khách/nhân viên không hiểu vì sao thất bại, dù mạng hoàn toàn
            // bình thường.
            $reason = $ttlock->lastErrorMessage
                ? self::translateTtlockError($ttlock->lastErrorMessage)
                : 'kiểm tra khoá còn kết nối mạng không rồi thử lại';

            return ['success' => false, 'message' => "Đổi mã thất bại — {$reason}."];
        }

        return ['success' => true, 'message' => 'Đã đổi mã mở mới.', 'code' => $new->code];
    }

    // Hợp đồng bị XOÁ HẲN (forceDelete/xoá mềm) — thu hồi toàn bộ mã, không cần tính lại hiện trạng.
    public static function purgeForContract(Contract $contract): void
    {
        $rows = ContractTtlockPasscode::where('contract_id', $contract->id)->get();

        if ($rows->isEmpty()) {
            return;
        }

        // building_id lấy từ chính dòng đã lưu — không phụ thuộc phòng/toà hiện tại của hợp đồng
        // (có thể đã đổi khác từ lúc cấp mã).
        $byBuilding = $rows->groupBy('building_id');

        foreach ($byBuilding as $buildingId => $group) {
            $ttlock = TTLockService::forBuilding((int) $buildingId);

            foreach ($group as $row) {
                self::revokeRow($ttlock, $row);
            }
        }
    }

    // Dịch nguyên văn errmsg tiếng Anh của TTLock sang tiếng Việt cho những lỗi THƯỜNG GẶP khi khách/
    // nhân viên tự chọn mã (giữ nguyên bản gốc nếu không nhận diện được, còn hơn nuốt mất thông tin).
    private static function translateTtlockError(string $errmsg): string
    {
        return match (true) {
            str_contains($errmsg, 'too simple') => 'mã này quá đơn giản (không dùng số liên tiếp như 123456 hoặc số lặp như 111111), chọn mã khác',
            default => $errmsg,
        };
    }

    // Danh sách ổ khoá (khoá ngoài + khoá trong nếu có, loại trùng) hiện đang gán cho 1 Phòng.
    private static function targetLockIdsFor(Room $room): array
    {
        return array_values(array_unique(array_filter([
            (int) $room->lock_id,
            $room->lock_id_checkout ? (int) $room->lock_id_checkout : null,
        ])));
    }

    // Ngày bắt đầu hiệu lực của mã — TTLock CHẶN thẳng startDate ở quá khứ cho passcode loại thường
    // (errcode -3 "startDate is invalid... can't be earlier than today"). LỖI THẬT đã gặp: hợp đồng
    // bắt đầu thuê từ vài ngày/tuần trước (chuyện bình thường — phòng đã ở rồi mới gán khoá/đổi mã),
    // dùng thẳng contract->start_date làm startDate bị TTLock từ chối thẳng. Không có gì mất mát khi
    // dùng "hôm nay" thay cho ngày quá khứ — mã chỉ cần hiệu lực TỪ LÚC CẤP trở đi, không cần lùi về
    // đúng ngày dọn vào đã qua.
    private static function resolveStartDate(Contract $contract): Carbon
    {
        $start = ($contract->start_date ?? now())->copy()->startOfDay();
        $today = now()->startOfDay();

        return $start->lt($today) ? $today : $start;
    }

    // setTime(23,59,59) chứ KHÔNG dùng ->endOfDay() (23:59:59.999999) — getTimestampMs() có thể LÀM
    // TRÒN phần mili-giây .999999 LÊN, "nhảy" sang 00:00:00 của NGÀY HÔM SAU (lệch 1 ngày khi lưu lại
    // end_date để hiển thị/so sánh) — lấy nguyên giây, không có phần lẻ để tránh làm tròn sai.
    private static function resolveEndDate(Contract $contract): ?Carbon
    {
        return $contract->end_date?->copy()->setTime(23, 59, 59);
    }

    private static function issueFirst(TTLockService $ttlock, Contract $contract, Room $room, int $lockId, int $startMs, int $endMs, string $name, ?string &$code): void
    {
        $result = $ttlock->generatePasscode(lockId: $lockId, startDate: $startMs, endDate: $endMs, name: $name);

        if (! $result) {
            Log::error('MiniHouse ContractTtlockService: generatePasscode thất bại', ['contract_id' => $contract->id, 'lock_id' => $lockId]);

            return;
        }

        $code = $result['code'];

        ContractTtlockPasscode::create([
            'contract_id' => $contract->id, 'building_id' => $room->building_id, 'lock_id' => $lockId,
            'keyboard_pwd_id' => $result['keyboardPwdId'], 'code' => $code,
            'start_date' => date('Y-m-d H:i:s', intdiv($startMs, 1000)), 'end_date' => $endMs > 0 ? date('Y-m-d H:i:s', intdiv($endMs, 1000)) : null,
        ]);
    }

    private static function issueAdditional(TTLockService $ttlock, Contract $contract, Room $room, int $lockId, string $code, int $startMs, int $endMs, string $name): void
    {
        $result = $ttlock->addCustomPasscode(lockId: $lockId, code: $code, startDate: $startMs, endDate: $endMs, name: $name);

        if (! $result) {
            Log::error('MiniHouse ContractTtlockService: addCustomPasscode thất bại', ['contract_id' => $contract->id, 'lock_id' => $lockId]);

            return;
        }

        ContractTtlockPasscode::create([
            'contract_id' => $contract->id, 'building_id' => $room->building_id, 'lock_id' => $lockId,
            'keyboard_pwd_id' => $result['keyboardPwdId'], 'code' => $code,
            'start_date' => date('Y-m-d H:i:s', intdiv($startMs, 1000)), 'end_date' => $endMs > 0 ? date('Y-m-d H:i:s', intdiv($endMs, 1000)) : null,
        ]);
    }

    // Ngày hợp đồng đổi (gia hạn/rút ngắn/vừa thêm end_date) mà mã đã cấp từ trước -> SỬA HẠN mã cũ
    // (giữ nguyên mã số, khách không cần nhớ lại), không xoá-cấp-lại.
    private static function resyncDatesIfChanged(TTLockService $ttlock, ContractTtlockPasscode $row, Carbon $startDate, ?Carbon $endDate, int $startMs, int $endMs, string $name): void
    {
        $sameStart = $row->start_date?->equalTo($startDate);
        $sameEnd   = $endDate ? $row->end_date?->equalTo($endDate) : $row->end_date === null;

        if ($sameStart && $sameEnd) {
            return;
        }

        $ok = $ttlock->modifyPasscode(lockId: (int) $row->lock_id, keyboardPwdId: (int) $row->keyboard_pwd_id, startDate: $startMs, endDate: $endMs, name: $name);

        if (! $ok) {
            Log::error('MiniHouse ContractTtlockService: modifyPasscode thất bại', ['contract_id' => $row->contract_id, 'lock_id' => $row->lock_id]);

            return;
        }

        $row->update([
            'start_date' => date('Y-m-d H:i:s', intdiv($startMs, 1000)),
            'end_date'   => $endMs > 0 ? date('Y-m-d H:i:s', intdiv($endMs, 1000)) : null,
        ]);
    }

    private static function revokeRow(?TTLockService $ttlock, ContractTtlockPasscode $row): void
    {
        if ($ttlock && $row->keyboard_pwd_id) {
            $ok = $ttlock->deletePasscode((int) $row->lock_id, (int) $row->keyboard_pwd_id);

            if (! $ok) {
                Log::error('MiniHouse ContractTtlockService: deletePasscode thất bại — xoá dòng theo dõi, nhưng có thể mã còn sót trên khoá thật, kiểm tra tay ở trang Chi tiết khoá.', [
                    'contract_id' => $row->contract_id, 'lock_id' => $row->lock_id, 'keyboard_pwd_id' => $row->keyboard_pwd_id,
                ]);
            }
        }

        $row->delete();
    }
}
