<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ContractTtlockPasscode;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\TtlockSetting;
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
//
// KHOÁ CỔNG của toà nhà (TtlockSetting::gateLockIds(), cấu hình ở "Cấu hình TTLock"): mọi hợp đồng
// hiệu lực của toà đều được cấp mã trên các ổ cổng, cùng hạn với mã phòng. Chủ trọ chọn mã cổng TRÙNG
// mã phòng (khách nhớ 1 số) hoặc là 1 số RIÊNG (gate_code_mode). Chủ trọ cũng chọn THỜI ĐIỂM cấp
// (issue_mode): ngay khi tạo hợp đồng, hoặc chỉ khi đã thu cọc / đã thanh toán hoá đơn đầu tiên.
// Gọi từ ContractObserver (created/updated/deleted), RoomLockActions/RoomLockController (đổi khoá
// của phòng), InvoicePaymentObserver (hoá đơn vừa thanh toán) và trang cấu hình (đổi khoá cổng). Toàn bộ lỗi gọi TTLock (mất mạng, token hết hạn...) CHỈ ghi log, KHÔNG ném exception —
// các hàm TTLockService::* bản thân cũng tự try/catch trả về null/false, không làm hỏng luồng lưu
// Hợp đồng nếu TTLock đang lỗi.
class ContractTtlockService
{
    // Gọi lại mỗi khi Hợp đồng tạo/sửa (đổi status/room_id/start_date/end_date) — tự quyết định
    // cấp mới/sửa hạn/xoá cho khớp hiện trạng, không làm gì nếu không có gì đổi.
    public static function syncForContract(Contract $contract): void
    {
        $room    = $contract->room_id ? Room::withoutGlobalScope('activeBuilding')->find($contract->room_id) : null;
        $setting = TtlockSetting::forBuilding($room ? (int) $room->building_id : null);
        $targets = $room ? self::targetLocksFor($room, $setting) : [];

        if ($contract->status !== Contract::STATUS_ACTIVE || ! $targets) {
            self::purgeForContract($contract);

            return;
        }

        $ttlock = TTLockService::forBuilding((int) $room->building_id);

        if (! $ttlock) {
            return; // Toà nhà chưa cấu hình TTLock — không có gì để đồng bộ.
        }

        $existing = ContractTtlockPasscode::where('contract_id', $contract->id)->get()->keyBy('lock_id');

        // Toà nhà chọn "cấp mã sau khi thu tiền" mà hợp đồng chưa thu cọc/chưa thanh toán hoá đơn nào
        // -> CHƯA cấp. Hợp đồng đã có mã từ trước thì giữ nguyên (chủ trọ đổi cấu hình giữa chừng không
        // được làm khách đang ở mất mã).
        if ($existing->isEmpty() && ! self::paymentSatisfied($contract, $setting)) {
            return;
        }

        // Ổ khoá không còn được gán cho phòng/không còn là khoá cổng nữa -> xoá mã cũ trên đúng ổ đó.
        foreach ($existing->all() as $lockId => $row) {
            if (! array_key_exists((int) $lockId, $targets)) {
                self::revokeRow($ttlock, $row);
                $existing->forget($lockId);
            }
        }

        $separate = $setting->usesSeparateGateCode();

        self::issueMissing($ttlock, $contract, $room, $targets, $existing, self::codesFrom($existing, $separate), $separate);
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

    // Đổi danh sách KHOÁ CỔNG của Toà nhà (ManageTtlockSettings/TtlockSettingsController) — cấp mã trên
    // ổ cổng mới / thu hồi mã trên ổ vừa gỡ cho MỌI hợp đồng đang hiệu lực của toà.
    public static function syncForBuilding(int $buildingId): void
    {
        $roomIds = Room::withoutGlobalScope('activeBuilding')->where('building_id', $buildingId)->pluck('id');

        Contract::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('room_id', $roomIds)
            ->where('status', Contract::STATUS_ACTIVE)
            ->get()
            ->each(fn (Contract $contract) => self::syncForContract($contract));
    }

    // Còn đổi mã được không — dùng CHUNG cho cả nút "Đổi mã mở" của nhân viên (EditContract) LẪN
    // khách thuê tự đổi ở Portal/API: hợp đồng phải ĐANG HIỆU LỰC VÀ CHƯA QUA ngày kết thúc (chưa có
    // end_date thì luôn coi là còn hạn), phòng phải đã gán ít nhất 1 ổ khoá TTLock (hoặc toà có khoá
    // cổng), và hợp đồng CHƯA có mã thì phải đủ điều kiện thu tiền của toà (paymentSatisfied()) — nếu
    // không khách tự bấm "Cấp mã cổng" ở Portal là lách được điều kiện đó.
    public static function canChangeCode(Contract $contract): bool
    {
        if ($contract->status !== Contract::STATUS_ACTIVE) {
            return false;
        }

        if ($contract->end_date && now()->gt($contract->end_date->copy()->setTime(23, 59, 59))) {
            return false;
        }

        $room = $contract->room_id ? Room::withoutGlobalScope('activeBuilding')->find($contract->room_id) : null;

        if (! $room) {
            return false;
        }

        $setting = TtlockSetting::forBuilding((int) $room->building_id);

        if (! self::targetLocksFor($room, $setting)) {
            return false;
        }

        return ContractTtlockPasscode::where('contract_id', $contract->id)->exists() || self::paymentSatisfied($contract, $setting);
    }

    // Mã PHÒNG đang dùng của hợp đồng, null nếu chưa từng cấp mã. Toà chỉ có khoá cổng (phòng không
    // gắn khoá) thì trả về mã cổng.
    public static function currentCode(Contract $contract): ?string
    {
        $codes = self::currentCodes($contract);

        return $codes['room'] ?? $codes['gate'];
    }

    // Mã phòng + mã cổng đang dùng. Toà chọn "mã cổng trùng mã phòng" thì 2 số giống nhau.
    //
    // @return array{room: ?string, gate: ?string}
    public static function currentCodes(Contract $contract): array
    {
        $rows = ContractTtlockPasscode::where('contract_id', $contract->id)->get();

        return [
            'room' => $rows->firstWhere('is_gate', false)?->code,
            'gate' => $rows->firstWhere('is_gate', true)?->code,
        ];
    }

    // Hợp đồng này có mã cổng RIÊNG (khác mã phòng) để đổi độc lập không — toà chọn "mã cổng riêng",
    // có khoá cổng, và phòng có khoá riêng (không thì chỉ có đúng 1 loại mã, không có gì để tách).
    public static function hasSeparateGateCode(Contract $contract): bool
    {
        $room = $contract->room_id ? Room::withoutGlobalScope('activeBuilding')->find($contract->room_id) : null;

        if (! $room || ! $room->lock_id) {
            return false;
        }

        $setting = TtlockSetting::forBuilding((int) $room->building_id);

        return $setting->usesSeparateGateCode() && in_array(true, self::targetLocksFor($room, $setting), true);
    }

    // Đổi hẳn sang 1 mã MỚI — cho nút "Đổi mã mở" (nhân viên) và khách thuê tự đổi ở Portal/API. Xoá
    // hết mã cũ trên TTLock (mọi ổ khoá của hợp đồng) rồi cấp lại, giữ nguyên hạn dùng hiện tại.
    //
    // $customCode: để trống = TTLock tự sinh ngẫu nhiên (như trước); truyền vào (4-9 chữ số) = dùng
    // ĐÚNG số khách/nhân viên tự chọn — LỖI THẬT đã gặp: khách muốn tự đặt 1 mã dễ nhớ, không phải
    // mã ngẫu nhiên hệ thống đưa ra.
    //
    // $scope (chỉ có nghĩa khi hợp đồng có mã cổng RIÊNG — hasSeparateGateCode()): 'room' = chỉ đổi mã
    // phòng, 'gate' = chỉ đổi mã cổng, null = đổi cả hai. Toà dùng chung 1 mã thì luôn đổi tất cả.
    //
    // @return array{success: bool, message: string, code?: string, gate_code?: ?string}
    public static function regenerateCode(Contract $contract, ?string $customCode = null, ?string $scope = null): array
    {
        if ($customCode !== null && $customCode !== '' && ! preg_match('/^\d{4,9}$/', $customCode)) {
            return ['success' => false, 'message' => 'Mã tự chọn phải là 4-9 chữ số.'];
        }

        if (! self::canChangeCode($contract)) {
            return ['success' => false, 'message' => 'Hợp đồng đã hết hạn/không còn hiệu lực, phòng chưa gán khoá TTLock, hoặc chưa thu cọc/thanh toán hoá đơn đầu tiên — không thể đổi mã.'];
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

        $setting  = TtlockSetting::forBuilding((int) $room->building_id);
        $targets  = self::targetLocksFor($room, $setting);
        $separate = $setting->usesSeparateGateCode();
        $scope    = $separate && in_array($scope, ['room', 'gate'], true) ? $scope : null;
        $inScope  = fn (bool $isGate): bool => $scope === null || $isGate === ($scope === 'gate');
        $existing = ContractTtlockPasscode::where('contract_id', $contract->id)->get()->keyBy('lock_id');

        foreach ($existing->all() as $lockId => $row) {
            if ($inScope((bool) $row->is_gate) || ! array_key_exists((int) $lockId, $targets)) {
                self::revokeRow($ttlock, $row);
                $existing->forget($lockId);
            }
        }

        // Mã ngoài phạm vi đổi giữ nguyên (lấy lại từ dòng còn lại), mã trong phạm vi = số tự chọn
        // hoặc null (cấp mới ngẫu nhiên).
        $codes = self::codesFrom($existing, $separate);

        foreach (['room' => false, 'gate' => true] as $pool => $isGate) {
            if ($inScope($isGate)) {
                $codes[$pool] = $customCode ?: null;
            }
        }

        self::issueMissing($ttlock, $contract, $room, $targets, $existing, $codes, $separate);

        $new = ContractTtlockPasscode::where('contract_id', $contract->id)
            ->when($scope, fn ($q) => $q->where('is_gate', $scope === 'gate'))
            ->first();

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

        return ['success' => true, 'message' => 'Đã đổi mã mở mới.', 'code' => $new->code, 'gate_code' => self::currentCodes($contract)['gate']];
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

    // Mọi ổ khoá hợp đồng của 1 Phòng cần có mã: khoá phòng (ngoài + trong nếu có) rồi tới khoá cổng
    // của toà nhà — khoá phòng xếp TRƯỚC để mã phòng được sinh trước, mã cổng "dùng chung" lấy theo.
    //
    // @return array<int, bool> lockId => là khoá cổng?
    private static function targetLocksFor(Room $room, TtlockSetting $setting): array
    {
        $targets = [];

        foreach (array_filter([(int) $room->lock_id, (int) $room->lock_id_checkout]) as $lockId) {
            $targets[$lockId] = false;
        }

        foreach ($setting->gateLockIds() as $lockId) {
            $targets[$lockId] ??= true;
        }

        return $targets;
    }

    // Đủ điều kiện thu tiền để cấp mã LẦN ĐẦU chưa — toà "cấp ngay khi tạo hợp đồng" thì luôn đủ; toà
    // "cấp sau khi thu tiền" thì cần đã xác nhận thu cọc HOẶC có ít nhất 1 hoá đơn đã thanh toán đủ.
    private static function paymentSatisfied(Contract $contract, TtlockSetting $setting): bool
    {
        if (! $setting->issuesOnPayment()) {
            return true;
        }

        return $contract->deposit_paid_at !== null
            || Invoice::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('contract_id', $contract->id)
                ->where('status', Invoice::STATUS_PAID)
                ->exists();
    }

    // Mã đang dùng theo từng "nhóm mã" — toà dùng CHUNG 1 mã thì chỉ có nhóm 'room' (lấy mã bất kỳ đã
    // cấp, VD hợp đồng đã có mã ở ổ ngoài, giờ mới gán thêm ổ trong/thêm khoá cổng); mã cổng RIÊNG thì
    // khoá cổng thuộc nhóm 'gate'.
    //
    // @return array{room: ?string, gate: ?string}
    private static function codesFrom(Collection $existing, bool $separate): array
    {
        return $separate
            ? ['room' => $existing->firstWhere('is_gate', false)?->code, 'gate' => $existing->firstWhere('is_gate', true)?->code]
            : ['room' => $existing->first()?->code, 'gate' => null];
    }

    // Cấp mã cho các ổ CHƯA có dòng theo dõi, sửa hạn cho các ổ đã có. Mỗi nhóm mã dùng chung 1 số
    // trên mọi ổ của nhóm (khách chỉ cần nhớ 1 số).
    //
    // @param array<int, bool> $targets
    // @param array{room: ?string, gate: ?string} $codes
    private static function issueMissing(TTLockService $ttlock, Contract $contract, Room $room, array $targets, Collection $existing, array $codes, bool $separate): void
    {
        $startDate = self::resolveStartDate($contract);
        $endDate   = self::resolveEndDate($contract);
        $startMs   = $startDate->getTimestampMs();
        $endMs     = $endDate?->getTimestampMs() ?? 0;
        $name      = 'HD-' . $contract->id . ($room->code ? " {$room->code}" : '');

        foreach ($targets as $lockId => $isGate) {
            if ($row = $existing->get($lockId)) {
                self::resyncDatesIfChanged($ttlock, $row, $startDate, $endDate, $startMs, $endMs, $name);

                continue;
            }

            $pool = $separate && $isGate ? 'gate' : 'room';

            match (true) {
                $codes[$pool] !== null => self::issueAdditional($ttlock, $contract, $room, $lockId, $isGate, $codes[$pool], $startMs, $endMs, $name),
                $isGate                => self::issueFirstOnGate($ttlock, $contract, $room, $lockId, $startMs, $endMs, $name, $codes[$pool]),
                default                => self::issueFirst($ttlock, $contract, $room, $lockId, $startMs, $endMs, $name, $codes[$pool]),
            };
        }
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

    // Mã ĐẦU TIÊN của 1 nhóm mà ổ đầu tiên lại là KHOÁ CỔNG (mã cổng riêng, hoặc phòng không gắn
    // khoá): KHÔNG để TTLock tự sinh như issueFirst() — khoá cổng dùng chung cho mọi hợp đồng, mà
    // TTLock trả CÙNG 1 mã cho cùng khoá + cùng khung giờ (xem ManualLockPasswordTtlockIssuer), 2 hợp
    // đồng cùng ngày bắt đầu/kết thúc sẽ trùng mã cổng. Tự sinh số ngẫu nhiên chưa ai trong toà dùng
    // rồi thêm như mã tự chọn; TTLock chê "quá đơn giản"/trùng thì thử số khác.
    private static function issueFirstOnGate(TTLockService $ttlock, Contract $contract, Room $room, int $lockId, int $startMs, int $endMs, string $name, ?string &$code): void
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidate = (string) random_int(100000, 999999);

            if (ContractTtlockPasscode::where('building_id', $room->building_id)->where('code', $candidate)->exists()) {
                continue;
            }

            $result = $ttlock->addCustomPasscode(lockId: $lockId, code: $candidate, startDate: $startMs, endDate: $endMs, name: $name);

            if (! $result) {
                continue;
            }

            $code = $candidate;

            ContractTtlockPasscode::create([
                'contract_id' => $contract->id, 'building_id' => $room->building_id, 'lock_id' => $lockId, 'is_gate' => true,
                'keyboard_pwd_id' => $result['keyboardPwdId'], 'code' => $code,
                'start_date' => date('Y-m-d H:i:s', intdiv($startMs, 1000)), 'end_date' => $endMs > 0 ? date('Y-m-d H:i:s', intdiv($endMs, 1000)) : null,
            ]);

            return;
        }

        Log::error('MiniHouse ContractTtlockService: cấp mã khoá cổng thất bại', ['contract_id' => $contract->id, 'lock_id' => $lockId]);
    }

    private static function issueAdditional(TTLockService $ttlock, Contract $contract, Room $room, int $lockId, bool $isGate, string $code, int $startMs, int $endMs, string $name): void
    {
        $result = $ttlock->addCustomPasscode(lockId: $lockId, code: $code, startDate: $startMs, endDate: $endMs, name: $name);

        if (! $result) {
            Log::error('MiniHouse ContractTtlockService: addCustomPasscode thất bại', ['contract_id' => $contract->id, 'lock_id' => $lockId]);

            return;
        }

        ContractTtlockPasscode::create([
            'contract_id' => $contract->id, 'building_id' => $room->building_id, 'lock_id' => $lockId, 'is_gate' => $isGate,
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
