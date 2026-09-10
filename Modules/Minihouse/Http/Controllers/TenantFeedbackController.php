<?php

namespace Modules\Minihouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\TenantFeedback;

// Kênh phản hồi/đánh giá công khai cho khách thuê — KHÔNG cần đăng nhập (chưa có portal khách thuê
// riêng), truy cập qua link/QR gắn theo phòng (VD dán QR trong phòng, hoặc gửi kèm link qua Zalo lúc
// bàn giao phòng). withoutGlobalScopes() khi tra Room — route công khai không có bộ lọc toà nhà đang
// active (ActiveBuildingScope chỉ áp trong panel Filament) nên phải tự tra không qua scope.
class TenantFeedbackController extends Controller
{
    public function create(Request $request): View
    {
        $room = $request->integer('room')
            ? Room::withoutGlobalScopes()->find($request->integer('room'))
            : null;

        return view('minihouse::feedback.create', ['room' => $room]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'room_id'      => ['nullable', 'integer', 'exists:minihouse_rooms,id'],
            'tenant_name'  => ['nullable', 'string', 'max:255'],
            'tenant_phone' => ['nullable', 'string', 'max:20'],
            'rating'       => ['required', 'integer', 'min:1', 'max:5'],
            'content'      => ['nullable', 'string', 'max:2000'],
        ]);

        TenantFeedback::create($data);

        return redirect()
            ->route('minihouse.feedback.create', $request->only('room'))
            ->with('feedback_sent', true);
    }
}
