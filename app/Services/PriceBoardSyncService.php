<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\PriceBoardItem;
use Modules\Product\App\Models\PriceBoardPriceLog;
use Modules\Product\App\Models\PriceBoardTimeSlot;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

/**
 * Đồng bộ giữa "bảng giá" (price_boards/price_board_items) và dữ liệu giá thật mà toàn bộ pipeline
 * đặt phòng đang đọc (products/room_time_slots) — xem plan "Chuyển module Hệ thống giá thành module
 * Bảng giá": products/room_time_slots vẫn là nguồn duy nhất cho tính giá, bảng giá chỉ là lớp quản
 * trị ở trên, ghi đè xuống theo lịch rồi khôi phục lại khi hết hạn.
 *
 * Chỉ áp dụng cho phòng kiểu "Theo Khung Giờ" (styles=1, room_time_slots type=time) và các field giá
 * chung trên Product. KHÔNG đụng tới room_time_slots type=date (lịch "Ngày đặc biệt" của style=2) —
 * đó là tính năng lịch chi tiết riêng đã có sẵn ở SettingBook, không thuộc phạm vi bảng giá đặt tên.
 */
class PriceBoardSyncService
{
    /** Ghi $data (mảng 'items' hoặc 'product_ids' lấy từ form PriceBoardResource) xuống
     *  PriceBoardItem/PriceBoardTimeSlot — dùng chung cho Create/Edit VÀ cho các nút "Áp dụng hàng
     *  loạt" trong form (ghi thẳng xuống DB ngay khi đang sửa 1 bảng đã tồn tại, không phụ thuộc vào
     *  việc bấm "Lưu thay đổi" hay TableRepeater có tự vẽ lại đúng giá trị mới hay không — xem
     *  PriceBoardForm::applyBulkByPosition()/applyBulkByTimeslot() để biết lý do). */
    public function saveItems(PriceBoard $board, array $data): void
    {
        if ($board->isAdjustment()) {
            $this->saveProductIds($board, $data['product_ids'] ?? []);

            return;
        }

        $this->saveOverrideItems($board, $data['items'] ?? []);
    }

    public function saveProductIds(PriceBoard $board, array $productIds): void
    {
        $keepItemIds = [];

        foreach (array_filter($productIds) as $productId) {
            $item = PriceBoardItem::updateOrCreate(
                ['price_board_id' => $board->id, 'product_id' => $productId],
                []
            );

            if (! $board->is_default && $product = Product::find($productId)) {
                $this->captureBaselineIfMissing($item, $product);
            }

            $item->timeSlots()->delete();
            $keepItemIds[] = $item->id;
        }

        $board->items()->whereNotIn('id', $keepItemIds ?: [0])->get()->each(function (PriceBoardItem $item) {
            $item->timeSlots()->delete();
            $item->delete();
        });
    }

    public function saveOverrideItems(PriceBoard $board, array $items): void
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

            $rawRules  = $itemData['bulk_discount_rules'] ?? [];
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

            if (! $board->is_default) {
                $this->captureBaselineIfMissing($item, $product);
            }

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

    /**
     * Ghi (hoặc cập nhật) item của $product trong $board từ 1 bộ giá — dùng khi "sinh item" của v2.
     * $fields là các cột giá chung (price, full_booking_discount, bulk_discount_rules, room_config,
     * deposit_*, default_checkin/out), $slots là danh sách { timeslot_id, price, over_night, status }
     * (rỗng với bộ adjustment / phòng theo ngày). Chụp giá gốc 1 lần như saveOverrideItems().
     *
     * @param list<array{timeslot_id:int,price:int,over_night:bool,status:string}> $slots
     */
    public function upsertItem(PriceBoard $board, Product $product, array $fields, array $slots, ?int $sheetId): PriceBoardItem
    {
        $item = PriceBoardItem::updateOrCreate(
            ['price_board_id' => $board->id, 'product_id' => $product->id],
            $fields + ['sheet_id' => $sheetId]
        );

        if (! $board->is_default && ! $board->is_template) {
            $this->captureBaselineIfMissing($item, $product);
        }

        $item->timeSlots()->delete();

        foreach ($slots as $slot) {
            PriceBoardTimeSlot::create([
                'price_board_item_id' => $item->id,
                'timeslot_id'         => $slot['timeslot_id'],
                'price'               => $slot['price'],
                'over_night'          => $slot['over_night'] ?? false,
                'status'              => $slot['status'] ?? 'available',
            ]);
        }

        return $item;
    }

    /** Xoá các item của $board có id KHÔNG nằm trong $keepItemIds; trả về product_id đã bị gỡ. */
    public function pruneItems(PriceBoard $board, array $keepItemIds): array
    {
        $removed = [];

        $board->items()->whereNotIn('id', $keepItemIds ?: [0])->get()->each(function (PriceBoardItem $item) use (&$removed) {
            $removed[] = $item->product_id;
            $item->timeSlots()->delete();
            $item->delete();
        });

        return $removed;
    }

