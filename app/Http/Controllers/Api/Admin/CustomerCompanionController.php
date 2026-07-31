<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerCompanion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Payment\App\Services\CccdScannerService;

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
        $customer = Customer::find($customerId);

        if (! $customer) {
            return response()->json(['message' => 'Không tìm thấy khách hàng.'], 404);
        }

        $companions = $customer->companions()
            ->orderByDesc('id')
            ->get()
            ->map(fn (CustomerCompanion $companion) => $this->formatCompanion($companion))
            ->values();

        return response()->json(['data' => $companions]);
    }

    // POST /api/admin/customers/{customer_id}/companions (multipart/form-data)
    // Thêm CÙNG LÚC nhiều companion — số lượng do FE quyết định dựa trên guest_count của đơn
    // (ví dụ guest_count=3 → gửi 3 companion trong 1 request thay vì gọi POST 3 lần riêng lẻ).
    // Payload: companions[{index}][cccd_front|cccd_back], index bắt đầu từ 0. Không nhận full_name
    // qua payload — full_name lấy tự động từ QR khi quét CCCD, sửa tay qua POST .../companions/{id}.
    // CCCD (2 mặt) là BẮT BUỘC cho từng companion — mục đích của companion là lưu sẵn CCCD đã quét,
    // không có ảnh thì không có gì để tái sử dụng ở lần đặt phòng sau.
    // Xử lý theo TRANSACTION — 1 companion lỗi (trùng CCCD, 2 mặt không khớp...) thì rollback toàn
    // bộ batch và xoá lại các ảnh đã upload trong request, tránh tạo dở dang nửa danh sách.
    public function store(Request $request, string $customerId): JsonResponse
    {
        $customer = Customer::find($customerId);

        if (! $customer) {
            return response()->json(['message' => 'Không tìm thấy khách hàng.'], 404);
        }

        $data = $request->validate([
            'companions'              => 'required|array|min:1',
            'companions.*.cccd_front' => 'required|file|mimes:jpg,jpeg,png,webp|max:10240',
            'companions.*.cccd_back'  => 'required|file|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        $uploadedPaths = [];

        try {
            $companions = DB::transaction(function () use ($request, $data, $customer, &$uploadedPaths) {
                $seenCccds = [];
                $created   = [];

                foreach (array_keys($data['companions']) as $index) {
                    $front = $request->file("companions.{$index}.cccd_front")->store('cccd', 'public');
                    $back  = $request->file("companions.{$index}.cccd_back")->store('cccd', 'public');
                    $uploadedPaths[] = $front;
                    $uploadedPaths[] = $back;

                    $this->assertSidesMatch($front, $back, $index);

                    $cccdData = null;
                    try {
                        $cccdData = app(CccdScannerService::class)->scanPaths($front, $back);
                    } catch (\Throwable $e) {
                        Log::warning('Admin API: quét CCCD khách đi cùng (companion) thất bại', [
                            'customer_id' => $customer->id,
                            'index'       => $index,
                            'error'       => $e->getMessage(),
                        ]);
                    }

                    $this->assertNoCccdDuplicate($customer, $cccdData, null, $seenCccds, $index);

                    $rowCccd = trim((string) ($cccdData['cccd'] ?? ''));
                    if ($rowCccd !== '') {
                        $seenCccds[$index] = $rowCccd;
                    }

                    // Không bắt buộc đọc được QR — quét lỗi vẫn tạo companion bình thường, cccd_data
                    // để trống, full_name lấy từ QR (không nhận qua payload) — admin sửa tay sau nếu
                    // cần (cùng nguyên tắc CCCD "tùy chọn" toàn hệ thống).
                    $created[] = $customer->companions()->create([
                        'full_name'  => $cccdData['full_name'] ?? null,
                        'cccd_front' => $front,
                        'cccd_back'  => $back,
                        'cccd_data'  => $cccdData,
                    ]);
                }

                return $created;
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($uploadedPaths);
            throw $e;
        }

        return response()->json([
            'companions' => collect($companions)->map(fn (CustomerCompanion $c) => $this->formatCompanion($c))->values(),
        ], 201);
    }

    // POST /api/admin/customers/{customer_id}/companions/{id} (dùng POST thay PUT để hỗ trợ multipart)
    // Ảnh CCCD chỉ được thay khi gửi ĐỦ CẢ 2 mặt cùng lúc — gửi thiếu 1 mặt thì ảnh cũ giữ nguyên
    // (tránh trường hợp chỉ đổi mặt trước làm mất luôn liên kết QR hợp lệ của mặt sau cũ).
    public function update(Request $request, string $customerId, int $id): JsonResponse
    {
        $customer = Customer::find($customerId);

        if (! $customer) {
            return response()->json(['message' => 'Không tìm thấy khách hàng.'], 404);
        }

        $companion = $customer->companions()->find($id);

        if (! $companion) {
            return response()->json(['message' => 'Không tìm thấy khách đi cùng.'], 404);
        }

        $data = $request->validate([
            'full_name'  => 'sometimes|nullable|string|max:255',
            'cccd_front' => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'cccd_back'  => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        $fields = collect($data)->only(['full_name'])->toArray();

        if ($request->hasFile('cccd_front') && $request->hasFile('cccd_back')) {
            $front = $request->file('cccd_front')->store('cccd', 'public');
            $back  = $request->file('cccd_back')->store('cccd', 'public');

            $this->assertSidesMatch($front, $back);

            $cccdData = null;
            try {
                $cccdData = app(CccdScannerService::class)->scanPaths($front, $back);
            } catch (\Throwable $e) {
                Log::warning('Admin API: quét lại CCCD khách đi cùng (companion) thất bại', [
                    'companion_id' => $companion->id,
                    'error'        => $e->getMessage(),
                ]);
            }

            $this->assertNoCccdDuplicate($customer, $cccdData, $companion->id);

            $fields['cccd_front'] = $front;
            $fields['cccd_back']  = $back;
            $fields['cccd_data']  = $cccdData;
        }

        $companion->update($fields);

        return response()->json(['companion' => $this->formatCompanion($companion->fresh())]);
    }

    // DELETE /api/admin/customers/{customer_id}/companions/{id}
    public function destroy(Request $request, string $customerId, int $id): JsonResponse
    {
        $customer = Customer::find($customerId);

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

    // Quét ĐỘC LẬP từng mặt (khác scanPaths() ở trên coi 2 ảnh là 1 "pool") để phát hiện trường
    // hợp admin/lễ tân lỡ chụp nhầm mặt trước của người này ghép với mặt sau của người khác — cùng
    // logic đã dùng ở luồng khách hàng tự đặt phòng (ProductDetail::confirmBooking()). Phát hiện
    // xung đột thì xoá luôn 2 file vừa lưu, không để rác lại trên storage.
    private function assertSidesMatch(string $frontPath, string $backPath, ?int $index = null): void
    {
        $frontAbs = Storage::disk('public')->path($frontPath);
        $backAbs  = Storage::disk('public')->path($backPath);

        if (app(CccdScannerService::class)->sidesConflict($frontAbs, $backAbs)) {
            // update() không track uploadedPaths như store() nên tự dọn ở đây; store() sẽ dọn lại
            // (an toàn, xoá 1 path đã xoá chỉ trả về false) trong catch của transaction.
            Storage::disk('public')->delete([$frontPath, $backPath]);

            $key = $index === null ? 'cccd_front' : "companions.{$index}.cccd_front";

            throw ValidationException::withMessages([
                $key => ['Ảnh mặt trước và mặt sau CCCD không khớp thông tin (có thể thuộc về 2 người khác nhau). Vui lòng chụp lại đúng CCCD.'],
            ]);
        }
    }

    // Chặn 1 người bị lưu trùng CCCD trong cùng hồ sơ khách hàng — vừa là chính khách hàng vừa là
    // khách đi cùng của chính họ, hoặc 2 companion khác nhau lại cùng 1 số CCCD (upload nhầm ảnh).
    // Chỉ so sánh khi quét ra được số CCCD — quét lỗi/không đọc được số thì bỏ qua check này (không
    // đủ dữ liệu để so, và CCCD vốn là thông tin "tùy chọn" trong toàn hệ thống).
    // $seenCccds: cccd đã xử lý TRONG CÙNG request batch (index => cccd) — chặn 2 companion mới
    // trong cùng lần gửi lại trùng nhau (upload nhầm ảnh 1 người vào 2 ô).
    private function assertNoCccdDuplicate(
        Customer $customer,
        ?array $cccdData,
        ?int $excludeCompanionId = null,
        array $seenCccds = [],
        ?int $index = null,
    ): void {
        $cccd = trim((string) ($cccdData['cccd'] ?? ''));

        if ($cccd === '') {
            return;
        }

        $key = $index === null ? 'cccd_front' : "companions.{$index}.cccd_front";

        $customerCccd = trim((string) ($customer->cccd_data['cccd'] ?? ''));

        if ($customerCccd !== '' && $customerCccd === $cccd) {
            throw ValidationException::withMessages([
                $key => ['Số CCCD này trùng với CCCD của chính khách hàng — không thể vừa là khách chính vừa là khách đi cùng.'],
            ]);
        }

        foreach ($seenCccds as $seenIndex => $seenCccd) {
            if ($seenCccd === $cccd) {
                throw ValidationException::withMessages([
                    $key => ["Số CCCD này trùng với khách đi cùng thứ " . ($seenIndex + 1) . ' trong cùng danh sách vừa gửi.'],
                ]);
            }
        }

        $duplicate = $customer->companions()
            ->when($excludeCompanionId, fn ($q) => $q->where('id', '!=', $excludeCompanionId))
            ->get()
            ->first(fn (CustomerCompanion $c) => trim((string) ($c->cccd_data['cccd'] ?? '')) === $cccd);

        if ($duplicate) {
            $name = $duplicate->full_name ?: "#{$duplicate->id}";
            throw ValidationException::withMessages([
                $key => ["Số CCCD này đã được lưu cho khách đi cùng khác ({$name})."],
            ]);
        }
    }

    private function formatCompanion(CustomerCompanion $companion): array
    {
        $data = $companion->toArray();

        $data['cccd_front_url'] = $companion->cccd_front ? Storage::disk('public')->url($companion->cccd_front) : null;
        $data['cccd_back_url']  = $companion->cccd_back  ? Storage::disk('public')->url($companion->cccd_back)  : null;

        return $data;
    }
}
