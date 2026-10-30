<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\User;
use App\Support\HolidayPrices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DANH MỤC NGÀY LỄ, TẾT (ngoài bảng giá) — khai báo 1 lần, rồi thêm vào bảng giá / bộ giá bằng holiday_id
 * (xem PriceBoardController: holiday_prices[].holiday_id). Bảng giá SAO CHÉP khoảng ngày lúc thêm.
 *
 *  - GET    /api/admin/holidays        danh sách ngày lễ dùng được (dùng chung + của đối tác); ?search= &is_active= &shared=
 *  - GET    /api/admin/holidays/{id}   chi tiết
 *  - POST   /api/admin/holidays        tạo (name, recurring, from, to, is_active?, sort_order?, note?)
 *  - PUT|PATCH /api/admin/holidays/{id} sửa
 *  - DELETE /api/admin/holidays/{id}   xoá (bảng giá đã thêm ngày lễ này không đổi)
 * from/to: "MM-DD" khi recurring (lặp hàng năm, được vắt qua năm mới), "YYYY-MM-DD" khi đúng 1 năm.
 * Ngày lễ DÙNG CHUNG (shared = true, do 365Home quản lý): đối tác chỉ xem + dùng. Super_admin chưa chọn đối tác
 * (X-Partner-Id) tạo ngày lễ dùng chung; tài khoản đối tác (hoặc super_admin đã chọn đối tác) tạo ngày lễ riêng của đối tác.
 */
class HolidayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => 'nullable|string|max:100', 'is_active' => 'nullable|boolean', 'shared' => 'nullable|boolean']);

        $rows = Holiday::query()->visibleTo($request->user())
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%' . $request->input('search') . '%'))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->has('shared'), fn ($q) => $request->boolean('shared') ? $q->whereNull('partner_id') : $q->whereNotNull('partner_id'))
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        return response()->json(['data' => $rows->map(fn (Holiday $h) => $h->toRow() + ['editable' => $h->editableBy($request->user())])->values()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $holiday = Holiday::query()->visibleTo($request->user())->find($id);

        return $holiday
            ? response()->json(['data' => $holiday->toRow() + ['editable' => $holiday->editableBy($request->user())]])
            : response()->json(['message' => 'Không tìm thấy ngày lễ.'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $this->validated($request, true);

        if ($errors = $this->rangeErrors($data)) {
            return response()->json(['message' => 'Dữ liệu không hợp lệ.', 'errors' => $errors], 422);
        }

        // is_active / sort_order có mặc định trong DB nhưng model mới tạo chưa nạp lại nên phải gán rõ, kẻo phản hồi báo is_active = false.
        $holiday = Holiday::create($this->attributes($data) + [
            'partner_id' => $user->seesAllPartners() ? null : ($user->partner_id ? (string) $user->partner_id : null),
            'is_active'  => true,
            'sort_order' => 0,
        ]);

        return response()->json(['data' => $holiday->toRow() + ['editable' => true]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user    = $request->user();
        $holiday = Holiday::query()->visibleTo($user)->find($id);

        if (! $holiday) {
            return response()->json(['message' => 'Không tìm thấy ngày lễ.'], 404);
        }

        if (! $holiday->editableBy($user)) {
            return response()->json(['message' => 'Ngày lễ dùng chung do 365Home quản lý, bạn chỉ xem và dùng được.'], 403);
        }

        $data = $this->validated($request, false);
        $merged = [
            'recurring' => $data['recurring'] ?? $holiday->recurring,
            'from'      => $data['from'] ?? $holiday->from_day,
            'to'        => $data['to'] ?? $holiday->to_day,
        ];

        if ($errors = $this->rangeErrors($merged)) {
            return response()->json(['message' => 'Dữ liệu không hợp lệ.', 'errors' => $errors], 422);
        }

        $holiday->update($this->attributes($data));

        return response()->json(['data' => $holiday->fresh()->toRow() + ['editable' => true]]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user    = $request->user();
        $holiday = Holiday::query()->visibleTo($user)->find($id);

        if (! $holiday) {
            return response()->json(['message' => 'Không tìm thấy ngày lễ.'], 404);
        }

        if (! $holiday->editableBy($user)) {
            return response()->json(['message' => 'Ngày lễ dùng chung do 365Home quản lý, bạn chỉ xem và dùng được.'], 403);
        }

        $holiday->delete();

        return response()->json(['message' => 'Đã xoá ngày lễ.']);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name'       => [$req, 'string', 'max:100'],
            'recurring'  => [$req, 'boolean'],
            'from'       => [$req, 'string', 'max:10'],
            'to'         => [$req, 'string', 'max:10'],
            'is_active'  => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0|max:65535',
            'note'       => 'sometimes|nullable|string|max:1000',
        ]);
    }

    /** @param array<string,mixed> $data */
    private function rangeErrors(array $data): array
    {
        return HolidayPrices::rangeErrors((bool) $data['recurring'], (string) $data['from'], (string) $data['to']);
    }

    /** @param array<string,mixed> $data */
    private function attributes(array $data): array
    {
        $map = ['from' => 'from_day', 'to' => 'to_day'];
        $out = [];

        foreach (['name', 'recurring', 'from', 'to', 'is_active', 'sort_order', 'note'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$map[$key] ?? $key] = $data[$key];
            }
        }

        return $out;
    }
}
