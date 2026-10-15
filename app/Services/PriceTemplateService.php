<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\PriceTemplate;
use Modules\Product\App\Models\PriceTemplateSlot;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\TimeSlot;
use RuntimeException;

/**
 * Nghiệp vụ BẢNG GIÁ MẪU dùng chung cho API (PriceTemplateController) và admin web
 * (PriceTemplateResource): tách bản riêng khi đối tác sửa mẫu hệ thống, ghi lại danh sách khung giờ
 * của mẫu, và TẠO BẢNG GIÁ (price_boards) từ mẫu.
 *
 * Bảng giá mẫu chỉ là NGUỒN khung giờ + giá. Giá phòng chỉ đổi qua bảng giá (price_boards — có ngày
 * hiệu lực, bật/tắt, khôi phục khi hết hạn), nên chỉ có MỘT hệ thống đổi giá phòng.
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

    /** Mẫu đang dùng mà đối tác $partnerId chọn được (mẫu hệ thống + mẫu riêng), kèm khung giờ. */
    public function usableTemplate(?string $partnerId, int $id): ?PriceTemplate
    {
        return PriceTemplate::usableBy($partnerId)->where('is_active', true)->with('slots')->find($id);
    }

    /** Lý do không dùng được $template cho phòng $partnerId/$room (phòng theo ngày, mẫu rỗng, khung giờ
     *  không thuộc đối tác), null nếu hợp lệ. */
    public function applyError(PriceTemplate $template, ?string $partnerId, ?Product $room = null, ?array $timeslotIds = null): ?string
    {
        if ($template->slots->isEmpty()) {
            return 'Bảng giá mẫu chưa có khung giờ nào.';
        }

        // Khung giờ được chọn phải nằm trong mẫu.
        if ($timeslotIds !== null) {
            $outside = array_diff(array_map('intval', $timeslotIds), $template->slots->pluck('timeslot_id')->map(fn ($v) => (int) $v)->all());

            if ($outside) {
                return 'Khung giờ #' . implode(', #', $outside) . ' không nằm trong bảng giá mẫu đã chọn.';
            }
        }

        if ($room && (int) ($room->styles ?? 1) !== 1) {
            return 'Chỉ dùng được bảng giá mẫu cho phòng theo khung giờ (styles=1).';
        }

        return $this->foreignTimeSlotError($partnerId, $template->slots->pluck('timeslot_id')->all());
    }

    /**
     * THÊM THẲNG khung giờ + giá của mẫu vào phòng $room (dùng khi tạo/sửa phòng chọn bảng giá mẫu).
     * $replace = true: phòng chỉ còn đúng các khung giờ của mẫu (khung cũ không có trong mẫu bị gỡ kèm
     * ưu đãi/mã giảm giá đang gắn); false: chỉ thêm/cập nhật khung giờ có trong mẫu. Khung giờ đã có được
     * cập nhật tại chỗ (giữ id → giữ ưu đãi). Gọi applyError() trước.
     */
    public function applyToRoom(PriceTemplate $template, Product $room, bool $replace = true, ?array $timeslotIds = null): void
    {
        $slots = $template->slots()->get();

        // Chỉ thêm các khung giờ được chọn trong mẫu (null = cả mẫu).
        if ($timeslotIds !== null) {
            $wanted = array_map('intval', $timeslotIds);
            $slots  = $slots->filter(fn ($s) => in_array((int) $s->timeslot_id, $wanted, true))->values();
        }

        $keepIds = $slots->pluck('timeslot_id')->map(fn ($v) => (int) $v)->all();
        $before  = $this->priceBoards->snapshotPricing($room);

        DB::transaction(function () use ($room, $slots, $keepIds, $replace) {
            if ($replace) {
                RoomTimeSlot::where('room_id', $room->id)
                    ->whereNull('date')
                    ->whereNotIn('timeslot_id', $keepIds)
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

        // Cập nhật "Bảng giá mặc định" + giá gốc dùng để khôi phục — như lúc đối tác sửa giá tay.
        $this->priceBoards->afterManualPriceEdit($room->fresh(), $before);
    }

    /**
     * TẠO BẢNG GIÁ từ mẫu $template cho chi nhánh + các phòng đã chọn: mỗi phòng nhận đúng các khung
     * giờ + giá của mẫu (điều kiện giảm giá/phụ thu giữ theo phòng). Bảng thuộc đối tác của $user.
     *
     * $data: branch_id (bắt buộc), product_ids[] (bắt buộc, phòng theo khung giờ của chi nhánh),
     *        name, note, start_date, end_date, is_active (mặc định true).
     *
     * @throws RuntimeException nội dung là thông báo cho người dùng (HTTP 422)
     */
    public function createBoard(PriceTemplate $template, User $user, array $data): PriceBoard
    {
        if ($user->seesAllPartners() || empty($user->partner_id)) {
            throw new RuntimeException('Chọn đối tác (header X-Partner-Id) để tạo bảng giá từ mẫu.');
        }

        $partnerId = (string) $user->partner_id;

        if ($template->partner_id !== null && $template->partner_id !== $partnerId) {
            throw new RuntimeException('Bảng giá mẫu không thuộc đối tác của bạn.');
        }

        $slots = $template->slots()->get();

        if ($slots->isEmpty()) {
            throw new RuntimeException('Bảng giá mẫu chưa có khung giờ nào.');
        }

        // Khung giờ của mẫu phải dùng được với đối tác (mẫu hệ thống → khung hệ thống).
        if ($error = $this->foreignTimeSlotError($partnerId, $slots->pluck('timeslot_id')->all())) {
            throw new RuntimeException($error);
        }

        // Chi nhánh: thuộc đối tác (và chi nhánh nhân viên được giao).
        $allowed = $user->allowedCategoryIds();
        $branch  = Category::withoutGlobalScopes()
            ->whereKey((int) ($data['branch_id'] ?? 0))
            ->whereNull('parent_id')
            ->where('category_type', 'product')
            ->whereNull('deleted_at')
            ->where('partner_id', $partnerId)
            ->when(! empty($allowed), fn ($q) => $q->whereIn('id', $allowed))
            ->first();

        if (! $branch) {
            throw new RuntimeException('Chi nhánh không thuộc đối tác của bạn.');
        }

        // Phòng: thuộc chi nhánh, theo khung giờ (styles=1).
        $ids   = array_values(array_unique(array_map('strval', $data['product_ids'] ?? [])));
        $rooms = Product::query()
            ->whereIn('id', $ids)
            ->where('partner_id', $partnerId)
            ->where('styles', 1)
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', PriceBoard::branchCategoryIds((int) $branch->id)))
            ->get();

        if (! $ids || $rooms->count() !== count($ids)) {
            $missing = array_diff($ids, $rooms->pluck('id')->map(fn ($v) => (string) $v)->all());

            throw new RuntimeException($ids
                ? 'Phòng không thuộc chi nhánh, hoặc không phải phòng theo khung giờ: ' . implode(', ', $missing) . '.'
                : 'Chọn ít nhất 1 phòng.');
        }

        $board = new PriceBoard([
            'partner_id'   => $partnerId,
            'category_id'  => $branch->id,
            'name'         => $data['name'] ?? $template->name,
            'note'         => $data['note'] ?? null,
            'start_date'   => $data['start_date'] ?? null,
            'end_date'     => $data['end_date'] ?? null,
            'is_active'    => $data['is_active'] ?? true,
            'is_default'   => false,
            'pricing_mode' => PriceBoard::MODE_OVERRIDE,
        ]);

        $this->priceBoards->assertNoOverlap($board, $rooms->pluck('id'));

        return DB::transaction(function () use ($board, $rooms, $slots) {
            $board->save();

            $items = $rooms->map(function (Product $room) use ($slots) {
                $config = (array) $room->room_config;

                return [
                    'product_id'                  => $room->id,
                    'full_booking_discount'       => $room->full_booking_discount,
                    'bulk_discount_rules'         => $room->bulk_discount_rules,
                    'room_config_max_free_guests' => $config['max_free_guests'] ?? 2,
                    'room_config_extra_guest_fee' => $config['extra_guest_fee'] ?? 0,
                    'roomTimeSlots'               => $slots->filter(fn (PriceTemplateSlot $s) => $s->price !== null)->map(fn (PriceTemplateSlot $s) => [
                        'timeslot_id' => $s->timeslot_id,
                        'price'       => (int) $s->price,
                        'over_night'  => (bool) $s->over_night,
                        'status'      => 'available',
                    ])->all(),
                ];
            })->all();

            $this->priceBoards->saveItems($board, ['items' => $items]);
            $this->priceBoards->resyncBoardProducts($board);

            return $board->fresh('items.timeSlots');
        });
    }
}