    /** Chụp "giá gốc" của $product vào ĐÚNG $item (bảng đặt tên) — CHỈ chụp 1 LẦN DUY NHẤT lúc item
     *  này mới được tạo (baseline_fields còn null), sau đó đóng băng vĩnh viễn dù "Hệ thống giá" có
     *  sửa gì tiếp — đúng yêu cầu "giá khôi phục = giá tại thời điểm tạo bảng khuyến mãi", KHÔNG phải
     *  giá mới nhất đang lưu ở "Bảng giá mặc định" (vốn liên tục cập nhật theo mỗi lần lưu). Item cũ
     *  tạo trước khi có cột này (baseline_fields null) cũng tự "chữa lành" ngay lần sửa kế tiếp — chấp
     *  nhận chụp trễ vì không thể biết lại giá TẠI ĐÚNG lúc tạo trong quá khứ. */
    private function captureBaselineIfMissing(PriceBoardItem $item, Product $product): void
    {
        if ($item->baseline_fields !== null) {
            return;
        }

        $snapshot = $this->resolveBaselineSnapshot($product);

        $item->update([
            'baseline_fields'     => $snapshot['fields'],
            'baseline_time_slots' => $snapshot['slots'],
        ]);
    }

    /** "Giá gốc thật" của $product NGAY LÚC NÀY — dùng để chụp baseline cho 1 bảng đặt tên MỚI. Ưu
     *  tiên đọc lại baseline ĐÃ ĐÓNG BĂNG của bảng đặt tên khác gần nhất (nếu có), thay vì đọc thẳng
     *  giá ĐANG CHẠY trên room_time_slots — vì giá đang chạy có thể đang bị 1 bảng khác ghi đè tạm
     *  thời (VD phòng đang trong đợt khuyến mãi A thì giá thật là giá của A, không phải giá gốc).
     *  Đọc thẳng giá đang chạy trong lúc đó sẽ "nhiễm" giá tạm của A vào baseline của bảng mới B, khiến
     *  sau này B hết hạn lại khôi phục nhầm về giá của A thay vì giá gốc thật trước khi có bảng nào cả.
     *  Chỉ khi CHƯA từng có bảng đặt tên nào khác chụp baseline cho phòng này mới đọc giá đang chạy
     *  (lúc đó chắc chắn không bị bảng nào khác ghi đè, giá đang chạy CHÍNH LÀ giá gốc thật). */
    private function resolveBaselineSnapshot(Product $product): array
    {
        $frozenItem = PriceBoardItem::where('product_id', $product->id)
            ->whereHas('priceBoard', fn ($q) => $q->where('is_default', false))
            ->whereNotNull('baseline_fields')
            ->latest('created_at')
            ->first();

        if ($frozenItem) {
            return [
                'fields' => $frozenItem->baseline_fields,
                'slots'  => $frozenItem->baseline_time_slots ?? [],
            ];
        }

        return $this->liveSnapshot($product);
    }

    /** Giá ĐANG CHẠY trên phòng ở dạng baseline (các field chung + khung giờ tái sử dụng). */
    private function liveSnapshot(Product $product): array
    {
        $slots = $product->roomTimeSlots()
            ->whereHas('timeSlot', fn ($q) => $this->scopeRecurring($q))
            ->get()
            ->groupBy('timeslot_id')
            ->map(fn ($group) => $group->sortBy('id')->last())
            ->values()
            ->map(fn (RoomTimeSlot $slot) => [
                'timeslot_id' => $slot->timeslot_id,
                'price'       => $slot->price,
                'checkin'     => $slot->checkin,
                'checkout'    => $slot->checkout,
                'over_night'  => $slot->over_night,
                'status'      => $slot->status,
            ])
            ->all();

        return [
            'fields' => $this->extractProductFields($product),
            'slots'  => $slots,
        ];
    }

    /** Sau khi đối tác SỬA GIÁ TAY: cập nhật "giá gốc" đã đóng băng của mọi bảng đặt tên chứa phòng
     *  này thành giá vừa sửa — nếu không, job `price-boards:sync-due` hằng đêm sẽ khôi phục phòng về
     *  giá gốc lúc tạo bảng và xoá mất giá vừa sửa. Bỏ qua khi đang có bảng hiệu lực đè lên phòng:
     *  lúc đó giá đang chạy là giá tạm của bảng, không phải giá thật. */
    private function refreshBaselines(Product $product): void
    {
        $items = PriceBoardItem::where('product_id', $product->id)
            ->whereHas('priceBoard', fn ($q) => $q->where('is_default', false))
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $today = now()->startOfDay();

        $covered = $items->contains(function (PriceBoardItem $item) use ($today) {
            $board = $item->priceBoard;

            return $board && $board->is_active && $board->coversDate($today);
        });

        if ($covered) {
            return;
        }

        $snapshot = $this->liveSnapshot($product);

        foreach ($items as $item) {
            $item->update([
                'baseline_fields'     => $snapshot['fields'],
                'baseline_time_slots' => $snapshot['slots'],
            ]);
        }
    }

