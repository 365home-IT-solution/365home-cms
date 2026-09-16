<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\PriceBoardSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

/**
 * Bản API của nút "Sửa giá hàng loạt" trong Filament
 * (Modules\Book\App\Filament\Traits\HasBookingHeaderActions::applyBulkPriceUpdate()) — GHI THẲNG
 * price mới xuống products.price (phòng styles=2) hoặc room_time_slots.price (phòng styles=1) NGAY
 * khi gọi, KHÔNG qua bảng giá nào. Cùng logic tính giá (percent/fixed, chọn khung giờ theo vị trí hay
 * theo timeslot_id cụ thể) và cùng bước ghi lịch sử (seedDefaultBoard()+logPriceChange()) như bản
 * Filament, để 2 đường không lệch nhau.
 *
 * Quyền: CHỈ super_admin — action này trong Filament bị ẩn HẲN với mọi tài khoản khác (xem
 * SettingBook::getHeaderActions(), $hiddenActions), không có quyền Shield nào để mở, vì ghi giá thẳng
 * xuống hệ thống cho NHIỀU phòng cùng lúc, không xem trước trước khi áp.
 */
class BulkPriceController extends Controller
{
    /**
     * POST /api/admin/rooms/bulk-price
     *
     * Body:
     *  - room_style : 1 (Theo khung giờ) | 2 (Theo ngày) — bắt buộc
     *  - room_ids   : mảng id phòng (phải cùng styles với room_style, chỉ áp phòng is_activated=true)
     *  - apply_mode : 'price' (giá cụ thể, VNĐ) | 'percent' (điều chỉnh % trên giá đang có)
     *  - value      : BẮT BUỘC khi room_style=2 — giá mới (apply_mode=price) hoặc mức % (+/-)
     *    (apply_mode=percent) áp cho TẤT CẢ phòng đã chọn
     *  - slot_rules : BẮT BUỘC khi room_style=1 — mảng { pick_mode, position, position_n, timeslot_id,
     *    value }, mỗi dòng đổi giá 1 khung giờ (chọn theo 'position': first/last/nth VỚI position_n,
     *    hoặc 'specific': đúng timeslot_id) — CÙNG áp cho TẤT CẢ phòng đã chọn theo đúng vị trí/khung
     *    giờ đó, không phải mỗi phòng 1 giá riêng (đúng như UI Filament)
     *
     * Response: { "updated_count": n, "rooms": [ { "room_id", "room_name", "touched": bool } ] } —
     * phòng không có khung giờ nào khớp slot_rules (room_style=1) thì "touched": false, không tính vào
     * updated_count, KHÔNG báo lỗi (mirror hành vi `continue` của bản Filament).
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            return response()->json(['message' => 'Không có quyền thực hiện thao tác này.'], 403);
        }

        $data = $request->validate([
            'room_style'                    => 'required|in:1,2',
            'room_ids'                      => 'required|array|min:1',
            'room_ids.*'                    => 'string|exists:products,id',
            'apply_mode'                    => 'required|in:price,percent',

            'value'                         => 'required_if:room_style,2|nullable|numeric',

            'slot_rules'                    => 'required_if:room_style,1|nullable|array|min:1',
            'slot_rules.*.pick_mode'        => 'nullable|in:position,specific',
            'slot_rules.*.position'         => 'required_if:slot_rules.*.pick_mode,position|nullable|in:first,last,nth',
            'slot_rules.*.position_n'       => 'required_if:slot_rules.*.position,nth|nullable|integer|min:1',
            'slot_rules.*.timeslot_id'      => 'required_if:slot_rules.*.pick_mode,specific|nullable|integer|exists:time_slots,id',
            'slot_rules.*.value'            => 'required|numeric',
        ]);

        $style     = (int) $data['room_style'];
        $mode      = $data['apply_mode'];
        $slotRules = collect($data['slot_rules'] ?? [])
            ->filter(fn ($rule) => ($rule['value'] ?? null) !== null && $rule['value'] !== '')
            ->values();

        $rooms = Product::where('is_activated', true)
            ->where('styles', $style)
            ->whereIn('id', $data['room_ids'])
            ->get();

        $service = app(PriceBoardSyncService::class);
        $results = [];
        $updated = 0;

        foreach ($rooms as $room) {
            $before  = $service->snapshotPricing($room);
            $touched = false;

            if ($style === 2) {
                $value   = (float) $data['value'];
                $current = (float) $room->price;
                $new     = $mode === 'percent' ? (int) round($current * (1 + $value / 100)) : (int) round($value);
                $room->update(['price' => $new]);
                $touched = true;
            } else {
                $slots = RoomTimeSlot::where('room_id', $room->id)
                    ->whereHas('timeSlot', fn ($q) => $q->where(fn ($q2) => $q2->whereNull('type')->orWhere('type', '!=', 'date')))
                    ->with('timeSlot')
                    ->get()
                    ->sortBy(fn ($s) => $s->timeSlot?->start_time ?? '99:99:99')
                    ->values();

                foreach ($slotRules as $rule) {
                    $target = ($rule['pick_mode'] ?? 'position') === 'specific'
                        ? $slots->firstWhere('timeslot_id', $rule['timeslot_id'] ?? null)
                        : $slots->get(match ($rule['position'] ?? 'first') {
                            'last'  => $slots->count() - 1,
                            'nth'   => ((int) ($rule['position_n'] ?? 1)) - 1,
                            default => 0,
                        });

                    if (! $target) {
                        continue;
                    }

                    $value   = (float) $rule['value'];
                    $current = (float) $target->price;
                    $new     = $mode === 'percent' ? (int) round($current * (1 + $value / 100)) : (int) round($value);
                    $target->update(['price' => $new]);
                    $touched = true;
                }
            }

            if ($touched) {
                $freshRoom = $room->fresh();
                $service->seedDefaultBoard($freshRoom);
                $service->logPriceChange($service->defaultBoard()->id, $freshRoom, $before, $service->snapshotPricing($freshRoom));
                $updated++;
            }

            $results[] = ['room_id' => $room->id, 'room_name' => $room->name, 'touched' => $touched];
        }

        return response()->json(['updated_count' => $updated, 'rooms' => $results]);
    }
}
