<?php

namespace Modules\Book\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;
use App\Services\SlotRealtimeService;
use Modules\Category\Entities\Categorizable;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Filament\Notifications\Notification;
use Carbon\Carbon;

class BlockTimeslotModal extends Component
{
    // ---- Form fields ----
    public int|string|null $product_id = null;
    public array $timeslot_ids = [];
    public ?string $date_from = null;
    public ?string $date_to = null;

    // ---- State ----
    public bool $showModal = false;
    public bool $isStyle2  = false;

    // true khi modal được mở sẵn cho 1 phòng cụ thể (từ menu ⋮ trên thẻ phòng ở Dashboard) — ẩn
    // dropdown "Chọn phòng" vì đã biết trước, xem openForProduct().
    public bool $lockedToProduct = false;

    // ---- Confirmation state ----
    public bool   $showConfirmClear      = false;
    public bool   $showConfirmBulkDelete = false;
    public ?int   $pendingRangeIndex = null;
    public ?array $pendingDateItem   = null;

    // ---- Chọn nhiều mục trong danh sách đã tô đen để xóa hàng loạt ----
    // Style1: key = "{rts_id}|{date}"; Style2: key = "{index}" (dạng chuỗi).
    public array $selectedBlockedKeys = [];

    // ---- Options ----
    public array $branchOptions   = [];
    public array $roomOptions     = [];
    public array $timeslotOptions = [];

    // Toàn bộ phòng (id/name/branch_id) nạp 1 lần, đẩy xuống Alpine để lọc theo chi nhánh NGAY
    // trên trình duyệt — chọn chi nhánh không cần round-trip Livewire nên không phải chờ.
    public array $allRooms = [];

    // ---- Danh sách blocked hiển thị ----
    // [ ['rts_id' => X, 'label' => '...', 'date' => 'YYYY-MM-DD', 'date_display' => 'dd/mm/yyyy'], ... ]
    public array $blockedList = [];

    public function mount(): void
    {
        $this->branchOptions = $this->buildBranchOptions();
        $this->allRooms      = $this->buildAllRooms();
        $this->roomOptions   = collect($this->allRooms)->pluck('name', 'id')->toArray();
    }

    // Chi nhánh = Category gốc (parent_id null, category_type = product), giống cách Dashboard
    // build bộ lọc chi nhánh — xem Modules\Dashboard\App\Filament\Pages\Dashboard::mount()/render().
    //
    // Dùng User::rootProductCategoryIds() (nguồn chuẩn, cùng nguồn với AuthController::
    // branchCategories()) thay vì allowedCategoryIds() — allowedCategoryIds() CHỈ dựa vào bảng cấp
    // quyền chi nhánh cụ thể (UserBranchPermission), không tự lọc theo partner_id. Chủ đối tác mặc
    // định KHÔNG có bản ghi cấp quyền chi nhánh nào (không bị giới hạn theo branch — xem docblock
    // rootProductCategoryIds()), nên trước đây $allowedIds rỗng → bỏ qua lọc → dropdown hiện TOÀN
    // BỘ chi nhánh của MỌI đối tác thay vì chỉ đối tác của họ (Category không dùng trait
    // BelongsToPartner nên không có global scope tự lọc partner_id như Product).
    private function buildBranchOptions(): array
    {
        $user = auth()->user();

        $query = \Modules\Category\Entities\Category::whereNull('parent_id')
            ->where('category_type', 'product')
            ->orderBy('name');

        if ($user && ! $user->isSuperAdmin()) {
            $query->whereIn('id', $user->rootProductCategoryIds());
        }

        return $query->pluck('name', 'id')->toArray();
    }

    // Nạp toàn bộ phòng kèm branch_id đã resolve sẵn (parent category, giống cách
    // RoomCardsService xác định chi nhánh của phòng) — Alpine dùng mảng này để lọc theo chi nhánh
    // ngay trên trình duyệt, không cần gọi lại server mỗi lần đổi chi nhánh.
    private function buildAllRooms(): array
    {
        $user  = auth()->user();
        $query = Product::where('is_activated', true)->with('categories.parent');

        if ($user && ! $user->isSuperAdmin()) {
            $categoryIds = $user->allowedCategoryIds();
            if (! empty($categoryIds)) {
                $allowedIds = Categorizable::where('categorizable_type', Product::class)
                    ->whereIn('category_id', $categoryIds)
                    ->distinct()
                    ->pluck('categorizable_id');
                $query->whereIn('id', $allowedIds);
            }
            // Chưa gán quyền chi nhánh cụ thể thì không thu hẹp thêm — Product đã tự lọc theo
            // partner_id (BelongsToPartner).
        }

        return $query->get()->map(function (Product $product) {
            $category = $product->categories->first();
            $parent   = $category?->parent;

            return [
                'id'        => $product->id,
                'name'      => $product->name,
                'branch_id' => $parent?->id ?? $category?->id ?? 0,
            ];
        })->values()->all();
    }

