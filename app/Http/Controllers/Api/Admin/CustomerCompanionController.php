<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Exceptions\CccdIntakeException;
use App\Models\CustomerCompanion;
use App\Services\CccdIntakeService;
use App\Support\CccdIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Quản lý CCCD "khách đi cùng" đã LƯU SẴN vào hồ sơ 1 khách hàng (customer_companions) — tái sử
 * dụng được cho nhiều lần đặt phòng qua đêm sau này, khác với CCCD khách đi cùng gắn riêng theo
 * từng đơn (Admin\BookingController::store(), guests[]).
 *
 * Luồng FE dự kiến khi admin tạo đơn và CHỌN khách hàng có sẵn (thay vì tạo khách vãng lai mới):
 * dựa vào guest_count, hiển thị đúng (guest_count - 1) ô khách đi cùng — mỗi ô cho phép CHỌN 1
 * companion đã có sẵn ở GET .../companions, hoặc THÊM MỚI (quét CCCD) qua POST .../companions nếu
 * số companion đã lưu chưa đủ.
 */
class CustomerCompanionController extends Controller
{
    // GET /api/admin/customers/{customer_id}/companions
    public function index(Request $request, string $customerId): JsonResponse
    {
        $customer = $this->resolveCustomer($request, $customerId);

        if (! $customer) {
            return response()->json(['message' => 'Không tìm thấy khách hàng.'], 404);
        }

        $companions = $customer->companions()
            ->withCount('orderGuestCccds')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CustomerCompanion $companion) => $this->formatCompanion($companion))
            ->values();

        return response()->json(['data' => $companions]);
    }

    // POST /api/admin/customers/{customer_id}/companions (multipart/form-data)
    // Thêm CÙNG LÚC nhiều companion — số lượng do FE quyết định dựa trên guest_count của đơn
    // (ví dụ guest_count=3 → gửi 3 companion trong 1 request thay vì gọi POST 3 lần riêng lẻ).
    // Mỗi companion CHỌN 1 trong 2 chế độ, tự nhận diện qua field nào được gửi:
    //  - Chế độ ẢNH:  companions[{index}][qr_image|cccd_front|cccd_back] — gửi ảnh nào lưu ảnh đó,
    //    quét QR từng ảnh + đối chứng (CccdIntakeService::readForAdmin), full_name lấy tự động từ
    //    kết quả quét (không nhận qua payload).
    //  - Chế độ NHẬP TAY: companions[{index}][full_name|cccd|dob|gender|address] — dùng khi admin
    //    đã có sẵn thông tin (không có ảnh chụp), full_name và cccd bắt buộc, dob/gender/address
    //    tuỳ chọn. Không quét ảnh, cccd_front/cccd_back lưu null.
    // Xử lý theo TRANSACTION — 1 companion lỗi (trùng CCCD, 2 mặt không khớp...) thì rollback toàn
    // bộ batch và xoá lại các ảnh đã upload trong request, tránh tạo dở dang nửa danh sách.
    public function store(Request $request, string $customerId): JsonResponse
    {
        $customer = $this->resolveCustomer($request, $customerId);

        if (! $customer) {
            return response()->json(['message' => 'Không tìm thấy khách hàng.'], 404);
        }

        $data = $request->validate([
            'companions'                => 'required|array|min:1',
            'companions.*.qr_image'     => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'companions.*.cccd_front'   => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'companions.*.cccd_back'    => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'companions.*.full_name'    => 'sometimes|nullable|string|max:255',
            'companions.*.cccd'         => 'sometimes|nullable|string|max:20',
            'companions.*.dob'          => 'sometimes|nullable|string|max:20',
            'companions.*.gender'       => 'sometimes|nullable|string|max:20',
            'companions.*.address'      => 'sometimes|nullable|string|max:255',
        ]);

        $uploadedPaths = [];
        $intake        = app(CccdIntakeService::class);
        $checks        = [];

        try {
            $companions = DB::transaction(function () use ($request, $data, $customer, $intake, &$uploadedPaths, &$checks) {
                $seenPeople = [];
                $seenHashes = [];
                $created    = [];

                foreach (array_keys($data['companions']) as $index) {
                    $files = $this->companionFiles($request, "companions.{$index}.");

                    if ($files) {
                        // Trùng ẢNH (hash nội dung) — bắt cả khi QR không đọc được nên không có số
                        // CCCD để so. Kiểm tra trước khi quét vì không phụ thuộc kết quả quét.
                        $seenHashes[$index] = $this->assertNoImageDuplicate($customer, $files, null, $seenHashes, $index);

                        // Quét + đối chứng từng ảnh: ảnh của 2 người khác nhau → 422 cccd_mismatch.
                        $read     = $intake->readForAdmin($files, "companions.{$index}.");
                        $cccdData = $read['data'];
                        $checks[$index] = ['checks' => $read['checks'], 'warnings' => $read['warnings']];

                        $this->assertNoCccdDuplicate($customer, $cccdData, null, $seenPeople, $index);
                        if ($cccdData) {
                            $seenPeople[$index] = $cccdData;
                        }

                        // Không bắt buộc đọc được QR — quét lỗi vẫn tạo companion bình thường,
                        // cccd_data để trống, full_name lấy từ QR (không nhận qua payload) — admin
                        // sửa tay sau nếu cần (cùng nguyên tắc CCCD "tùy chọn" toàn hệ thống).
                        $paths = $intake->storeAdminImages($files);
                        array_push($uploadedPaths, ...array_values($paths));

                        $created[] = $customer->companions()->create([
                            'full_name' => $cccdData['full_name'] ?? null,
                            ...$paths,
                            'cccd_data' => $cccdData,
                        ]);

                        continue;
                    }

                    // Chế độ NHẬP TAY — không có ảnh, admin gõ trực tiếp thông tin CCCD đã biết sẵn.
                    $row      = $data['companions'][$index];
                    $fullName = trim((string) ($row['full_name'] ?? ''));
                    $cccd     = trim((string) ($row['cccd'] ?? ''));
                    $key      = "companions.{$index}.full_name";

                    if ($fullName === '' || $cccd === '') {
                        throw ValidationException::withMessages([
                            $key => ['Không có ảnh CCCD thì phải nhập đủ họ tên và số CCCD.'],
                        ]);
                    }

                    $cccdData = [
                        'cccd'      => $cccd,
                        'full_name' => $fullName,
                        'dob'       => trim((string) ($row['dob'] ?? '')),
                        'gender'    => trim((string) ($row['gender'] ?? '')),
                        'address'   => trim((string) ($row['address'] ?? '')),
                        'source'    => 'manual',
                    ];

                    $this->assertNoCccdDuplicate($customer, $cccdData, null, $seenPeople, $index);
                    $seenPeople[$index] = $cccdData;

                    $created[] = $customer->companions()->create([
                        'full_name'  => $fullName,
                        'cccd_front' => null,
                        'cccd_back'  => null,
                        'cccd_data'  => $cccdData,
                    ]);
                }

                return $created;
            });
        } catch (\Throwable $e) {
            $intake->deleteImages($uploadedPaths);
            throw $e;
        }

        return response()->json([
            'companions' => collect($companions)->map(fn (CustomerCompanion $c) => $this->formatCompanion($c))->values(),
            // Kết quả quét/đối chứng theo index của companions gửi lên (chế độ ảnh).
            'cccd_check' => $checks,
        ], 201);
    }

    // POST /api/admin/customers/{customer_id}/companions/{id} (dùng POST thay PUT để hỗ trợ multipart)
    // Đồng bộ với POST .../companions (tạo mới): không nhận full_name qua payload — full_name luôn
    // tự động lấy từ QR. Gửi ảnh nào (qr_image/cccd_front/cccd_back) thay ảnh đó, bổ sung dần được;
    // quét + đối chứng với các ảnh vừa gửi; không đọc được QR thì GIỮ cccd_data/full_name cũ.
    public function update(Request $request, string $customerId, int $id): JsonResponse
    {
        $customer = $this->resolveCustomer($request, $customerId);

        if (! $customer) {
            return response()->json(['message' => 'Không tìm thấy khách hàng.'], 404);
        }

        $companion = $customer->companions()->find($id);

        if (! $companion) {
            return response()->json(['message' => 'Không tìm thấy khách đi cùng.'], 404);
        }

        $request->validate([
            'qr_image'   => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'cccd_front' => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'cccd_back'  => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        $fields = [];
        $check  = null;
        $files  = $this->companionFiles($request, '');

        if ($files) {
            $intake = app(CccdIntakeService::class);
            $this->assertNoImageDuplicate($customer, $files, $companion->id, [], null);

            $read  = $intake->readForAdmin($files);
            $check = ['checks' => $read['checks'], 'warnings' => $read['warnings']];

            $this->assertNoCccdDuplicate($customer, $read['data'], $companion->id);

            // Không xoá ảnh cũ — đơn đặt trước đây có thể còn trỏ thẳng vào file của companion.
            $fields = $intake->storeAdminImages($files);

            // Quét lỗi/không đọc được QR thì giữ nguyên cccd_data + full_name cũ.
            if ($read['data']) {
                $fields['cccd_data'] = $read['data'];
                if (! empty($read['data']['full_name'])) {
                    $fields['full_name'] = $read['data']['full_name'];
                }
            }
        }

        $companion->update($fields);

        return response()->json(['companion' => $this->formatCompanion($companion->fresh()), 'cccd_check' => $check]);
    }

    // DELETE /api/admin/customers/{customer_id}/companions/{id}
    public function destroy(Request $request, string $customerId, int $id): JsonResponse
    {
        $customer = $this->resolveCustomer($request, $customerId);

        if (! $customer) {
            return response()->json(['message' => 'Không tìm thấy khách hàng.'], 404);
        }

        $companion = $customer->companions()->find($id);

        if (! $companion) {
            return response()->json(['message' => 'Không tìm thấy khách đi cùng.'], 404);
        }

        $companion->delete();

        return response()->json(['message' => 'Đã xoá.']);
    }

    // Giới hạn khách hàng admin được phép thao tác companion — CÙNG quy ước với
    // CustomerController::visibleCustomersQuery() (chi nhánh gốc customer thuộc về phải nằm trong
    // User::rootProductCategoryIds() của admin; khách chưa gán chi nhánh nào thì ai cũng thấy được).
    // Trước đây Customer::find() KHÔNG có bước này — admin đối tác A vẫn tra được customer_id thuộc
    // đối tác B, xem/sửa/xoá luôn companion (CCCD) của khách đối tác khác.
    private function resolveCustomer(Request $request, string $customerId): ?Customer
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $query = Customer::query();

        if (! $user->isSuperAdmin()) {
            $rootIds = $user->rootProductCategoryIds();
            $query->where(function ($q) use ($rootIds) {
                $q->doesntHave('categories')
                    ->orWhereHas('categories', fn ($q2) => $q2->whereIn('categories.id', $rootIds ?: [-1]));
            });
        }

        return $query->find($customerId);
    }

    /**
     * Ảnh CCCD gửi cho 1 companion, map về tên cột: {prefix}qr_image → cccd_qr_image,
     * {prefix}cccd_front|cccd_back giữ nguyên. Chỉ trả những ảnh có gửi.
     *
     * @return array<string, \Illuminate\Http\UploadedFile>
     */
    private function companionFiles(Request $request, string $prefix): array
    {
        $keys = ['cccd_qr_image' => "{$prefix}qr_image", 'cccd_front' => "{$prefix}cccd_front", 'cccd_back' => "{$prefix}cccd_back"];

        return array_filter(array_map(fn ($key) => $request->hasFile($key) ? $request->file($key) : null, $keys));
    }

    // Chặn 1 người bị lưu trùng trong cùng hồ sơ khách hàng — vừa là chính khách hàng vừa là khách
    // đi cùng của chính họ, hoặc 2 companion là cùng 1 người. Cùng 1 người = trùng số CCCD, HOẶC
    // trùng họ tên + ngày sinh (CccdIdentity::samePerson). Không có dữ liệu (quét lỗi) thì bỏ qua.
    // $seenPeople: cccd_data đã xử lý TRONG CÙNG request batch (index => data).
    private function assertNoCccdDuplicate(
        Customer $customer,
        ?array $cccdData,
        ?int $excludeCompanionId = null,
        array $seenPeople = [],
        ?int $index = null,
    ): void {
        if (! $cccdData || (trim((string) ($cccdData['cccd'] ?? '')) === '' && trim((string) ($cccdData['full_name'] ?? '')) === '')) {
            return;
        }

        $key = $index === null ? 'cccd_qr_image' : "companions.{$index}.qr_image";
        $fail = fn (string $message) => throw new CccdIntakeException($message, CccdIntakeException::DUPLICATE, $key);

        if (is_array($customer->cccd_data) && $customer->cccd_data && CccdIdentity::samePerson($cccdData, $customer->cccd_data)) {
            $fail('Thông tin CCCD này trùng với CCCD của chính khách hàng — không thể vừa là khách chính vừa là khách đi cùng.');
        }

        foreach ($seenPeople as $seenIndex => $seen) {
            if (CccdIdentity::samePerson($cccdData, $seen)) {
                $fail('Thông tin CCCD này trùng với khách đi cùng thứ ' . ($seenIndex + 1) . ' trong cùng danh sách vừa gửi.');
            }
        }

        $duplicate = $customer->companions()
            ->when($excludeCompanionId, fn ($q) => $q->where('id', '!=', $excludeCompanionId))
            ->get()
            ->first(fn (CustomerCompanion $c) => is_array($c->cccd_data) && $c->cccd_data && CccdIdentity::samePerson($cccdData, $c->cccd_data));

        if ($duplicate) {
            $fail('Thông tin CCCD này đã được lưu cho khách đi cùng khác (' . ($duplicate->full_name ?: "#{$duplicate->id}") . ').');
        }
    }

    // Trùng ẢNH — so hash nội dung file (không phụ thuộc QR đọc được hay không): bất kỳ ảnh nào vừa
    // gửi trùng 1 ảnh đã lưu của companion khác, hoặc trùng ảnh của companion khác trong cùng batch.
    // $seenHashes: index => [hash...] đã xử lý TRONG CÙNG batch. Trả về hash các ảnh vừa check.
    private function assertNoImageDuplicate(
        Customer $customer,
        array $files,
        ?int $excludeCompanionId,
        array $seenHashes,
        ?int $index,
    ): array {
        $hashes = array_values(array_map(fn ($f) => hash_file('sha256', $f->getRealPath()), $files));
        $key    = $index === null ? 'cccd_qr_image' : "companions.{$index}.qr_image";

        foreach ($seenHashes as $seenIndex => $seen) {
            if (array_intersect($hashes, $seen)) {
                throw new CccdIntakeException('Ảnh CCCD này trùng với khách đi cùng thứ ' . ($seenIndex + 1) . ' trong cùng danh sách vừa gửi.', CccdIntakeException::DUPLICATE, $key);
            }
        }

        $duplicate = $customer->companions()
            ->when($excludeCompanionId, fn ($q) => $q->where('id', '!=', $excludeCompanionId))
            ->get()
            ->first(fn (CustomerCompanion $c) => array_intersect($hashes, $this->storedHashes($c)));

        if ($duplicate) {
            throw new CccdIntakeException('Ảnh CCCD này đã được lưu cho khách đi cùng khác (' . ($duplicate->full_name ?: "#{$duplicate->id}") . ').', CccdIntakeException::DUPLICATE, $key);
        }

        return $hashes;
    }

    private function storedHashes(CustomerCompanion $companion): array
    {
        $disk = Storage::disk('public');

        return collect([$companion->cccd_qr_image, $companion->cccd_front, $companion->cccd_back])
            ->filter(fn ($path) => $path && $disk->exists($path))
            ->map(fn ($path) => hash_file('sha256', $disk->path($path)))
            ->values()
            ->all();
    }

    private function formatCompanion(CustomerCompanion $companion): array
    {
        $data = collect($companion->toArray())->except(['cccd_qr_image', 'cccd_front', 'cccd_back'])->toArray();

        $urls = CccdIntakeService::imageUrls($companion);
        $data['cccd_qr_image_url'] = $urls['cccd_qr_image'];
        $data['cccd_front_url']    = $urls['cccd_front'];
        $data['cccd_back_url']     = $urls['cccd_back'];
        $data['cccd_valid']        = CccdIdentity::validate($companion->cccd_data, requireQr: false) === null;
        // Số lần companion này được gắn vào 1 đơn — chỉ có giá trị khi index() eager-load bằng
        // withCount('orderGuestCccds'); các nơi khác gọi formatCompanion() (store/update) không load
        // nên mặc định 0 (companion mới/vừa sửa chưa gắn đơn nào).
        $data['orders_count']   = $companion->order_guest_cccds_count ?? 0;

        return $data;
    }
}
