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
    public bool   $showConfirmClear  = false;
    public ?int   $pendingRangeIndex = null;
    public ?array $pendingDateItem   = null;

    // ---- Options ----
    public array $roomOptions     = [];
    public array $timeslotOptions = [];

    // ---- Danh sách blocked hiển thị ----
    // [ ['rts_id' => X, 'label' => '...', 'date' => 'YYYY-MM-DD', 'date_display' => 'dd/mm/yyyy'], ... ]
    public array $blockedList = [];

    public function mount(): void
    {
        $this->roomOptions = $this->buildRoomOptions();
    }

    private function buildRoomOptions(): array
    {
        $user  = auth()->user();
        $query = Product::where('is_activated', true);

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

        return $query->pluck('name', 'id')->toArray();
    }

    // Called from Alpine via $wire.resetModal() when button is clicked — modal visibility
    // is handled client-side by Alpine, so we only need to reset the form state here.
    public function resetModal(): void
    {
        $this->reset([
            'product_id', 'timeslot_ids', 'date_from', 'date_to',
            'blockedList', 'timeslotOptions', 'isStyle2', 'lockedToProduct',
            'showConfirmClear', 'pendingRangeIndex', 'pendingDateItem',
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
        $this->showConfirmClear  = false;
        $this->pendingRangeIndex = null;
        $this->pendingDateItem   = null;
    }

    public function confirmClear(): void
    {
        $this->pendingRangeIndex = null;
        $this->pendingDateItem   = null;
        $this->showConfirmClear  = true;
    }

    public function confirmDeleteRange(int $index): void
    {
        $this->showConfirmClear  = false;
        $this->pendingDateItem   = null;
        $this->pendingRangeIndex = $index;
    }

    public function confirmDeleteDate(int $rtsId, string $date): void
    {
        $this->showConfirmClear  = false;
        $this->pendingRangeIndex = null;
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
        $this->timeslot_ids    = [];
        $this->timeslotOptions = [];
        $this->blockedList     = [];
        $this->isStyle2        = false;
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

            $settings = is_array($rts->settings)
                ? $rts->settings
                : (json_decode($rts->settings, true) ?? []);

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

        $settings = is_array($rts->settings)
            ? $rts->settings
            : (json_decode($rts->settings, true) ?? []);

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

    // Build lại danh sách từ DB
    protected function refreshBlockedList(): void
    {
        $this->blockedList = [];
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
            $settings = is_array($rts->settings)
                ? $rts->settings
                : (json_decode($rts->settings, true) ?? []);

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
                $settings = is_array($rts->settings)
                    ? $rts->settings
                    : (json_decode($rts->settings, true) ?? []);
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