    // Called from Alpine via $wire.resetModal() when button is clicked — modal visibility
    // is handled client-side by Alpine, so we only need to reset the form state here.
    public function resetModal(): void
    {
        $this->reset([
            'product_id', 'timeslot_ids', 'date_from', 'date_to',
            'blockedList', 'timeslotOptions', 'isStyle2', 'lockedToProduct',
            'showConfirmClear', 'showConfirmBulkDelete', 'pendingRangeIndex', 'pendingDateItem',
            'selectedBlockedKeys',
        ]);
    }

    // Kept for any Livewire callers that still dispatch the event server-side.
    #[On('open-block-timeslot-modal')]
    public function openModal(): void
    {
        $this->resetModal();
        $this->showModal = true;
    }

    // Mở sẵn modal cho 1 phòng cụ thể — gọi từ menu ⋮ trên thẻ phòng ở Dashboard (dispatch event
    // 'open-block-timeslot-modal-for-room' kèm productId, xem rcOpenRoomMenu() trong
    // _scripts.blade.php). Khác openModal() ở chỗ KHÔNG để trống product_id, tự load luôn khung
    // giờ/danh sách đang khóa của đúng phòng đó — admin không cần chọn lại từ đầu.
    #[On('open-block-timeslot-modal-for-room')]
    public function openForProduct($productId): void
    {
        $this->resetModal();
        $this->lockedToProduct = true;
        $this->product_id      = $productId;
        $this->updatedProductId();
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    // ---- Xác nhận Filament-style ----

    public function cancelConfirm(): void
    {
        $this->showConfirmClear      = false;
        $this->showConfirmBulkDelete = false;
        $this->pendingRangeIndex     = null;
        $this->pendingDateItem       = null;
    }

    public function confirmClear(): void
    {
        $this->pendingRangeIndex     = null;
        $this->pendingDateItem       = null;
        $this->showConfirmBulkDelete = false;
        $this->showConfirmClear      = true;
    }

    // Xóa nhiều mục đã tô đen/khóa cùng lúc — chọn qua checkbox trong danh sách bên phải.
    public function confirmDeleteSelected(): void
    {
        if (empty($this->selectedBlockedKeys)) return;

        $this->showConfirmClear  = false;
        $this->pendingRangeIndex = null;
        $this->pendingDateItem   = null;
        $this->showConfirmBulkDelete = true;
    }

    // Tick/untick tất cả các dòng đang hiển thị trong danh sách đã tô đen/khóa.
    public function toggleSelectAllBlocked(): void
    {
        if (count($this->selectedBlockedKeys) >= count($this->blockedList) && ! empty($this->blockedList)) {
            $this->selectedBlockedKeys = [];
            return;
        }

        $this->selectedBlockedKeys = $this->isStyle2
            ? collect($this->blockedList)->map(fn ($item) => (string) $item['index'])->values()->all()
            : collect($this->blockedList)->map(fn ($item) => $item['rts_id'] . '|' . $item['date'])->values()->all();
    }

    public function confirmDeleteRange(int $index): void
    {
        $this->showConfirmClear      = false;
        $this->showConfirmBulkDelete = false;
        $this->pendingDateItem       = null;
        $this->pendingRangeIndex     = $index;
    }

    public function confirmDeleteDate(int $rtsId, string $date): void
    {
        $this->showConfirmClear      = false;
        $this->showConfirmBulkDelete = false;
        $this->pendingRangeIndex     = null;
        $item = collect($this->blockedList)
            ->first(fn ($i) => ($i['rts_id'] ?? null) === $rtsId && ($i['date'] ?? '') === $date);
        $this->pendingDateItem = $item ?? [
            'rts_id'       => $rtsId,
            'date'         => $date,
            'date_display' => Carbon::parse($date)->format('d/m/Y'),
            'label'        => '',
        ];
    }

    public function executeConfirmedAction(): void
    {
        if ($this->showConfirmClear) {
            $this->showConfirmClear = false;
            $this->clearAllBlocked();
        } elseif ($this->showConfirmBulkDelete) {
            $this->showConfirmBulkDelete = false;
            $this->removeSelectedBlocked();
        } elseif ($this->pendingRangeIndex !== null) {
            $index = $this->pendingRangeIndex;
            $this->pendingRangeIndex = null;
            $this->removeBlockedRange($index);
        } elseif ($this->pendingDateItem !== null) {
            $item = $this->pendingDateItem;
            $this->pendingDateItem = null;
            $this->removeBlockedDate($item['rts_id'], $item['date']);
        }
    }

    // Reactive: chọn phòng → load khung giờ + blocked list
    public function updatedProductId(): void
    {
        $this->timeslot_ids       = [];
        $this->timeslotOptions    = [];
        $this->blockedList        = [];
        $this->selectedBlockedKeys = [];
        $this->isStyle2           = false;
        $this->cancelConfirm();

        if (!$this->product_id) return;

        $product = Product::find($this->product_id);
        if (!$product) return;

        $this->isStyle2 = ((int)($product->styles ?? 1)) === 2;

        if (!$this->isStyle2) {
            $this->timeslotOptions = RoomTimeSlot::where('room_id', $this->product_id)
                ->with('timeSlot')
                ->get()
                ->mapWithKeys(fn ($rts) => [
                    $rts->id => ($rts->timeSlot?->label ?? 'Slot #' . $rts->id)
                        . ' — ' . number_format($rts->price, 0, ',', '.') . ' VNĐ',
                ])
                ->toArray();
        }

        $this->refreshBlockedList();
    }

    // Lưu tô đen
    public function saveBlock(): void
    {
        // ── Styles = 2: khóa khoảng ngày trên product ──────────────────
        if ($this->isStyle2) {
            $this->validate([
                'product_id' => 'required',
                'date_from'  => 'required|date',
                'date_to'    => 'required|date|after_or_equal:date_from',
            ], [
                'product_id.required'    => 'Vui lòng chọn phòng.',
                'date_from.required'     => 'Vui lòng chọn ngày bắt đầu.',
                'date_to.required'       => 'Vui lòng chọn ngày kết thúc.',
                'date_to.after_or_equal' => 'Ngày kết thúc phải >= ngày bắt đầu.',
            ]);

            $product = Product::find($this->product_id);
            if (!$product) return;

            $startDisplay = Carbon::parse($this->date_from)->format('d/m/Y');
            $endDisplay   = Carbon::parse($this->date_to)->format('d/m/Y');

            $config = is_array($product->room_config) ? $product->room_config : [];
            $ranges = $config['blocked_ranges'] ?? [];
            $ranges[] = [
                'start' => Carbon::parse($this->date_from)->toDateString(),
                'end'   => Carbon::parse($this->date_to)->toDateString(),
            ];
            $config['blocked_ranges'] = array_values($ranges);
            $product->update(['room_config' => $config]);

            $this->reset(['date_from', 'date_to']);
            $this->refreshBlockedList();

            app(SlotRealtimeService::class)->broadcastDailyBlocked(
                (string) $this->product_id,
                $config['blocked_ranges'],
            );

            Notification::make()
                ->title('Đã khóa khoảng thời gian')
                ->body($startDisplay . ' → ' . $endDisplay)
                ->success()
                ->send();

            return;
        }

        // ── Styles = 1: tô đen từng khung giờ ──────────────────────────
        $this->validate([
            'product_id'   => 'required',
            'timeslot_ids' => 'required|array|min:1',
            'date_from'    => 'required|date',
            'date_to'      => 'required|date|after_or_equal:date_from',
        ], [
            'product_id.required'    => 'Vui lòng chọn phòng.',
            'timeslot_ids.required'  => 'Vui lòng chọn ít nhất 1 khung giờ.',
            'timeslot_ids.min'       => 'Vui lòng chọn ít nhất 1 khung giờ.',
            'date_from.required'     => 'Vui lòng chọn ngày bắt đầu.',
            'date_to.required'       => 'Vui lòng chọn ngày kết thúc.',
            'date_to.after_or_equal' => 'Ngày kết thúc phải >= ngày bắt đầu.',
        ]);

        $dateFrom = Carbon::parse($this->date_from);
        $dateTo   = Carbon::parse($this->date_to);

        // Sinh danh sách ngày trong khoảng
        $blockedDates = [];
        $current = $dateFrom->copy();
        while ($current->lte($dateTo)) {
            $blockedDates[] = $current->toDateString();
            $current->addDay();
        }

        $count = 0;
        foreach ($this->timeslot_ids as $rtsId) {
            $rts = RoomTimeSlot::find($rtsId);
            if (!$rts) continue;

            // 'settings' đã cast 'array' ở RoomTimeSlot — luôn là array hoặc null, không phải chuỗi
            // JSON thô, nên json_decode() ở đây gây TypeError khi khung giờ chưa từng có settings.
            $settings = $rts->settings ?? [];

            $existing = $settings['blocked_dates'] ?? [];
            $merged   = array_values(array_unique(array_merge($existing, $blockedDates)));
            sort($merged);

            $settings['blocked_dates'] = $merged;
            $rts->update(['settings' => $settings]);
            $count++;
        }

        $broadcastSlotIds = array_map('intval', $this->timeslot_ids);
        $this->reset(['timeslot_ids', 'date_from', 'date_to']);
        $this->refreshBlockedList();

        app(SlotRealtimeService::class)->broadcastBlockedRange(
            (string) $this->product_id,
            $blockedDates,
            $broadcastSlotIds,
            'blocked',
        );

        Notification::make()
            ->title("Đã tô đen {$count} khung giờ")
            ->body(
                'Từ ' . $dateFrom->format('d/m/Y') .
                ' → ' . $dateTo->format('d/m/Y') .
                ' (' . count($blockedDates) . ' ngày)'
            )
            ->success()
            ->send();
    }

    // Xóa 1 ngày blocked của 1 RoomTimeSlot (styles=1)
    public function removeBlockedDate(int $rtsId, string $date): void
    {
        $rts = RoomTimeSlot::find($rtsId);
        if (!$rts) return;

        $settings = $rts->settings ?? [];

        $settings['blocked_dates'] = array_values(
            array_diff($settings['blocked_dates'] ?? [], [$date])
        );

        $rts->update(['settings' => $settings]);
        $this->refreshBlockedList();

        app(SlotRealtimeService::class)->broadcastBlockedRange(
            (string) $this->product_id,
            [$date],
            [$rtsId],
            'available',
        );

        Notification::make()
            ->title('Đã gỡ tô đen')
            ->body(Carbon::parse($date)->format('d/m/Y'))
            ->success()
            ->send();
    }

    // Xóa 1 khoảng khóa của phòng styles=2
    public function removeBlockedRange(int $index): void
    {
        $product = Product::find($this->product_id);
        if (!$product) return;

        $config = is_array($product->room_config) ? $product->room_config : [];
        $ranges = $config['blocked_ranges'] ?? [];

        if (!isset($ranges[$index])) return;

        array_splice($ranges, $index, 1);
        $config['blocked_ranges'] = array_values($ranges);
        $product->update(['room_config' => $config]);

        $this->refreshBlockedList();

        app(SlotRealtimeService::class)->broadcastDailyBlocked(
            (string) $this->product_id,
            $config['blocked_ranges'],
        );

        Notification::make()
            ->title('Đã gỡ khóa khoảng thời gian')
            ->success()
            ->send();
    }

    // Xóa hàng loạt các mục đã chọn (checkbox) trong danh sách bên phải — cả 2 kiểu style.
    public function removeSelectedBlocked(): void
    {
        if (empty($this->selectedBlockedKeys)) return;

        $realtime = app(SlotRealtimeService::class);

        if ($this->isStyle2) {
            $product = Product::find($this->product_id);
            if (!$product) return;

            $config  = is_array($product->room_config) ? $product->room_config : [];
            $ranges  = $config['blocked_ranges'] ?? [];
            $indexes = array_map('intval', $this->selectedBlockedKeys);

            foreach ($indexes as $idx) {
                unset($ranges[$idx]);
            }
            $config['blocked_ranges'] = array_values($ranges);
            $product->update(['room_config' => $config]);

            $removedCount = count($indexes);
            $this->selectedBlockedKeys = [];
            $this->refreshBlockedList();

            $realtime->broadcastDailyBlocked((string) $this->product_id, $config['blocked_ranges']);

            Notification::make()
                ->title("Đã gỡ khóa {$removedCount} khoảng thời gian")
                ->success()
                ->send();
            return;
        }

        // Style1: mỗi key có dạng "rts_id|date" — gom theo rts_id để update 1 lần/slot.
        $byRoomTimeSlot = [];
        foreach ($this->selectedBlockedKeys as $key) {
            [$rtsId, $date] = array_pad(explode('|', $key, 2), 2, null);
            if ($rtsId === null || $date === null) continue;
            $byRoomTimeSlot[(int) $rtsId][] = $date;
        }

        $removedCount = 0;
        foreach ($byRoomTimeSlot as $rtsId => $dates) {
            $rts = RoomTimeSlot::find($rtsId);
            if (!$rts) continue;

            $settings = $rts->settings ?? [];

            $settings['blocked_dates'] = array_values(
                array_diff($settings['blocked_dates'] ?? [], $dates)
            );
            $rts->update(['settings' => $settings]);
            $removedCount += count($dates);

            $realtime->broadcastBlockedRange((string) $this->product_id, $dates, [$rtsId], 'available');
        }

        $this->selectedBlockedKeys = [];
        $this->refreshBlockedList();

        Notification::make()
            ->title("Đã gỡ tô đen {$removedCount} mục")
            ->success()
            ->send();
    }

    // Build lại danh sách từ DB
    protected function refreshBlockedList(): void
    {
        $this->blockedList        = [];
        $this->selectedBlockedKeys = [];
        if (!$this->product_id) return;

        if ($this->isStyle2) {
            $product = Product::find($this->product_id);
            if (!$product) return;

            $config = is_array($product->room_config) ? $product->room_config : [];
            foreach ($config['blocked_ranges'] ?? [] as $idx => $range) {
                $this->blockedList[] = [
                    'index'         => $idx,
                    'start'         => $range['start'],
                    'end'           => $range['end'],
                    'start_display' => Carbon::parse($range['start'])->format('d/m/Y'),
                    'end_display'   => Carbon::parse($range['end'])->format('d/m/Y'),
                ];
            }
            return;
        }

        $slots = RoomTimeSlot::where('room_id', $this->product_id)
            ->with('timeSlot')
            ->get();

        foreach ($slots as $rts) {
            $settings = $rts->settings ?? [];

            foreach ($settings['blocked_dates'] ?? [] as $date) {
                $this->blockedList[] = [
                    'rts_id'       => $rts->id,
                    'label'        => $rts->timeSlot?->label ?? 'Slot #' . $rts->id,
                    'date'         => $date,
                    'date_display' => Carbon::parse($date)->format('d/m/Y'),
                ];
            }
        }

        usort(
            $this->blockedList,
            fn ($a, $b) => [$a['date'], $a['label']] <=> [$b['date'], $b['label']]
        );
    }

    // Xóa tất cả khung giờ bị khóa của phòng đang chọn
    public function clearAllBlocked(): void
    {
        if (!$this->product_id) return;

        $realtime = app(SlotRealtimeService::class);

        if ($this->isStyle2) {
            $product = Product::find($this->product_id);
            if (!$product) return;

            $config = is_array($product->room_config) ? $product->room_config : [];
            $config['blocked_ranges'] = [];
            $product->update(['room_config' => $config]);

            $realtime->broadcastDailyBlocked((string) $this->product_id, []);
        } else {
            // Capture toàn bộ ngày đang bị khóa trước khi xóa để broadcast
            $datesBeforeClear = collect($this->blockedList)->pluck('date')->unique()->values()->all();

            $slots = RoomTimeSlot::where('room_id', $this->product_id)->get();
            foreach ($slots as $rts) {
                $settings = $rts->settings ?? [];
                $settings['blocked_dates'] = [];
                $rts->update(['settings' => $settings]);
            }

            if (!empty($datesBeforeClear)) {
                $realtime->broadcastBlockedRange(
                    (string) $this->product_id,
                    $datesBeforeClear,
                    [],
                    'available',
                );
            }
        }

        $this->refreshBlockedList();

        Notification::make()
            ->title('Đã xóa tất cả lịch bị khóa')
            ->success()
            ->send();
    }

    public function render()
    {
        return view('book::livewire.block-timeslot-modal');
    }
}