    public function defaultBoard(): PriceBoard
    {
        return PriceBoard::firstOrCreate(
            ['is_default' => true],
            ['name' => 'Bảng giá mặc định', 'is_active' => true]
        );
    }

    /** Ghi lại trạng thái giá hiện tại của $product vào bảng mặc định — gọi mỗi khi admin lưu giá
     *  qua trang "Hệ thống giá" (SettingBook) để bảng mặc định luôn là ảnh chụp mới nhất, dùng làm
     *  điểm khôi phục khi 1 bảng giá đặt tên hết hạn. */
    public function seedDefaultBoard(Product $product): PriceBoardItem
    {
        $board = $this->defaultBoard();

        $item = PriceBoardItem::updateOrCreate(
            ['price_board_id' => $board->id, 'product_id' => $product->id],
            $this->extractProductFields($product)
        );

        $item->timeSlots()->delete();

        // groupBy+last: nếu room_time_slots lỡ có 2 dòng trùng timeslot_id (dữ liệu bẩn), chỉ mirror
        // đúng 1 dòng (mới nhất theo id) — tránh nhân bản trùng lặp lan sang bảng mặc định rồi tái
        // tạo lại y hệt mỗi lần khôi phục.
        $recurringSlots = $product->roomTimeSlots()
            ->whereHas('timeSlot', fn ($q) => $this->scopeRecurring($q))
            ->get()
            ->groupBy('timeslot_id')
            ->map(fn ($group) => $group->sortBy('id')->last());

        foreach ($recurringSlots as $slot) {
            PriceBoardTimeSlot::create([
                'price_board_item_id' => $item->id,
                'timeslot_id'         => $slot->timeslot_id,
                'price'               => $slot->price,
                'checkin'             => $slot->checkin,
                'checkout'            => $slot->checkout,
                'over_night'          => $slot->over_night,
                'status'              => $slot->status,
            ]);
        }

        $this->refreshBaselines($product);

        return $item->fresh('timeSlots');
    }

    /** Gọi SAU mỗi lần sửa giá trực tiếp ngoài trang "Hệ thống giá" (API admin sửa khung giờ/áp
     *  bảng giá mẫu...) — làm đúng như SettingBook::save(): cập nhật "Bảng giá mặc định" theo giá
     *  vừa lưu + ghi lịch sử nếu giá đổi. Bỏ bước này thì job `price-boards:sync-due` chạy nửa đêm sẽ
     *  ghi đè giá phòng về ảnh chụp CŨ của bảng mặc định, coi như mất thay đổi. $before = kết quả
     *  snapshotPricing($product) chụp TRƯỚC khi sửa. */
    public function afterManualPriceEdit(Product $product, array $before): void
    {
        $fresh = $product->fresh();

        $this->seedDefaultBoard($fresh);
        $this->logPriceChange($this->defaultBoard()->id, $fresh, $before, $this->snapshotPricing($fresh));
    }

    /**
     * Item THẮNG của phòng vào ngày $date trong các bảng đang bật (không phải mặc định / mẫu).
     *  - Giai đoạn 1: bảng có start_date mới hơn thắng, rồi id lớn hơn (luật v1).
     *  - Giai đoạn 2: priority cao hơn thắng; bằng nhau thì phạm vi hẹp hơn (rooms > branches > partner);
     *    vẫn bằng thì start_date mới hơn, rồi id lớn hơn. Bảng phải đúng thứ (days_of_week).
     */
    public function winningItem(string $productId, Carbon $date): ?PriceBoardItem
    {
        return $this->pickWinningItem($this->candidateItems($productId), $date);
    }

    /**
     * Mọi item của phòng nằm trong bảng đặt tên đang bật (bất kể ngày) — nạp 1 lần rồi dùng pickWinningItem() cho nhiều ngày.
     *
     * @return \Illuminate\Support\Collection<int, PriceBoardItem>
     */
    public function candidateItems(string $productId): \Illuminate\Support\Collection
    {
        return PriceBoardItem::where('product_id', $productId)
            ->whereHas('priceBoard', fn ($q) => $q->where('is_active', true)->where('is_default', false)->where('is_template', false))
            ->with(['timeSlots', 'priceBoard', 'sheet'])
            ->get();
    }

    /** Bảng thắng vào ngày $date trong số $candidates: đúng khoảng ngày, đúng thứ (giai đoạn 2), rồi priority → phạm vi hẹp hơn → bắt đầu muộn hơn → id lớn hơn. */
    public function pickWinningItem(\Illuminate\Support\Collection $candidates, Carbon $date): ?PriceBoardItem
    {
        $date  = $date->copy()->startOfDay();
        $phase = PriceBoard::phase2Enabled();

        return $candidates
            ->filter(function (PriceBoardItem $i) use ($date, $phase) {
                $board = $i->priceBoard;

                return $board
                    && (! $board->start_date || Carbon::parse($board->start_date)->startOfDay()->lte($date))
                    && (! $board->end_date || Carbon::parse($board->end_date)->startOfDay()->gte($date))
                    && (! $phase || $board->coversWeekday($date));
            })
            ->sortByDesc(fn (PriceBoardItem $i) => sprintf(
                '%03d-%d-%s-%010d',
                $phase ? (int) $i->priceBoard->priority : 0,
                $phase ? $i->priceBoard->scopeRank() : 0,
                $i->priceBoard->start_date ? Carbon::parse($i->priceBoard->start_date)->format('Y-m-d') : '9999-12-31',
                $i->priceBoard->id
            ))
            ->first();
    }

