<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\RoomCopyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

/**
 * Sao chép phòng (chỉ Super Admin) — cùng nghiệp vụ với nút "Sao chép" ở danh sách Phòng trên CMS, xem App\Services\RoomCopyService.
 *
 *  - GET  /api/admin/rooms/{id}/copy/preview?quantity=&start_number=  tên các phòng sẽ được tạo (để app hiện xem trước)
 *  - POST /api/admin/rooms/{id}/copy   body: quantity (1..50), start_number?   tạo N phòng mới từ phòng gốc
 */
class RoomCopyController extends Controller
{
    public function __construct(private readonly RoomCopyService $service) {}

    public function preview(Request $request, string $id): JsonResponse
    {
        $this->authorizeSuper($request);
        $source = $this->source($id);
        $data   = $this->validated($request);

        return response()->json(['data' => [
            'source'       => ['id' => $source->id, 'name' => $source->name],
            'quantity'     => $data['quantity'],
            'start_number' => $data['start_number'] ?? null,
            'names'        => $this->service->previewNames($source, $data['quantity'], $data['start_number'] ?? null),
        ]]);
    }

    public function copy(Request $request, string $id): JsonResponse
    {
        $this->authorizeSuper($request);
        $source = $this->source($id);
        $data   = $this->validated($request);

        // Mỗi đối tác chỉ một lượt sao chép cùng lúc — hai lượt song song sẽ chọn trùng số và trùng tên phòng.
        $lock = Cache::lock('room-copy:' . $source->partner_id, 300);

        if (! $lock->get()) {
            return response()->json(['message' => 'Đang có một lượt sao chép phòng khác của đối tác này, vui lòng thử lại sau ít phút.'], 409);
        }

        $names = $this->service->previewNames($source, $data['quantity'], $data['start_number'] ?? null);

        try {
            // Mỗi phòng chép file ảnh riêng: nhiều phòng có thể lâu hơn giới hạn thời gian mặc định của máy chủ.
            @set_time_limit(300);

            $copies = $this->service->copy($source, $data['quantity'], $data['start_number'] ?? null);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Sao chép phòng bị gián đoạn giữa chừng. Các phòng đã tạo được giữ lại — kiểm tra danh sách phòng rồi sao chép phần còn thiếu.',
                'created' => Product::withoutGlobalScopes()->where('partner_id', $source->partner_id)->whereIn('name', $names)->pluck('name')->values(),
            ], 500);
        } finally {
            $lock->release();
        }

        return response()->json([
            'message' => 'Đã sao chép ' . $copies->count() . ' phòng.',
            'data'    => [
                'source' => ['id' => $source->id, 'name' => $source->name],
                'copies' => $copies->map(fn (Product $room) => $this->row($room))->values(),
            ],
        ], 201);
    }

    private function source(string $id): Product
    {
        $source = Product::query()->find($id);

        abort_unless($source, 404, 'Không tìm thấy phòng.');

        return $source;
    }

    /** @return array{quantity: int, start_number?: int|null} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'quantity'     => 'required|integer|min:1|max:' . RoomCopyService::MAX_COPIES,
            'start_number' => 'nullable|integer|min:0|max:999999',
        ]);

        return ['quantity' => (int) $data['quantity'], 'start_number' => isset($data['start_number']) ? (int) $data['start_number'] : null];
    }

    private function row(Product $room): array
    {
        // load (không phải loadMissing): bản sao thừa hưởng quan hệ categories đã nạp ở phòng gốc chỉ với cột id.
        $room->load('categories:id,name');

        return [
            'id'              => $room->id,
            'name'            => $room->name,
            'slug'            => $room->slug,
            'partner_id'      => $room->partner_id,
            'status'          => (bool) $room->is_activated,
            'approval_status' => $room->approval_status ?? 'approved',
            'image_main'      => $room->getFirstMediaUrl('Ảnh bìa') ?: null,
            'categories'      => $room->categories->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name])->values(),
        ];
    }

    private function authorizeSuper(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được sao chép phòng.');
    }
}
