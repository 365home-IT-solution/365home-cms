<?php

declare(strict_types=1);

namespace App\Services\PriceBoards;

use App\Services\PriceBoardSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\PriceBoardSheet;
use Modules\Product\App\Models\PriceBoardSheetProduct;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

/**
 * CHỌN BẢNG GIÁ KHI TẠO / SỬA PHÒNG (thay cho — hoặc song song với — chọn bảng giá mẫu).
 *
 * Phòng nhận khung giờ + giá (hoặc giá/đêm) của một BỘ GIÁ làm giá gốc ngay lúc lưu, đồng thời được
 * GẮN vào bảng giá đó (gán đích danh vào bộ giá đã chọn) để từ đó giá đi theo bảng: hiệu lực theo ngày,
 * ưu tiên, sửa bảng là phòng đổi theo. Bảng đang tắt / chưa tới ngày vẫn cho chọn — phòng có giá gốc để
 * bán được, bảng chỉ ghi đè khi tới hiệu lực.
 *
 * Chỉ nhận bộ giá kiểu override (bộ adjustment không có giá cụ thể để làm giá gốc).
 */
class RoomPriceBoardPicker
{
    public function __construct(
        private PriceBoardV2Service $v2,
        private PriceBoardSyncService $sync,
    ) {
    }

    public function usableBoard(?string $partnerId, int $id): ?PriceBoard
    {
        if (! $partnerId) {
            return null;
        }

        return PriceBoard::query()
            ->where('partner_id', $partnerId)
            ->where('is_default', false)
            ->where('is_template', false)
            ->with(['sheets.slots.timeslot', 'scopeBranches'])
            ->find($id);
    }