    /** Áp giá đang hiệu lực cho $product tại $date (mặc định hôm nay): tìm bảng đặt tên (không phải
     *  mặc định) đang active và có khoảng ngày phủ $date; nếu không có, khôi phục về bảng mặc định. */
    public function applyForProduct(Product $product, ?Carbon $date = null): void
    {
        $date = ($date ?? now())->copy()->startOfDay();

        // assertNoOverlap() phải chặn 2 bảng active trùng ngày cho cùng phòng ngay từ lúc lưu/kích
        // hoạt, nhưng vẫn lấy hết các item khớp (không ->first() ngay trên query) rồi tự chọn 1 bảng
        // theo tiêu chí ổn định — phòng khi có sai sót lọt qua (dữ liệu cũ, sửa tay DB...) thì kết quả
        // "bảng nào thắng" luôn nhất quán giữa các lần chạy, không phụ thuộc thứ tự ngẫu nhiên của DB.
        $item = $this->winningItem((string) $product->id, $date);

        if (! $item) {
            // Không còn bảng đặt tên nào đang active — ưu tiên khôi phục về giá gốc đã ĐÓNG BĂNG
            // ngay tại thời điểm tạo bảng đặt tên GẦN NHẤT của phòng này (baseline_fields/
            // baseline_time_slots, chụp 1 lần duy nhất, không đổi theo dù sau đó "Hệ thống giá" có
            // sửa gì tiếp) — thay vì luôn đọc "Bảng giá mặc định" vốn liên tục cập nhật theo lần lưu
            // MỚI NHẤT, không còn là giá gốc thật lúc tạo bảng khuyến mãi nữa.
            $frozenItem = PriceBoardItem::where('product_id', $product->id)
                ->whereHas('priceBoard', fn ($q) => $q->where('is_default', false))
                ->whereNotNull('baseline_fields')
                ->latest('created_at')
                ->first();

            if ($frozenItem) {
                $this->writeBaselineToProduct($frozenItem, $product);

                return;
            }
        }

        if (! $item) {
            $item = PriceBoardItem::where('price_board_id', $this->defaultBoard()->id)
                ->where('product_id', $product->id)
                ->with('timeSlots')
                ->first();
        }

        if (! $item) {
            return; // Chưa từng seed bảng mặc định cho phòng này — không có gì để áp.
        }

        $this->writeItemToProduct($item, $product);
    }

    /** Áp NGAY item của $board xuống các phòng gắn với nó, BẤT KỂ ngày hiệu lực/is_active — dùng
     *  cho nút "Áp dụng ngay" khi đối tác cần đổi giá tức thời dù chưa tới ngày. */
    public function applyBoard(PriceBoard $board): void
    {
        foreach ($board->items()->with(['timeSlots', 'product'])->get() as $item) {
            if ($item->product) {
                $this->writeItemToProduct($item, $item->product);
            }
        }
    }

