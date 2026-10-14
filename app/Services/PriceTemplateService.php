<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\PriceTemplate;
use Modules\Product\App\Models\PriceTemplateSlot;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\TimeSlot;

/**
 * Nghiệp vụ BẢNG GIÁ MẪU dùng chung cho API (PriceTemplateController) và admin web
 * (PriceTemplateResource): tách bản riêng khi đối tác sửa mẫu hệ thống, ghi lại danh sách khung giờ
 * của mẫu, áp mẫu xuống room_time_slots của phòng.
 */
class PriceTemplateService
{
    public function __construct(private PriceBoardSyncService $priceBoards)
    {
    }

    /** Bản riêng của đối tác tách từ mẫu hệ thống $source — dùng lại bản đã tách trước đó nếu có. */
    public function forkFor(PriceTemplate $source, string $partnerId): PriceTemplate
    {
        $existing = PriceTemplate::where('partner_id', $partnerId)
            ->where('source_template_id', $source->id)
            ->with('slots')
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($source, $partnerId) {
            $fork = PriceTemplate::create([
                'partner_id'         => $partnerId,
                'source_template_id' => $source->id,
                'name'               => $source->name,
                'note'               => $source->note,
                'is_active'          => true,
                'sort_order'         => $source->sort_order,
            ]);

            foreach ($source->slots()->get() as $slot) {
                $fork->slots()->create($slot->only(['timeslot_id', 'price', 'checkin', 'checkout', 'over_night', 'sort_order']));
            }

            return $fork->load('slots');
        });
    }

    /** Thay toàn bộ khung giờ của mẫu — thứ tự trong $slots = thứ tự hiển thị. */
    public function replaceSlots(PriceTemplate $template, array $slots): void
    {
        $template->slots()->delete();

        foreach (array_values($slots) as $index => $row) {
            $timeSlot = TimeSlot::find($row['timeslot_id']);

            PriceTemplateSlot::create([
                'price_template_id' => $template->id,
                'timeslot_id'       => $row['timeslot_id'],
                'price'             => $row['price'] ?? null,
                'checkin'           => $row['checkin'] ?? null,
                'checkout'          => $row['checkout'] ?? null,
                'over_night'        => $row['over_night'] ?? (bool) $timeSlot?->over_night,
                'sort_order'        => $index,
            ]);
        }
    }

    /** Mẫu hệ thống ($partnerId NULL) chỉ dùng khung giờ hệ thống; mẫu đối tác dùng thêm khung giờ
     *  riêng của chính đối tác đó. Trả về thông báo lỗi, hoặc null nếu hợp lệ. */
    public function foreignTimeSlotError(?string $partnerId, array $timeslotIds): ?string
    {
        $timeslotIds = array_values(array_unique(array_map('intval', $timeslotIds)));

        $usable  = TimeSlot::usableBy($partnerId)->whereIn('id', $timeslotIds)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $foreign = array_diff($timeslotIds, $usable);

        if (! $foreign) {
            return null;
        }

        return $partnerId === null
            ? 'Bảng giá mẫu hệ thống chỉ dùng được khung giờ hệ thống — khung giờ #' . implode(', #', $foreign) . ' là khung giờ riêng của đối tác.'
            : 'Khung giờ #' . implode(', #', $foreign) . ' không thuộc đối tác của bạn.';
    }

    /** Lý do không áp được $template cho $rooms (phòng theo ngày, khác đối tác...), null nếu hợp lệ. */
    public function applyError(PriceTemplate $template, Collection $rooms): ?string
    {
        if ($template->slots()->doesntExist()) {
            return 'Bảng giá mẫu chưa có khung giờ nào.';
        }

        $notTimeBased = $rooms->filter(fn (Product $room) => (int) ($room->styles ?? 1) !== 1);
        if ($notTimeBased->isNotEmpty()) {
            return 'Chỉ áp được cho phòng theo khung giờ (styles=1): ' . $notTimeBased->pluck('name')->implode(', ') . '.';
        }

        foreach ($rooms as $room) {
            if ($template->partner_id !== null && $template->partner_id !== $room->partner_id) {
                return "Bảng giá mẫu không thuộc đối tác của phòng {$room->name}.";
            }
        }

        return null;
    }

    /**
     * Copy khung giờ + giá của $template xuống room_time_slots của từng phòng. $replace = true thì
     * gỡ các khung giờ (tái sử dụng, không phải "ngày đặc biệt") không có trong mẫu, kèm ưu đãi/mã
     * giảm giá đang gắn. Khung giờ đã có được cập nhật tại chỗ (giữ id → giữ ưu đãi đã gắn).
     * Gọi applyError() trước.
     */
    public function apply(PriceTemplate $template, Collection $rooms, bool $replace): void
    {
        $slots       = $template->slots()->get();
        $timeslotIds = $slots->pluck('timeslot_id')->map(fn ($v) => (int) $v)->all();

        foreach ($rooms as $room) {
            $before = $this->priceBoards->snapshotPricing($room);

            DB::transaction(function () use ($room, $slots, $timeslotIds, $replace) {
                if ($replace) {
                    RoomTimeSlot::where('room_id', $room->id)
                        ->whereNull('date')
                        ->whereNotIn('timeslot_id', $timeslotIds)
                        ->whereHas('timeSlot', fn ($q) => $q->where(fn ($q2) => $q2->whereNull('type')->orWhere('type', '!=', 'date')))
                        ->get()
                        ->each(function (RoomTimeSlot $slot) {
                            $slot->promotions()->detach();
                            $slot->coupons()->detach();
                            $slot->delete();
                        });
                }

                foreach ($slots as $slot) {
                    RoomTimeSlot::updateOrCreate(
                        ['room_id' => $room->id, 'timeslot_id' => $slot->timeslot_id, 'date' => null],
                        [
                            'price'      => $slot->price,
                            'over_night' => $slot->over_night,
                            'checkin'    => $slot->checkin,
                            'checkout'   => $slot->checkout,
                            'status'     => 'available',
                        ]
                    );
                }
            });

            $this->priceBoards->afterManualPriceEdit($room, $before);
        }
    }
}