    /**
     * Danh sách để chọn trong màn tạo phòng: bảng của đối tác bao chi nhánh $branchId (nếu có) và có ít nhất
     * một bộ override đúng loại phòng $style (nếu có).
     *
     * @return Collection<int, PriceBoard>
     */
    public function options(string $partnerId, ?int $branchId = null, ?int $style = null): Collection
    {
        return PriceBoard::query()
            ->where('partner_id', $partnerId)
            ->where('is_default', false)
            ->where('is_template', false)
            ->with(['sheets.slots.timeslot', 'scopeBranches'])
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (PriceBoard $b) => $this->coversBranch($b, $branchId))
            ->each(function (PriceBoard $b) use ($style) {
                $b->setRelation('sheets', $b->sheets
                    ->filter(fn (PriceBoardSheet $s) => $this->eligible($s, $style))
                    ->values());
            })
            ->filter(fn (PriceBoard $b) => $b->sheets->isNotEmpty())
            ->values();
    }

    /** Bộ giá hợp lệ làm giá gốc cho phòng loại $style (null = bất kỳ). */
    public function eligible(PriceBoardSheet $sheet, ?int $style): bool
    {
        if ($sheet->isAdjustment()) {
            return false;
        }

        if ($style !== null && (int) $sheet->room_style !== $style) {
            return false;
        }

        return (int) $sheet->room_style === 2 || $sheet->slots->isNotEmpty();
    }

    /** Bộ giá được chọn: đúng sheet_id, hoặc bộ mặc định override cùng loại phòng. */
    public function resolveSheet(PriceBoard $board, int $style, ?int $sheetId): ?PriceBoardSheet
    {
        if ($sheetId) {
            return $board->sheets->firstWhere('id', $sheetId);
        }

        return $board->sheets->first(fn (PriceBoardSheet $s) => $s->is_default && $this->eligible($s, $style));
    }

    /**
     * Lý do không dùng được bảng cho phòng, null nếu hợp lệ.
     *
     * @param list<int> $categoryIds chi nhánh / danh mục của phòng
     */
    public function error(PriceBoard $board, ?PriceBoardSheet $sheet, int $style, array $categoryIds, ?Product $room = null): ?string
    {
        if (! $sheet) {
            return 'Bảng giá chưa có bộ giá phù hợp với loại phòng này (chọn sheet_id hoặc đặt bộ mặc định).';
        }

        if ((int) $sheet->price_board_id !== (int) $board->id) {
            return 'Bộ giá không thuộc bảng giá đã chọn.';
        }

        if ($sheet->isAdjustment()) {
            return 'Bộ giá kiểu điều chỉnh không có giá cụ thể — chọn bộ giá kiểu thay thế giá.';
        }

        if ((int) $sheet->room_style !== $style) {
            return $style === 1
                ? 'Bộ giá này dành cho phòng theo ngày, không dùng cho phòng theo khung giờ.'
                : 'Bộ giá này dành cho phòng theo khung giờ, không dùng cho phòng theo ngày.';
        }

        if ($style === 1 && $sheet->slots->isEmpty()) {
            return 'Bộ giá chưa có khung giờ nào.';
        }

        if ($board->scope === PriceBoard::SCOPE_BRANCHES) {
            $scoped = $board->scopeBranches->pluck('id')
                ->flatMap(fn ($id) => PriceBoard::branchCategoryIds((int) $id))
                ->all();

            if ($categoryIds && ! array_intersect(array_map('intval', $categoryIds), $scoped)) {
                return 'Bảng giá không áp dụng cho chi nhánh của phòng này.';
            }
        }

        if ($room && $board->is_active && ($conflicts = $this->sync->overlapConflicts($board, collect([(string) $room->id])))) {
            return 'Phòng đang thuộc bảng giá "' . $conflicts[0]['board']->name . '" đang bật và trùng ngày — tắt bảng đó hoặc đổi khoảng ngày.';
        }

        return null;
    }

    /**
     * Ghi giá gốc từ bộ giá xuống phòng rồi gắn phòng vào bảng. Gọi error() trước.
     */
    public function apply(PriceBoard $board, PriceBoardSheet $sheet, Product $room): void
    {
        $before = $this->sync->snapshotPricing($room);

        DB::transaction(function () use ($board, $sheet, $room) {
            $this->writeBase($sheet, $room);

            // Mỗi phòng chỉ nằm trong MỘT bộ của cùng bảng (ràng buộc 3.2).
            PriceBoardSheetProduct::query()
                ->where('product_id', $room->id)
                ->whereIn('sheet_id', $board->sheets->pluck('id'))
                ->where('sheet_id', '!=', $sheet->id)
                ->delete();

            PriceBoardSheetProduct::query()->firstOrCreate(['sheet_id' => $sheet->id, 'product_id' => (string) $room->id]);

            if ($board->scope === PriceBoard::SCOPE_ROOMS) {
                $board->declaredRooms()->firstOrCreate(['product_id' => (string) $room->id]);
            }
        });

        $this->sync->afterManualPriceEdit($room->fresh(), $before);
        $this->v2->rematerialize($board->fresh(), [(string) $room->id]);
    }

    private function writeBase(PriceBoardSheet $sheet, Product $room): void
    {
        if ((int) ($room->styles ?? 1) === 2) {
            $room->forceFill([
                'price'               => $sheet->price,
                'default_checkin'     => $sheet->default_checkin ?: '14:00',
                'default_checkout'    => $sheet->default_checkout ?: '12:00',
                'deposit_min_nights'  => $sheet->deposit_min_nights ?? 2,
                'deposit_multi_night' => $sheet->deposit_multi_night ?? 50,
            ])->save();
        } else {
            $keep = $sheet->slots->pluck('timeslot_id')->map(fn ($v) => (int) $v)->all();

            RoomTimeSlot::where('room_id', $room->id)
                ->whereNull('date')
                ->whereNotIn('timeslot_id', $keep)
                ->whereHas('timeSlot', fn ($q) => $q->where(fn ($q2) => $q2->whereNull('type')->orWhere('type', '!=', 'date')))
                ->get()
                ->each(function (RoomTimeSlot $slot) {
                    $slot->promotions()->detach();
                    $slot->coupons()->detach();
                    $slot->delete();
                });

            foreach ($sheet->slots as $slot) {
                RoomTimeSlot::updateOrCreate(
                    ['room_id' => $room->id, 'timeslot_id' => $slot->timeslot_id, 'date' => null],
                    ['price' => $slot->price, 'over_night' => (bool) $slot->over_night, 'status' => 'available']
                );
            }
        }

        $extra = [];

        foreach (['full_booking_discount', 'bulk_discount_rules', 'room_config'] as $field) {
            if ($sheet->declares($field)) {
                $extra[$field] = $sheet->{$field};
            }
        }

        if ($extra) {
            $room->forceFill($extra)->save();
        }
    }

    private function coversBranch(PriceBoard $board, ?int $branchId): bool
    {
        if ($branchId === null || $board->scope === PriceBoard::SCOPE_PARTNER || $board->scope === PriceBoard::SCOPE_ROOMS) {
            return true;
        }

        $scoped = $board->scopeBranches->pluck('id')
            ->flatMap(fn ($id) => PriceBoard::branchCategoryIds((int) $id))
            ->all();

        return in_array($branchId, array_map('intval', $scoped), true);
    }
}