    /**
     * Như applyBoard() nhưng chỉ các phòng trong $productIds (null = mọi phòng của bảng).
     *
     * @param list<string>|null $productIds
     * @return list<string> product_id đã áp
     */
    public function applyBoardTo(PriceBoard $board, ?array $productIds = null): array
    {
        $applied = [];

        $board->items()
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds ?: ['-']))
            ->with(['timeSlots', 'product', 'sheet'])
            ->get()
            ->each(function (PriceBoardItem $item) use (&$applied) {
                if ($item->product) {
                    $this->writeItemToProduct($item, $item->product);
                    $applied[] = (string) $item->product_id;
                }
            });

        return $applied;
    }

    /** Lấy danh sách phòng gắn với $board — gọi TRƯỚC khi DeleteAction thực sự xoá bản ghi (xem
     *  PriceBoardResource), vì xoá PriceBoard sẽ cascade xoá luôn PriceBoardItem, không còn cách nào
     *  biết bảng này từng gắn phòng nào nữa SAU khi đã xoá. */
    public function productsAffectedByBoard(PriceBoard $board): \Illuminate\Support\Collection
    {
        return $board->items()->with('product')->get()->pluck('product')->filter()->values();
    }

    /** Tính lại giá ĐÚNG cho từng phòng trong $products sau khi 1 bảng giá vừa bị xoá — gọi SAU khi
     *  DeleteAction thực sự xoá xong. CỐ Ý không tự ý ghi thẳng baseline của bảng vừa xoá xuống —
     *  trước đây làm vậy gây bug: nếu phòng đang có 1 bảng KHÁC đang active thật sự "thắng" (đúng giá
     *  hiện tại), xoá 1 bảng CŨ/hết hạn khác cho cùng phòng đó sẽ ghi đè nhầm về giá của bảng vừa xoá,
     *  làm mất giá đúng của bảng đang active. Gọi lại applyForProduct() — logic chọn "bảng nào thắng"
     *  y hệt lúc chạy nền — để tự động chọn đúng: bảng khác đang active (nếu có) vẫn thắng như cũ,
     *  chỉ khi THẬT SỰ không còn gì mới về đúng baseline gần nhất hoặc bảng mặc định. */
    public function resyncProductsAfterBoardDeleted(iterable $products): void
    {
        foreach ($products as $product) {
            $this->applyForProduct($product);
        }
    }

    /** Tính lại NGAY giá đang đúng phải áp cho từng phòng gắn với $board (áp nếu $board đang thắng,
     *  khôi phục bảng khác/mặc định nếu không) — gọi ngay sau khi tạo/sửa/bật/tắt 1 bảng để có hiệu
     *  lực tức thời, không phải chờ job `price-boards:sync-due` chạy lúc nửa đêm. */
    public function resyncBoardProducts(PriceBoard $board): void
    {
        foreach ($board->items()->with('product')->get() as $item) {
            if ($item->product) {
                $this->applyForProduct($item->product);
            }
        }
    }

    public function assertNoOverlap(PriceBoard $board, ?\Illuminate\Support\Collection $productIds = null): void
    {
        $conflicts = $this->overlapConflicts($board, $productIds);

        if (! $conflicts) {
            return;
        }

        $first = $conflicts[0];
        $count = collect($conflicts)->pluck('product_ids')->flatten()->unique()->count();

        throw new PriceBoardConflictException(
            "{$count} phòng đang nằm trong bảng \"{$first['board']->name}\" đang bật và trùng ngày.",
            array_map(fn (array $c) => [
                'board'       => ['id' => $c['board']->id, 'name' => $c['board']->name, 'priority' => 0],
                'product_ids' => array_values($c['product_ids']),
            ], $conflicts)
        );
    }

    /**
     * Các bảng ĐANG BẬT khác (không phải bảng mặc định/mẫu) trùng khoảng ngày với $board cho ít nhất 1
     * phòng trong $productIds. Mỗi phần tử: ['board' => PriceBoard, 'product_ids' => [...]].
     *
     * @return list<array{board: PriceBoard, product_ids: list<string>}>
     */
    public function overlapConflicts(PriceBoard $board, ?\Illuminate\Support\Collection $productIds = null): array
    {
        if ($board->is_default || $board->is_template) {
            return [];
        }

        $productIds = $productIds ?? $board->items()->pluck('product_id');
        if ($productIds->isEmpty()) {
            return [];
        }

        $others = PriceBoard::where('is_default', false)
            ->where('is_template', false)
            ->where('is_active', true)
            ->when($board->exists, fn ($q) => $q->where('id', '!=', $board->id))
            ->whereHas('items', fn ($q) => $q->whereIn('product_id', $productIds))
            ->get();

        $conflicts = [];

        foreach ($others as $other) {
            if (! $this->rangesOverlap($board, $other)) {
                continue;
            }

            // Giai đoạn 2: bảng ưu tiên cao hơn thắng nên chỉ chặn khi NGANG HÀNG (cùng priority + cùng
            // phạm vi) — không có luật phân xử nào khác, để không bao giờ có 2 bảng ngang nhau mà không
            // biết bảng nào thắng.
            if (PriceBoard::phase2Enabled()
                && ((int) $board->priority !== (int) $other->priority || $board->scopeRank() !== $other->scopeRank())) {
                continue;
            }

            $shared = $other->items()->whereIn('product_id', $productIds)->pluck('product_id')->all();

            $conflicts[] = ['board' => $other, 'product_ids' => $shared];
        }

        return $conflicts;
    }

    /** Nhãn các khung giờ của $product đang có đơn CHƯA TỚI NGÀY (đã trả tiền / đang giữ chỗ, chưa huỷ).
     *  order_items không lưu timeslot_id nên so theo nhãn khung (slot_label). */
    public function futureOrderLabels(Product $product): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.product_id', $product->id)
            ->whereNotNull('order_items.slot_label')
            ->where('order_items.checkin_datetime', '>=', now())
            ->whereIn('orders.status', ['paid', 'pending'])
            ->distinct()
            ->pluck('order_items.slot_label')
            ->map(fn ($l) => (string) $l)
            ->all();
    }

    /** Số đơn tương lai nằm trên các khung của phòng sẽ bị gỡ, theo từng khung — dùng cho slot_conflicts. */
    public function futureOrdersByLabel(Product $product, array $labels): array
    {
        if (! $labels) {
            return [];
        }

        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.product_id', $product->id)
            ->whereIn('order_items.slot_label', $labels)
            ->where('order_items.checkin_datetime', '>=', now())
            ->whereIn('orders.status', ['paid', 'pending'])
            ->select('order_items.slot_label as label')
            ->selectRaw('count(*) as n, max(' . DB::getTablePrefix() . 'order_items.checkin_date) as last_date')
            ->groupBy('order_items.slot_label')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->label => ['orders' => (int) $r->n, 'last_date' => (string) $r->last_date]])
            ->all();
    }

    /** "Khung giờ tái sử dụng" (type='time') KHÁC "ngày đặc biệt" (type='date') — dữ liệu cũ trước
     *  khi có cột `type` để lại nhiều TimeSlot với type=NULL, vẫn phải coi là khung giờ tái sử dụng
     *  (không phải ngày đặc biệt) chứ không được loại khỏi phạm vi đồng bộ của bảng giá. */
    private function scopeRecurring($query)
    {
        return $query->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'date'));
    }

    private function rangesOverlap(PriceBoard $a, PriceBoard $b): bool
    {
        $aStart = $a->start_date ? Carbon::parse($a->start_date) : Carbon::minValue();
        $aEnd   = $a->end_date ? Carbon::parse($a->end_date) : Carbon::maxValue();
        $bStart = $b->start_date ? Carbon::parse($b->start_date) : Carbon::minValue();
        $bEnd   = $b->end_date ? Carbon::parse($b->end_date) : Carbon::maxValue();

        if (! ($aStart->lte($bEnd) && $bStart->lte($aEnd))) {
            return false;
        }

        // Giai đoạn 2: hai bảng chỉ "trùng" khi có ít nhất 1 thứ chung (rỗng = mọi thứ).
        if (PriceBoard::phase2Enabled()) {
            $aDays = array_map('intval', (array) $a->days_of_week);
            $bDays = array_map('intval', (array) $b->days_of_week);

            if ($aDays && $bDays && ! array_intersect($aDays, $bDays)) {
                return false;
            }
        }

        return true;
    }

    private function writeItemToProduct(PriceBoardItem $item, Product $product): void
    {
        ['fields' => $fields, 'timeSlots' => $timeSlots] = $this->resolveEffectivePayload($item, $product);

        $normalizedSlots = collect($timeSlots)->map(fn ($slot) => [
            'timeslot_id' => $slot->timeslot_id,
            'price'       => $slot->price,
            'checkin'     => $slot->checkin,
            'checkout'    => $slot->checkout,
            'over_night'  => $slot->over_night,
            'status'      => $slot->status,
        ]);

        $this->applyFieldsAndSlotsToProduct($product, $fields, $normalizedSlots, $item->price_board_id, $item->price_board_id);
    }

    /** Ghi giá gốc đã ĐÓNG BĂNG (baseline_fields/baseline_time_slots của $frozenItem, chụp lúc tạo
     *  bảng đặt tên đó) xuống $product — dùng khi hết hạn TẤT CẢ bảng đặt tên, thay cho việc luôn đọc
     *  "Bảng giá mặc định" (xem applyForProduct()). synced_from_price_board_id = null vì đây không
     *  còn do bảng đặt tên nào "đang áp" nữa — chỉ còn lại đúng giá gốc của phòng. */
    private function writeBaselineToProduct(PriceBoardItem $frozenItem, Product $product): void
    {
        $fields = $frozenItem->baseline_fields ?? [];
        $slots  = collect($frozenItem->baseline_time_slots ?? []);

        $this->applyFieldsAndSlotsToProduct($product, $fields, $slots, null, $this->defaultBoard()->id);
    }

    /** Lõi dùng chung: ghi $fields (các cột giá chung) + $timeSlots (mảng chuẩn hoá
     *  timeslot_id/price/checkin/checkout/over_night/status) xuống $product/room_time_slots, rồi ghi
     *  1 dòng lịch sử nếu giá thật sự đổi. $logBoardId dùng để gắn dòng lịch sử vào đúng bảng giá
     *  (bảng đặt tên đang áp, hoặc "Bảng giá mặc định" khi khôi phục giá gốc). */
    private function applyFieldsAndSlotsToProduct(Product $product, array $fields, \Illuminate\Support\Collection $timeSlots, ?int $syncedFromBoardId, int $logBoardId): void
    {
        // Ghi giá của bảng đã được duyệt xuống phòng là việc của hệ thống, không phải đối tác tự sửa giá → không bị chặn duyệt (App\Services\ApprovalService).
        \App\Support\ApprovalContext::bypass(fn () => $this->doApplyFieldsAndSlotsToProduct($product, $fields, $timeSlots, $syncedFromBoardId, $logBoardId));
    }

    private function doApplyFieldsAndSlotsToProduct(Product $product, array $fields, \Illuminate\Support\Collection $timeSlots, ?int $syncedFromBoardId, int $logBoardId): void
    {
        $before = $this->snapshotPricing($product);

        $product->update($fields);

        // Nếu không có khung giờ nào (chưa từng được seed, hoặc bảng "override" lưu rỗng), TUYỆT ĐỐI
        // không xoá khung giờ đang có của phòng — xoá xong không tạo lại gì sẽ làm phòng mất hết khung
        // giờ đặt phòng. Giữ nguyên khung giờ hiện tại, chỉ áp các field giá chung ở trên.
        if ($timeSlots->isNotEmpty()) {
            $existingSlots = RoomTimeSlot::where('room_id', $product->id)
                ->whereHas('timeSlot', fn ($q) => $this->scopeRecurring($q))
                ->get()
                ->keyBy('timeslot_id');

            $targetTimeslotIds = $timeSlots->pluck('timeslot_id')->map(fn ($id) => (string) $id)->all();

            // Khung giờ nào KHÔNG còn nằm trong bảng mới mới thật sự bị xoá (gỡ khuyến mãi/mã giảm
            // giá trước khi xoá, vì bản ghi không còn tồn tại thì pivot trỏ vào id đó vô nghĩa).
            // Khung còn đơn chưa tới ngày thì GIỮ LẠI (đơn vẫn hợp lệ, lễ tân vẫn thấy); hết đơn thì lần
            // đồng bộ sau mới gỡ. Xem futureOrderLabels().
            $heldLabels = $this->futureOrderLabels($product);

            $existingSlots
                ->reject(fn (RoomTimeSlot $slot) => in_array((string) $slot->timeslot_id, $targetTimeslotIds, true))
                ->reject(fn (RoomTimeSlot $slot) => in_array((string) ($slot->timeSlot?->label ?? ''), $heldLabels, true))
                ->each(function (RoomTimeSlot $slot) {
                    $slot->promotions()->detach();
                    $slot->coupons()->detach();
                    $slot->delete();
                });

            foreach ($timeSlots as $boardSlot) {
                // Khung giờ đã tồn tại (cùng timeslot_id) thì UPDATE ngay trên đúng bản ghi cũ — giữ
                // nguyên id, giữ nguyên Khuyến mãi/Mã giảm giá đã gắn (promotions()/coupons() pivot
                // trỏ theo id này). Trước đây luôn xoá-tạo-lại MỌI khung giờ mỗi lần áp bảng giá (kể
                // cả job tự động chạy MỖI ĐÊM cho MỌI phòng) khiến id đổi liên tục, âm thầm gỡ sạch
                // mọi khuyến mãi đã gắn dù giá/khung giờ không hề đổi gì.
                $existing = $existingSlots->get($boardSlot['timeslot_id']);

                if ($existing) {
                    $existing->update([
                        'price'                      => $boardSlot['price'],
                        'checkin'                    => $boardSlot['checkin'],
                        'checkout'                   => $boardSlot['checkout'],
                        'over_night'                 => $boardSlot['over_night'],
                        'status'                     => $boardSlot['status'],
                        'synced_from_price_board_id' => $syncedFromBoardId,
                    ]);

                    continue;
                }

                RoomTimeSlot::create([
                    'room_id'                    => $product->id,
                    'timeslot_id'                => $boardSlot['timeslot_id'],
                    'price'                      => $boardSlot['price'],
                    'checkin'                    => $boardSlot['checkin'],
                    'checkout'                   => $boardSlot['checkout'],
                    'over_night'                 => $boardSlot['over_night'],
                    'status'                     => $boardSlot['status'],
                    'synced_from_price_board_id' => $syncedFromBoardId,
                ]);
            }
        }

        $after = $this->snapshotPricing($product->fresh());

        $this->logPriceChange($logBoardId, $product, $before, $after);
    }

    /** Chụp giá hiện tại của $product (giá/đêm + giá từng khung giờ tái sử dụng, theo tên khung
     *  giờ) — so sánh trước/sau mỗi lần đổi giá để biết CÓ THẬT SỰ đổi gì không, dùng cho popup
     *  "Lịch sử thay đổi giá" (không ghi log nếu giá không đổi, tránh nhiễu). Public để
     *  SettingBook::save() và "Sửa giá hàng loạt" (HasBookingHeaderActions) cũng gọi được — không
     *  chỉ riêng lúc áp bảng giá đặt tên. */
    public function snapshotPricing(Product $product): array
    {
        $slots = RoomTimeSlot::where('room_id', $product->id)
            ->whereHas('timeSlot', fn ($q) => $this->scopeRecurring($q))
            ->with('timeSlot')
            ->get()
            ->mapWithKeys(fn (RoomTimeSlot $slot) => [
                ($slot->timeSlot->label ?? (string) $slot->timeslot_id) => $slot->price === null ? null : (int) $slot->price,
            ])
            ->toArray();

        return [
            'price' => $product->price === null ? null : (float) $product->price,
            'slots' => $slots,
        ];
    }

    /** Ghi 1 dòng lịch sử NẾU giá thật sự đổi — dùng bảng riêng price_board_price_logs (không dùng
     *  AuditLog chung của hệ thống) vì AuditLogger::log() chỉ ghi khi có auth()->user(), trong khi
     *  phần lớn thay đổi giá ở đây chạy tự động qua lịch (price-boards:sync-due), không có ai đăng
     *  nhập — changed_by=null nghĩa là "hệ thống tự áp theo lịch". Public để log được cả những lần
     *  đổi giá KHÔNG qua bảng giá đặt tên (sửa tay ở "Hệ thống giá", "Sửa giá hàng loạt") — những lúc
     *  đó truyền $priceBoardId = defaultBoard()->id, gắn vào "Bảng giá mặc định" cho thống nhất. */
    public function logPriceChange(int $priceBoardId, Product $product, array $before, array $after): void
    {
        if ($before === $after) {
            return;
        }

        PriceBoardPriceLog::create([
            'price_board_id' => $priceBoardId,
            'product_id'     => $product->id,
            'old_price'      => $before['price'],
            'new_price'      => $after['price'],
            'old_slots'      => $before['slots'] ?: null,
            'new_slots'      => $after['slots'] ?: null,
            'changed_by'     => auth()->id(),
            'created_at'     => now(),
        ]);
    }

    /** 'override': dùng thẳng giá đã nhập trong $item. 'adjustment': lấy giá GỐC từ item của bảng
     *  mặc định (luôn là ảnh chụp mới nhất từ SettingBook) rồi cộng/trừ % hoặc số tiền — không cần nhập
     *  lại giá từng phòng. Kiểu + mức điều chỉnh lấy ở BỘ GIÁ của item (v2); item cũ chưa có bộ giá thì
     *  lấy ở cấp bảng (v1). adjustment_timeslot_ids (nếu có) giới hạn chỉ chỉnh các khung đó. */
    private function resolveEffectivePayload(PriceBoardItem $item, Product $product): array
    {
        $board = $item->priceBoard ?? $item->priceBoard()->first();
        $sheet = $item->sheet_id ? ($item->sheet ?? $item->sheet()->first()) : null;

        if ($sheet) {
            $isAdjustment = $sheet->isAdjustment();
            $type         = $sheet->adjustment_type;
            $value        = (float) $sheet->adjustment_value;
            $onlySlots    = collect((array) $sheet->adjustment_timeslot_ids)->map(fn ($v) => (int) $v)->all();
        } else {
            $isAdjustment = $board && $board->isAdjustment();
            $type         = $board?->adjustment_type;
            $value        = (float) ($board?->adjustment_value ?? 0);
            $onlySlots    = [];
        }

        if (! $isAdjustment) {
            return [
                'fields'    => $this->itemToProductFields($item),
                'timeSlots' => $item->timeSlots,
            ];
        }

        $baseline = PriceBoardItem::where('price_board_id', $this->defaultBoard()->id)
            ->where('product_id', $product->id)
            ->with('timeSlots')
            ->first();

        if (! $baseline) {
            return [
                'fields'    => $this->extractProductFields($product),
                'timeSlots' => collect(),
            ];
        }

        $fields = $this->itemToProductFields($baseline);

        // Phòng theo ngày: chỉnh giá/đêm. Phòng theo giờ: chỉnh giá từng khung (price bảng mặc định null).
        if ((int) ($product->styles ?? 1) === 2) {
            $fields['price'] = $this->applyAdjustment($fields['price'], $type, $value);
        }

        $timeSlots = $baseline->timeSlots->map(function (PriceBoardTimeSlot $slot) use ($type, $value, $onlySlots) {
            $adjusted = clone $slot;

            if (! $onlySlots || in_array((int) $slot->timeslot_id, $onlySlots, true)) {
                $adjusted->price = $this->applyAdjustment($slot->price, $type, $value);
            }

            return $adjusted;
        });

        return ['fields' => $fields, 'timeSlots' => $timeSlots];
    }

    /** Điều chỉnh giá, làm tròn 1.000đ sau khi tính và không bao giờ âm (âm → 0). */
    public function applyAdjustment(mixed $basePrice, ?string $type, float $value): ?int
    {
        if ($basePrice === null) {
            return null;
        }

        $price = $type === 'fixed'
            ? (float) $basePrice + $value
            : (float) $basePrice * (1 + $value / 100);

        return max(0, (int) (round($price / 1000) * 1000));
    }

    private function extractProductFields(Product $product): array
    {
        return [
            'price'                 => $product->price,
            'price_unit'            => $product->price_unit,
            'full_booking_discount' => $product->full_booking_discount,
            'bulk_discount_rules'   => $product->bulk_discount_rules,
            'room_config'           => $product->room_config,
            'deposit_1_night'       => $product->deposit_1_night,
            'deposit_multi_night'   => $product->deposit_multi_night,
            'deposit_min_nights'    => $product->deposit_min_nights,
            'default_checkin'       => $product->default_checkin,
            'default_checkout'      => $product->default_checkout,
        ];
    }

    private function itemToProductFields(PriceBoardItem $item): array
    {
        return [
            'price'                 => $item->price,
            'price_unit'            => $item->price_unit,
            'full_booking_discount' => $item->full_booking_discount,
            'bulk_discount_rules'   => $item->bulk_discount_rules,
            'room_config'           => $item->room_config,
            'deposit_1_night'       => $item->deposit_1_night,
            'deposit_multi_night'   => $item->deposit_multi_night,
            'deposit_min_nights'    => $item->deposit_min_nights,
            'default_checkin'       => $item->default_checkin,
            'default_checkout'      => $item->default_checkout,
        ];
    }
}
