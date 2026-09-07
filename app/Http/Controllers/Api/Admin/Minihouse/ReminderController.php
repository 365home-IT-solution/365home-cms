<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Reminder;
use Modules\Minihouse\App\Models\Room;

// Nhắc việc. room_id/contract_id ĐỀU nullable — nhắc việc CHUNG (không gắn phòng/hợp đồng nào, VD
// "Đóng thuế quý") hiện được cho MỌI tài khoản MiniHouse, không giới hạn theo toà — chỉ nhắc việc
// THỰC SỰ gắn 1 phòng/hợp đồng mới bị lọc theo toà được quản lý (mirror đúng Reminder::booted()).
class ReminderController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/reminders?is_done=&type=&per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_reminders')) {
            return response()->json(['message' => 'Không có quyền xem nhắc việc.'], 403);
        }

        $permitted = $this->permittedBuildingIds($request);

        $reminders = Reminder::query()
            ->with(['room:id,code,building_id', 'contract.room:id,code,building_id'])
            ->where(fn ($q) => $q
                ->where(fn ($q2) => $q2->whereNull('room_id')->whereNull('contract_id'))
                ->orWhereHas('room', fn ($q2) => $q2->whereIn('building_id', $permitted))
                ->orWhereHas('contract.room', fn ($q2) => $q2->whereIn('building_id', $permitted)))
            ->when($request->filled('is_done'), fn ($q) => $q->where('is_done', $request->boolean('is_done')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->orderBy('remind_date')
            ->paginate((int) $request->integer('per_page', 20));

        $reminders->getCollection()->transform(fn (Reminder $r) => $this->toItem($r));

        return response()->json($reminders);
    }

    // POST /api/admin/minihouse/reminders
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_reminders')) {
            return response()->json(['message' => 'Không có quyền tạo nhắc việc.'], 403);
        }

        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'content'     => 'nullable|string',
            'remind_date' => 'required|date',
            'type'        => ['required', Rule::in([Reminder::TYPE_PAYMENT, Reminder::TYPE_CONTRACT, Reminder::TYPE_MAINTENANCE, Reminder::TYPE_OTHER])],
            'room_id'     => 'nullable|integer|exists:minihouse_rooms,id',
            'contract_id' => 'nullable|integer|exists:minihouse_contracts,id',
        ]);

        if (! empty($data['room_id'])) {
            $room = Room::withoutGlobalScopes()->find($data['room_id']);

            if (! $room || ! $this->isBuildingAllowed($request, $room->building_id)) {
                return response()->json(['message' => 'Không có quyền tạo nhắc việc cho phòng của toà nhà này.'], 403);
            }
        }

        if (! empty($data['contract_id'])) {
            $contract = Contract::withoutGlobalScopes()->with('room')->find($data['contract_id']);

            if (! $contract || ! $this->isBuildingAllowed($request, $contract->room?->building_id)) {
                return response()->json(['message' => 'Không có quyền tạo nhắc việc cho hợp đồng này.'], 403);
            }
        }

        $reminder = Reminder::create($data);

        return response()->json(['data' => $this->toItem($reminder->fresh(['room', 'contract.room']))], 201);
    }

    // PUT/PATCH /api/admin/minihouse/reminders/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_reminders')) {
            return response()->json(['message' => 'Không có quyền sửa nhắc việc.'], 403);
        }

        $reminder = $this->findAllowed($request, $id);

        if (! $reminder) {
            return response()->json(['message' => 'Không tìm thấy nhắc việc.'], 404);
        }

        $data = $request->validate([
            'title'       => 'sometimes|required|string|max:255',
            'content'     => 'nullable|string',
            'remind_date' => 'sometimes|required|date',
            'type'        => ['sometimes', Rule::in([Reminder::TYPE_PAYMENT, Reminder::TYPE_CONTRACT, Reminder::TYPE_MAINTENANCE, Reminder::TYPE_OTHER])],
            'is_done'     => 'nullable|boolean',
        ]);

        $reminder->update($data);

        return response()->json(['data' => $this->toItem($reminder->fresh(['room', 'contract.room']))]);
    }

    // DELETE /api/admin/minihouse/reminders/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_reminders')) {
            return response()->json(['message' => 'Không có quyền xoá nhắc việc.'], 403);
        }

        $reminder = $this->findAllowed($request, $id);

        if (! $reminder) {
            return response()->json(['message' => 'Không tìm thấy nhắc việc.'], 404);
        }

        $reminder->delete();

        return response()->json(['message' => 'Đã xoá nhắc việc.']);
    }

    private function findAllowed(Request $request, int $id): ?Reminder
    {
        $reminder = Reminder::with(['room', 'contract.room'])->find($id);

        if (! $reminder) {
            return null;
        }

        if (! $reminder->room_id && ! $reminder->contract_id) {
            return $reminder;
        }

        $buildingId = $reminder->room?->building_id ?? $reminder->contract?->room?->building_id;

        return $this->isBuildingAllowed($request, $buildingId) ? $reminder : null;
    }

    private function toItem(Reminder $reminder): array
    {
        return [
            'id'          => $reminder->id,
            'title'       => $reminder->title,
            'content'     => $reminder->content,
            'remind_date' => $reminder->remind_date?->toDateString(),
            'type'        => $reminder->type,
            'room_id'     => $reminder->room_id,
            'room_code'   => $reminder->room?->code,
            'contract_id' => $reminder->contract_id,
            'is_done'     => $reminder->is_done,
        ];
    }
}
