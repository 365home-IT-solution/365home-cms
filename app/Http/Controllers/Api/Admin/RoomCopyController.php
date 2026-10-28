<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\RoomCopyException;
use App\Http\Controllers\Controller;
use App\Services\RoomCopyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

/**
 * Sao chép phòng (chỉ Super Admin) — cùng nghiệp vụ với nút "Sao chép" ở danh sách Phòng trên CMS, xem App\Services\RoomCopyService.
 *
 *  - GET  /api/admin/rooms/{id}/copy/preview?quantity=&start_number=  tên các phòng sẽ được tạo (để app hiện xem trước)
 *        + taken_names: tên đã có phòng dùng (khác rỗng thì POST sẽ bị từ chối 422)
 *  - POST /api/admin/rooms/{id}/copy   body: quantity (1..50), start_number?   tạo N phòng mới từ phòng gốc
 *        lỗi: 409 room_copy_busy | 422 room_copy_name_taken (names) | 500 room_copy_interrupted (created)
 */
class RoomCopyController extends Controller
{
    public function __construct(private readonly RoomCopyService $service) {}

    public function preview(Request $request, string $id): JsonResponse
    {
        $this->authorizeSuper($request);
        $source = $this->source($id);
        $data   = $this->validated($request);
        $names  = $this->service->previewNames($source, $data['quantity'], $data['start_number'] ?? null);

        return response()->json(['data' => [
            'source'       => ['id' => $source->id, 'name' => $source->name],
            'quantity'     => $data['quantity'],
            'start_number' => $data['start_number'] ?? null,
            'names'        => $names,
            'taken_names'  => $this->service->takenNames($source, $names),
        ]]);
    }

    public function copy(Request $request, string $id): JsonResponse
    {
        $this->authorizeSuper($request);
        $source = $this->source($id);
        $data   = $this->validated($request);

        try {
            $copies = $this->service->copy($source, $data['quantity'], $data['start_number'] ?? null);
        } catch (RoomCopyException $e) {
            return response()->json(array_filter([
                'message' => $e->getMessage(),
                'code'    => $e->errorCode,
                'names'   => $e->errorCode === RoomCopyException::NAME_TAKEN ? $e->names : null,
                'created' => $e->errorCode === RoomCopyException::INTERRUPTED ? $e->names : null,
            ], fn ($v) => $v !== null), $e->status);
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
