<?php

namespace App\Services;

use App\Exceptions\CccdIntakeException as E;
use App\Models\Customer;
use App\Models\CustomerCompanion;
use App\Support\CccdIdentity;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Payment\App\Services\CccdScannerService;

/**
 * Điểm nhận CCCD DÙNG CHUNG cho web (ProductDetail) và mọi API — để quy tắc luôn đồng nhất:
 *
 *   kiểm tra file → quét QR trên file TẠM (chưa lưu vĩnh viễn) → kiểm tra cấu trúc số CCCD
 *   → (theo đơn) tuổi + trùng người → lưu ảnh vào cccd_qr_image.
 *
 * Luồng khách (web/app): 1 ảnh mặt có QR (`cccd_qr_image`), chỉ nhận dữ liệu từ QR, sai là chặn.
 * Luồng admin: nhận tuỳ ý cccd_qr_image/cccd_front/cccd_back, quét từng ảnh rồi ĐỐI CHỨNG với
 * nhau; không đọc được QR thì vẫn cho lưu kèm cảnh báo (lễ tân nhập tay sau).
 *
 * Lỗi ném CccdIntakeException → API tự trả 422 {message, code, field}.
 */
class CccdIntakeService
{
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_BYTES     = 5 * 1024 * 1024;
    private const ADMIN_MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly CccdScannerService $scanner)
    {
    }

    // ── Kiểm tra file & giới hạn ─────────────────────────────────────────────

    public function imageError(UploadedFile $file, int $maxBytes = self::MAX_BYTES): ?string
    {
        $path = $file->getRealPath();

        if (! $file->isValid() || ! $path || ! is_file($path)
            || ! in_array($file->getMimeType(), self::ALLOWED_MIMES, true)
            || $file->getSize() > $maxBytes) {
            return 'Ảnh CCCD phải là JPG, PNG hoặc WEBP, tối đa ' . intdiv($maxBytes, 1024 * 1024) . 'MB.';
        }

        $size = @getimagesize($path);
        if (! $size || min($size[0], $size[1]) < 300 || max($size[0], $size[1]) < 500) {
            return 'Ảnh CCCD quá nhỏ hoặc không phải ảnh hợp lệ. Vui lòng chụp rõ nét mặt có mã QR.';
        }

        return null;
    }

    /**
     * Tính 1 lượt quét; trả về thông báo lỗi nếu đã vượt giới hạn (theo IP + theo "actor": session
     * id ở web, customer id / số điện thoại ở API).
     */
    public function hitScanLimit(?string $actor = null): ?string
    {
        $cfg    = config('cccd.scan_limit');
        $limits = ['cccd-scan:ip:' . request()->ip() => $cfg['per_ip']];
        if ($actor) {
            $limits['cccd-scan:actor:' . $actor] = $cfg['per_actor'];
        }

        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));
                Log::warning('[CccdIntake] scan rate limited', ['key' => $key]);

                return "Bạn đã gửi CCCD quá nhiều lần. Vui lòng thử lại sau {$minutes} phút.";
            }
        }

        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, $cfg['decay_seconds']);
        }

        return null;
    }

    /** Quét CHỈ mã QR trên 1 ảnh (file upload hoặc đường dẫn tuyệt đối). */
    public function scanQr(UploadedFile|string $file): ?array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;

        return $path ? $this->scanner->scanQrImage($path) : null;
    }

    // ── Luồng khách (web/app) ────────────────────────────────────────────────

    /**
     * Đọc CCCD của 1 khách từ request API. Ưu tiên $qrKey (cccd_qr_image / guests.i.qr_image);
     * app cũ gửi $legacyKeys (front/back) thì chấp nhận khi bật cccd.accept_legacy_front_back.
     *
     * @return array{data: array, file: UploadedFile}|null  null khi không gửi gì và !$required
     */
    public function readFromRequest(Request $request, string $qrKey, array $legacyKeys, string $field, bool $required, ?string $actor): ?array
    {
        if ($request->hasFile($qrKey)) {
            $file = $request->file($qrKey);

            return ['data' => $this->readStrict($file, $field, $actor), 'file' => $file];
        }

        $legacy = array_values(array_filter(array_map(
            fn ($key) => $request->hasFile($key) ? $request->file($key) : null,
            $legacyKeys,
        )));

        if ($legacy && config('cccd.accept_legacy_front_back')) {
            Log::info('[CccdIntake] legacy front/back upload', ['field' => $field, 'keys' => $legacyKeys]);

            return $this->readLegacy($legacy, $field, $actor);
        }

        if ($required) {
            throw new E('Vui lòng gửi ảnh CCCD (mặt có mã QR).', E::REQUIRED, $field);
        }

        return null;
    }

    /** 1 ảnh mặt có QR → cccd_data hợp lệ, hoặc ném lỗi. */
    public function readStrict(UploadedFile $file, string $field, ?string $actor): array
    {
        if ($error = $this->imageError($file)) {
            throw new E($error, E::IMAGE_INVALID, $field);
        }
        if ($error = $this->hitScanLimit($actor)) {
            throw new E($error, E::RATE_LIMITED, $field, 429);
        }

        return $this->assertValid($this->scanQr($file), $field);
    }

    /**
     * App cũ gửi 2 ảnh trước/sau: tìm ảnh chứa QR (thẻ cũ QR ở mặt trước, thẻ căn cước 2024 ở
     * mặt sau) → ảnh đó được lưu làm cccd_qr_image.
     *
     * @param  UploadedFile[]  $files
     * @return array{data: array, file: UploadedFile}
     */
    public function readLegacy(array $files, string $field, ?string $actor): array
    {
        foreach ($files as $file) {
            if ($error = $this->imageError($file)) {
                throw new E($error, E::IMAGE_INVALID, $field);
            }
        }
        if ($error = $this->hitScanLimit($actor)) {
            throw new E($error, E::RATE_LIMITED, $field, 429);
        }

        $this->allowScanTime(count($files));
        foreach ($files as $file) {
            if ($data = $this->scanQr($file)) {
                return ['data' => $this->assertValid($data, $field), 'file' => $file];
            }
        }

        return ['data' => $this->assertValid(null, $field), 'file' => $files[0]];
    }

    public function assertValid(?array $data, string $field, bool $requireQr = true): array
    {
        if ($error = CccdIdentity::validate($data, $requireQr)) {
            throw new E($error, $data ? E::INVALID : E::QR_UNREADABLE, $field);
        }

        return $data;
    }

    /**
     * Kiểm tra theo ĐƠN: dưới 16 tuổi (tại ngày nhận phòng) và trùng người (số CCCD, hoặc họ tên
     * + ngày sinh) giữa người đặt và người đi cùng / giữa các người đi cùng.
     *
     * Người đã có sẵn trong đơn truyền 'skip_age' => true: chỉ dùng để so trùng, không kiểm tra
     * lại tuổi (đơn cũ vẫn sửa được).
     *
     * @param  array<int, array{field: string, label: string, is_booker: bool, data: array, skip_age?: bool, extra?: array}>  $people
     */
    public function assertPeople(array $people, ?CarbonInterface $checkinDate): void
    {
        $seen = [];

        foreach ($people as $person) {
            if (empty($person['skip_age']) && $error = CccdIdentity::ageError($person['data'], $person['is_booker'], $checkinDate, $person['label'])) {
                throw new E($error, E::UNDER_AGE, $person['field'], 422, $person['extra'] ?? []);
            }

            foreach ($seen as $other) {
                if (CccdIdentity::samePerson($person['data'], $other['data'])) {
                    throw new E(
                        "{$person['label']}: thông tin CCCD trùng với " . mb_strtolower($other['label']) . '. Mỗi người cần CCCD riêng của chính mình.',
                        E::DUPLICATE,
                        $person['field'],
                        422,
                        $person['extra'] ?? [],
                    );
                }
            }

            $seen[] = $person;
        }
    }

    public function storeQrImage(UploadedFile $file): string
    {
        return $file->store(config('cccd.qr_image_directory'), 'public');
    }

    /**
     * CCCD người đi cùng trong request đặt phòng/sửa đơn — key theo VỊ TRÍ 0-based guests[i]:
     *   - guests[i][qr_image]      ảnh mặt có QR (app mới)
     *   - guests[i][companion_id]  chọn người đi cùng đã lưu trong hồ sơ (chỉ khi có $customer)
     *   - guests[i][front|back]    app cũ (cờ cccd.accept_legacy_front_back)
     *   - không gửi gì + cờ legacy + có $customer → lấy người đi cùng TIẾP THEO trong hồ sơ (đúng
     *     hành vi app cũ), bỏ qua những người đã được chọn tường minh.
     *
     * $keyOffset: key thực = vị trí + offset — vài endpoint cũ đánh key theo guest_index tuyệt đối
     * (guests[2], guests[3]...) thay vì vị trí 0-based; giữ nguyên để app đang chạy không hỏng.
     *
     * @return list<array{guest_index: int, field: string, label: string, data: array, file: ?UploadedFile, companion: ?CustomerCompanion}>
     */
    public function readGuestsFromRequest(Request $request, int $count, int $firstGuestIndex, ?Customer $customer, ?string $actor, int $keyOffset = 0): array
    {
        $companions = $customer ? $customer->companions()->orderBy('id')->get()->keyBy('id') : collect();
        $explicitIds = collect($count > 0 ? range(0, $count - 1) : [])
            ->map(fn ($i) => (int) $request->input('guests.' . ($i + $keyOffset) . '.companion_id'))
            ->filter()
            ->all();
        $autoPool = $companions->except($explicitIds)->values();
        $rows     = [];

        for ($position = 0; $position < $count; $position++) {
            $i          = $position + $keyOffset;
            $guestIndex = $firstGuestIndex + $position;
            $label      = "Người đi cùng #{$guestIndex}";
            $companion  = null;
            $file       = null;

            if ($companionId = (int) $request->input("guests.{$i}.companion_id")) {
                $field     = "guests.{$i}.companion_id";
                $companion = $companions->get($companionId);
                if (! $companion) {
                    throw new E("{$label}: người đi cùng đã chọn không tồn tại trong hồ sơ.", E::COMPANION_INVALID, $field, 422, ['companion_id' => $companionId]);
                }
            } else {
                $field = "guests.{$i}.qr_image";
                $read  = $this->readFromRequest($request, $field, ["guests.{$i}.front", "guests.{$i}.back"], $field, false, $actor);

                if ($read) {
                    ['data' => $data, 'file' => $file] = $read;
                } elseif ($customer && config('cccd.accept_legacy_front_back') && $autoPool->isNotEmpty()) {
                    $companion = $autoPool->shift();
                } else {
                    // Log key file thực nhận — đối chiếu khi app đặt sai tên key (vd guests[2] thay vì guests[0]).
                    Log::warning('[CccdIntake] thiếu CCCD người đi cùng', [
                        'expected'   => $field,
                        'file_paths' => array_keys(Arr::dot($request->allFiles())),
                    ]);
                    throw new E("Khung giờ qua đêm cần khai báo lưu trú cho {$label} — vui lòng gửi ảnh CCCD (mặt có mã QR) hoặc chọn người đi cùng trong hồ sơ.", E::REQUIRED, $field);
                }
            }

            if ($companion) {
                // Hồ sơ cũ có thể lưu từ OCR — chỉ đòi cấu trúc hợp lệ, không đòi nguồn QR.
                $data = is_array($companion->cccd_data) ? $companion->cccd_data : null;
                if ($error = CccdIdentity::validate($data, requireQr: false)) {
                    throw new E("{$label} ({$companion->full_name}): thông tin CCCD trong hồ sơ không hợp lệ — {$error} Vui lòng cập nhật lại ảnh CCCD của người này.", E::COMPANION_INVALID, $field, 422, ['companion_id' => $companion->id]);
                }
            }

            $rows[] = ['guest_index' => $guestIndex, 'field' => $field, 'label' => $label, 'data' => $data, 'file' => $file, 'companion' => $companion];
        }

        return $rows;
    }

    /**
     * Người đã khai báo CCCD trong 1 đơn có sẵn (người đặt + order_guest_cccds) — đầu vào cho
     * assertPeople() khi sửa đơn/thêm khách. $bookerData thay dữ liệu người đặt nếu vừa đổi ảnh.
     */
    public static function existingPeople(\Modules\Payment\Entities\Order $order, ?array $bookerData = null, bool $bookerIsNew = false): array
    {
        $bookerData ??= is_array($order->cccd_data) ? $order->cccd_data : null;
        $people = [];

        if ($bookerData) {
            $people[] = ['field' => 'cccd_qr_image', 'label' => 'Người đặt phòng', 'is_booker' => true, 'data' => $bookerData, 'skip_age' => ! $bookerIsNew];
        }

        foreach ($order->guestCccds as $guest) {
            if (is_array($guest->cccd_data) && $guest->cccd_data) {
                $people[] = ['field' => 'guests', 'label' => "Người đi cùng #{$guest->guest_index}", 'is_booker' => false, 'data' => $guest->cccd_data, 'skip_age' => true];
            }
        }

        return $people;
    }

    /**
     * Ngày nhận phòng sớm nhất trong các item của đơn — mốc tính tuổi.
     */
    public static function checkinDateFromItems(iterable $items): ?CarbonInterface
    {
        $dates = collect($items)
            ->map(fn ($item) => data_get($item, 'checkin_date'))
            ->filter()
            ->map(fn ($d) => Carbon::parse($d)->startOfDay());

        return $dates->min();
    }

    /**
     * Copy ảnh đang dùng ở hồ sơ sang file riêng cho đơn — đơn là bản chụp tại thời điểm đặt,
     * sau này khách sửa/xoá ảnh trong hồ sơ thì ảnh của đơn cũ vẫn còn.
     *
     * @param  array<string, ?string>  $paths  [cột => path]
     * @return array<string, ?string>
     */
    public function snapshotImages(array $paths): array
    {
        $disk = Storage::disk('public');

        return array_map(function ($path) use ($disk) {
            if (! is_string($path) || $path === '' || ! $disk->exists($path)) {
                return null;
            }
            $copy = 'cccd/snapshots/' . Str::random(40) . '.' . (pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg');

            return $disk->copy($path, $copy) ? $copy : null;
        }, $paths);
    }

    /**
     * Lưu CCCD vừa quét của người đi cùng vào hồ sơ khách để lần sau chọn lại — chống trùng:
     *   - chưa có ai cùng số CCCD → tạo mới;
     *   - đã có, cùng người (họ tên + ngày sinh khớp) → cập nhật ảnh QR + dữ liệu mới;
     *   - đã có nhưng họ tên/ngày sinh KHÁC (nghi quét sai) → giữ nguyên dữ liệu cũ, trả 'conflict'.
     * Không bao giờ xoá ảnh cũ (đơn cũ có thể còn tham chiếu).
     *
     * @return 'created'|'updated'|'conflict'|'skipped'
     */
    public function rememberCompanion(Customer $customer, array $data, ?string $qrImagePath, bool $copyImage = true): string
    {
        // $copyImage = false: $qrImagePath là file lưu riêng cho hồ sơ (vd upload thẳng vào hồ sơ),
        // dùng luôn không copy; bị bỏ qua/xung đột thì xoá.
        $image = $qrImagePath && $copyImage ? $this->snapshotImages(['q' => $qrImagePath])['q'] : $qrImagePath;

        if (is_array($customer->cccd_data) && CccdIdentity::samePerson($data, $customer->cccd_data)) {
            $this->deleteImages([$image]);

            return 'skipped'; // chính chủ tài khoản, không phải người đi cùng
        }

        $existing = $customer->companions()->get()
            ->first(fn (CustomerCompanion $c) => trim((string) ($c->cccd_data['cccd'] ?? '')) === $data['cccd']);

        if (! $existing) {
            $customer->companions()->create([
                'full_name'     => $data['full_name'] ?? null,
                'cccd_qr_image' => $image,
                'cccd_data'     => $data,
            ]);

            return 'created';
        }

        $sameIdentity = CccdIdentity::samePerson(
            ['full_name' => $data['full_name'] ?? '', 'dob' => $data['dob'] ?? ''],
            ['full_name' => $existing->cccd_data['full_name'] ?? '', 'dob' => $existing->cccd_data['dob'] ?? ''],
        );

        if (! $sameIdentity) {
            $this->deleteImages([$image]);
            Log::warning('[CccdIntake] companion conflict — giữ dữ liệu cũ', ['customer_id' => $customer->id, 'companion_id' => $existing->id]);

            return 'conflict';
        }

        $existing->update(['full_name' => $data['full_name'] ?? $existing->full_name, 'cccd_qr_image' => $image ?? $existing->cccd_qr_image, 'cccd_data' => $data]);

        return 'updated';
    }

    /**
     * Khách tự xác thực CCCD cho hồ sơ (trang cá nhân). Hồ sơ (customers.cccd_data — dùng khi đặt
     * phòng) luôn nhận CCCD mới nhất, kể cả CCCD của người khác; mọi lần xác thực đều được lưu vào
     * customer_cccd_verifications (lần 1, 2, 3...) để admin xem lịch sử thay đổi ở trang quản lý
     * thành viên. same_as_first đánh dấu lần đó có cùng SỐ CCCD với lần xác thực đầu tiên không
     * (khác số = khách đã đổi sang CCCD khác — cần để ý khi tra soát):
     *   - 'first'   : lần xác thực đầu tiên;
     *   - 'same'    : cùng số CCCD với lần đầu (vd làm lại thẻ);
     *   - 'changed' : khác số CCCD với lần đầu.
     * Chỉ so theo SỐ CCCD (không dùng họ tên + ngày sinh): số CCCD không đổi khi làm lại thẻ, còn
     * trùng tên + ngày sinh nhưng khác số vẫn có thể là người khác.
     *
     * @return array{status: 'first'|'same'|'changed', attempt: int}
     */
    public function recordCustomerVerification(Customer $customer, array $data, UploadedFile $file, string $source): array
    {
        $image = $this->storeQrImage($file);

        try {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($customer, $data, $image, $source) {
                // Khoá hàng khách để 2 lần gửi cùng lúc không tính trùng số lần.
                $customer = Customer::whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
                $current  = is_array($customer->cccd_data) && CccdIdentity::validate($customer->cccd_data, requireQr: false) === null
                    ? $customer->cccd_data
                    : null;

                // CCCD có sẵn trong hồ sơ từ trước khi có lịch sử → ghi nhận làm lần 1.
                if ($current && ! $customer->cccdVerifications()->exists()) {
                    $customer->cccdVerifications()->create([
                        'attempt'       => 1,
                        'cccd_qr_image' => $customer->cccd_qr_image ?: $customer->cccd_front,
                        'cccd_data'     => $current,
                        'same_as_first' => true,
                        'source'        => \App\Models\CustomerCccdVerification::SOURCE_LEGACY,
                    ]);
                }

                $first   = $customer->cccdVerifications()->where('attempt', 1)->value('cccd_data');
                $first   = is_string($first) ? json_decode($first, true) : $first;
                $status  = match (true) {
                    ! $first                                                                => 'first',
                    trim((string) ($first['cccd'] ?? '')) === trim((string) $data['cccd']) => 'same',
                    default                                                                 => 'changed',
                };
                $attempt = (int) $customer->cccdVerifications()->max('attempt') + 1;

                $customer->cccdVerifications()->create([
                    'attempt'       => $attempt,
                    'cccd_qr_image' => $image,
                    'cccd_data'     => $data,
                    'same_as_first' => $status !== 'changed',
                    'source'        => $source,
                ]);

                $oldQrImage = $customer->cccd_qr_image;
                // Hồ sơ dùng bản copy riêng — ảnh của lịch sử xác thực giữ nguyên độc lập.
                $customer->update([
                    'cccd_qr_image' => $this->snapshotImages(['q' => $image])['q'],
                    'cccd_data'     => $data,
                ]);
                \Illuminate\Support\Facades\DB::afterCommit(fn () => $this->deleteUnreferencedImages([$oldQrImage]));

                if ($status === 'changed') {
                    Log::info('[CccdIntake] khách đổi sang CCCD khác số với lần xác thực đầu', [
                        'customer_id' => $customer->id,
                        'attempt'     => $attempt,
                    ]);
                }

                return ['status' => $status, 'attempt' => $attempt];
            });
        } catch (\Throwable $e) {
            $this->deleteImages([$image]);
            throw $e;
        }
    }

    /**
     * Lưu CCCD của chính người đặt vào hồ sơ — CHỈ khi hồ sơ đang trống/không hợp lệ và họ tên
     * trên CCCD khớp tên tài khoản (khách đặt hộ người khác không làm hỏng hồ sơ của mình).
     */
    public function rememberCustomerCccd(Customer $customer, array $data, ?string $qrImagePath): bool
    {
        $current = is_array($customer->cccd_data) ? $customer->cccd_data : null;
        if ($current && CccdIdentity::validate($current, requireQr: false) === null) {
            return false;
        }

        $normalize = fn ($n) => Str::upper(trim((string) preg_replace('/\s+/', ' ', Str::ascii((string) $n))));
        if (filled($customer->fullname) && $normalize($customer->fullname) !== $normalize($data['full_name'] ?? '')) {
            return false;
        }

        $image = $qrImagePath ? $this->snapshotImages(['q' => $qrImagePath])['q'] : null;
        $customer->update(['cccd_qr_image' => $image, 'cccd_data' => $data]);

        return true;
    }

    /**
     * URL công khai các ảnh CCCD của 1 bản ghi (orders / order_guest_cccds / customers /
     * customer_companions) — dùng chung cho response API.
     */
    public static function imageUrls(mixed $row): array
    {
        $url = fn ($path) => filled($path) ? Storage::disk('public')->url($path) : null;

        return [
            'cccd_qr_image' => $url(data_get($row, 'cccd_qr_image')),
            'cccd_front'    => $url(data_get($row, 'cccd_front')),
            'cccd_back'     => $url(data_get($row, 'cccd_back')),
        ];
    }

    // ── Luồng admin ──────────────────────────────────────────────────────────

    /**
     * Admin gửi tuỳ ý các ảnh (key => UploadedFile|null), vd ['cccd_qr_image' => …, 'cccd_front'
     * => …, 'cccd_back' => …]. Quét QR TỪNG ảnh riêng rồi đối chứng: ảnh của 2 người khác nhau →
     * chặn. Không đọc được QR ở đâu → thử OCR mặt trước; vẫn không được → data null + cảnh báo.
     *
     * @return array{data: ?array, checks: array<string, string>, warnings: string[]}
     */
    public function readForAdmin(array $files, string $fieldPrefix = ''): array
    {
        $files    = array_filter($files, fn ($f) => $f instanceof UploadedFile);
        $results  = [];

        $this->allowScanTime(count($files));
        $warnings = [];

        foreach ($files as $key => $file) {
            if ($error = $this->imageError($file, self::ADMIN_MAX_BYTES)) {
                throw new E($error, E::IMAGE_INVALID, $fieldPrefix . $key);
            }
            $results[$key] = $this->scanQr($file);
        }

        $readable = array_filter($results);
        $keys     = array_keys($readable);
        for ($i = 1; $i < count($keys); $i++) {
            if (! CccdIdentity::samePerson($readable[$keys[0]], $readable[$keys[$i]])) {
                throw new E(
                    'Ảnh ' . $this->adminLabel($keys[$i]) . ' và ' . $this->adminLabel($keys[0]) . ' là CCCD của 2 người khác nhau.',
                    E::MISMATCH,
                    $fieldPrefix . $keys[$i],
                );
            }
        }

        // Ưu tiên kết quả từ cccd_qr_image, sau đó tới ảnh nào đọc được đầu tiên.
        $data = $readable['cccd_qr_image'] ?? ($readable ? reset($readable) : null);

        if (! $data && isset($files['cccd_front'])) {
            $data = $this->scanner->scanImage($files['cccd_front']->getRealPath());
            if ($data) {
                $warnings[] = 'Không đọc được mã QR — thông tin lấy bằng OCR mặt trước, vui lòng kiểm tra lại.';
            }
        }

        if (! $data && $files) {
            $warnings[] = 'Không đọc được thông tin CCCD từ ảnh — vui lòng nhập tay.';
        }

        if ($data && ($error = CccdIdentity::validate($data, requireQr: false))) {
            $warnings[] = $error;
        }

        $checks = [];
        foreach ($results as $key => $result) {
            $checks[$key] = $result ? 'match' : 'unreadable';
        }

        return ['data' => $data, 'checks' => $checks, 'warnings' => $warnings];
    }

    /**
     * Quét 1 ảnh mặt có mã QR cho các endpoint quét ĐỘC LẬP của quản trị (homestay, minihouse) —
     * chỉ đọc QR, không OCR, không lưu ảnh. Thiếu ảnh/ảnh không hợp lệ → ném lỗi; không đọc được
     * QR hoặc dữ liệu sai cấu trúc thì KHÔNG chặn, trả cảnh báo để nhân viên nhập tay.
     *
     * @return array{data: ?array, warnings: string[]}
     */
    public function scanQrForAdmin(Request $request, string $key = 'cccd_qr_image'): array
    {
        if (! $request->hasFile($key)) {
            throw new E('Vui lòng gửi ảnh CCCD (mặt có mã QR).', E::REQUIRED, $key);
        }

        $file = $request->file($key);
        if ($error = $this->imageError($file, self::ADMIN_MAX_BYTES)) {
            throw new E($error, E::IMAGE_INVALID, $key);
        }

        $data = $this->scanQr($file);

        return [
            'data'     => $data,
            'warnings' => array_values(array_filter([
                $data ? CccdIdentity::validate($data) : 'Không đọc được mã QR trên ảnh CCCD — vui lòng chụp lại hoặc nhập tay.',
            ])),
        ];
    }

    /**
     * Ảnh admin gửi cho 1 người, map về tên cột: $prefix '' → cccd_qr_image|cccd_front|cccd_back;
     * $prefix 'guests.2.' → guests.2.qr_image|front|back.
     *
     * @return array{cccd_qr_image: ?UploadedFile, cccd_front: ?UploadedFile, cccd_back: ?UploadedFile}
     */
    public static function adminFilesFromRequest(Request $request, string $prefix = ''): array
    {
        $keys = $prefix === ''
            ? ['cccd_qr_image' => 'cccd_qr_image', 'cccd_front' => 'cccd_front', 'cccd_back' => 'cccd_back']
            : ['cccd_qr_image' => "{$prefix}qr_image", 'cccd_front' => "{$prefix}front", 'cccd_back' => "{$prefix}back"];

        return array_map(fn ($key) => $request->hasFile($key) ? $request->file($key) : null, $keys);
    }

    /**
     * Lưu các ảnh admin gửi lên, trả về [cột => path]. cccd_qr_image vào thư mục QR, 2 mặt
     * trước/sau giữ thư mục 'cccd' như trước.
     */
    public function storeAdminImages(array $files): array
    {
        $paths = [];
        foreach (array_filter($files, fn ($f) => $f instanceof UploadedFile) as $key => $file) {
            $paths[$key] = $key === 'cccd_qr_image' ? $this->storeQrImage($file) : $file->store('cccd', 'public');
        }

        return $paths;
    }

    public function deleteImages(array $paths): void
    {
        $paths = array_values(array_filter($paths, fn ($p) => is_string($p) && $p !== ''));
        if ($paths) {
            Storage::disk('public')->delete($paths);
        }
    }

    /**
     * Xoá file ảnh CCCD CHỈ KHI không còn bản ghi nào tham chiếu — dữ liệu cũ có đơn dùng chung
     * file với hồ sơ khách/người đi cùng (trước khi có snapshot). Gọi SAU khi đã xoá/cập nhật bản
     * ghi đang sở hữu các path này.
     */
    public function deleteUnreferencedImages(array $paths): void
    {
        $paths = array_values(array_unique(array_filter($paths, fn ($p) => is_string($p) && $p !== '')));
        if (! $paths) {
            return;
        }

        $referenced = collect([
            \Modules\Payment\Entities\Order::class,
            \Modules\Payment\Entities\OrderGuestCccd::class,
            Customer::class,
            CustomerCompanion::class,
        ])->flatMap(fn ($model) => $model::query()
            ->where(fn ($q) => $q->whereIn('cccd_qr_image', $paths)->orWhereIn('cccd_front', $paths)->orWhereIn('cccd_back', $paths))
            ->get(['cccd_qr_image', 'cccd_front', 'cccd_back'])
            ->flatMap(fn ($row) => [$row->cccd_qr_image, $row->cccd_front, $row->cccd_back])
        )->merge(
            // Lịch sử xác thực CCCD của khách (lần 1 có thể trỏ thẳng vào ảnh cũ của hồ sơ).
            \App\Models\CustomerCccdVerification::whereIn('cccd_qr_image', $paths)->pluck('cccd_qr_image')
        )->filter()->unique()->all();

        $this->deleteImages(array_diff($paths, $referenced));
    }

    /**
     * Mỗi ảnh quét tối đa ~18s (CccdScannerService::MAX_SCAN_SECONDS) — nhiều ảnh có thể vượt
     * max_execution_time. CHỈ NỚI thêm, không bao giờ hạ: set_time_limit() đặt lại giới hạn tính
     * từ lúc gọi, gọi bừa sẽ rút ngắn request đang được phép chạy lâu hơn (hoặc không giới hạn).
     */
    private function allowScanTime(int $images): void
    {
        $current = (int) ini_get('max_execution_time');
        if ($current === 0) {
            return; // không giới hạn (CLI/queue)
        }

        @set_time_limit(max($current, 30 + 20 * $images));
    }

    private function adminLabel(string $key): string
    {
        return match ($key) {
            'cccd_qr_image' => 'mặt có mã QR',
            'cccd_front'    => 'mặt trước',
            'cccd_back'     => 'mặt sau',
            default         => $key,
        };
    }
}
