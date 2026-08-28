<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources\PriceBoardResource\Concerns;

use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\PriceBoardItem;
use Modules\Product\App\Models\PriceBoardTimeSlot;
use Modules\Product\App\Models\Product;

/**
 * Xử lý dữ liệu phòng áp dụng của form PriceBoardResource thành các dòng PriceBoardItem/
 * PriceBoardTimeSlot — dùng chung cho Create/Edit, cùng cách xử lý thủ công SettingBook::save()
 * đang dùng cho products/room_time_slots (không dùng Repeater::relationship() vì cần lồng thêm
 * TableRepeater khung giờ theo từng phòng).
 *
 * Bảng kiểu 'adjustment' chỉ cần danh sách product_id (không nhập giá từng phòng) — giá được
 * PriceBoardSyncService tự tính từ bảng mặc định + %/số tiền điều chỉnh mỗi lần áp dụng.
 */
trait SavesPriceBoardItems
{
    private function savePriceBoardItems(PriceBoard $board, array $data): void
    {
        if ($board->isAdjustment()) {
            $this->savePriceBoardProductIds($board, $data['product_ids'] ?? []);

            return;
        }

        $this->savePriceBoardOverrideItems($board, $data['items'] ?? []);
    }

    private function savePriceBoardProductIds(PriceBoard $board, array $productIds): void
    {
        $keepItemIds = [];

        foreach (array_filter($productIds) as $productId) {
            $item = PriceBoardItem::updateOrCreate(
                ['price_board_id' => $board->id, 'product_id' => $productId],
                []
            );

            $item->timeSlots()->delete();
            $keepItemIds[] = $item->id;
        }

        $board->items()->whereNotIn('id', $keepItemIds ?: [0])->get()->each(function (PriceBoardItem $item) {
            $item->timeSlots()->delete();
            $item->delete();
        });
    }

    private function savePriceBoardOverrideItems(PriceBoard $board, array $items): void
    {
        $keepItemIds = [];

        foreach ($items as $itemData) {
            if (empty($itemData['product_id'])) {
                continue;
            }

            $product = Product::find($itemData['product_id']);
            if (! $product) {
                continue;
            }

            $style = (int) ($product->styles ?? 1);

            $rawRules = $itemData['bulk_discount_rules'] ?? [];
            $bulkRules = collect($rawRules)
                ->filter(fn ($r) => isset($r['slots'], $r['discount']))
                ->map(fn ($r) => ['slots' => (int) $r['slots'], 'discount' => (float) $r['discount']])
                ->sortBy('slots')
                ->values()
                ->toArray();

            $fields = [
                'full_booking_discount' => $itemData['full_booking_discount'] ?? null,
                'bulk_discount_rules'   => $bulkRules ?: null,
                'room_config'           => [
                    'max_free_guests' => (int) ($itemData['room_config_max_free_guests'] ?? 2),
                    'extra_guest_fee' => (int) preg_replace('/[^0-9]/', '', (string) ($itemData['room_config_extra_guest_fee'] ?? '0')),
                ],
            ];

            if ($style === 2) {
                $fields['price']               = $itemData['price'] ?? null;
                $fields['default_checkin']     = $itemData['default_checkin'] ?? '14:00';
                $fields['default_checkout']    = $itemData['default_checkout'] ?? '12:00';
                $fields['deposit_min_nights']  = (int) ($itemData['deposit_min_nights'] ?? 2);
                $fields['deposit_multi_night'] = (int) ($itemData['deposit_multi_night'] ?? 50);
                $fields['deposit_1_night']     = 100;
            }

            $item = PriceBoardItem::updateOrCreate(
                ['price_board_id' => $board->id, 'product_id' => $product->id],
                $fields
            );

            $keepItemIds[] = $item->id;

            $item->timeSlots()->delete();

            if ($style === 1) {
                foreach ($itemData['roomTimeSlots'] ?? [] as $slot) {
                    if (empty($slot['timeslot_id']) || $slot['price'] === null || $slot['price'] === '') {
                        continue;
                    }

                    PriceBoardTimeSlot::create([
                        'price_board_item_id' => $item->id,
                        'timeslot_id'          => $slot['timeslot_id'],
                        'price'                => (int) str_replace(['.', ','], '', (string) $slot['price']),
                        'over_night'           => $slot['over_night'] ?? false,
                        'status'               => $slot['status'] ?? 'available',
                    ]);
                }
            }
        }

        $board->items()->whereNotIn('id', $keepItemIds ?: [0])->get()->each(function (PriceBoardItem $item) {
            $item->timeSlots()->delete();
            $item->delete();
        });
    }
}
