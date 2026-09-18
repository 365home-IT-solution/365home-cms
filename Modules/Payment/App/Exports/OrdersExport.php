<?php

namespace Modules\Payment\App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Payment\Entities\Order;
use Modules\Payment\App\Services\CccdScannerService;
use Modules\Category\Entities\Category;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class OrdersExport implements WithMultipleSheets
{
    protected $filters;
    protected $orderIds;
    protected $allowedBranchIds;

    public function __construct($filters = null, $orderIds = null, ?array $allowedBranchIds = null)
    {
        $this->filters          = $filters ?? [];
        $this->orderIds         = $orderIds;
        $this->allowedBranchIds = $allowedBranchIds;
    }

    public function sheets(): array
    {
        $query = Category::with('children')
            ->whereNull('parent_id')
            ->where('category_type', 'product');

        if ($this->allowedBranchIds !== null) {
            $query->whereIn('id', $this->allowedBranchIds);
        }

        $categories = $query->get();

        // Luôn filter: chỉ tạo sheet cho danh mục có ít nhất 1 đơn khớp điều kiện
        $filters   = $this->filters;
        $orderIds  = $this->orderIds;

        $categories = $categories->filter(function ($category) use ($filters, $orderIds) {
            $categoryIds = $category->children->pluck('id')->push($category->id)->toArray();

            $query = Order::where('exclude_from_stats', false)->whereHas('items.product.categories', function ($q) use ($categoryIds) {
                $q->whereIn('categories.id', $categoryIds);
            });

            if (!empty($orderIds)) {
                $query->whereIn('id', $orderIds);
            }

            if (!empty($filters['date_from'])) {
                $query->where('created_at', '>=', \Carbon\Carbon::parse($filters['date_from']));
            }
            if (!empty($filters['date_to'])) {
                $query->where('created_at', '<=', \Carbon\Carbon::parse($filters['date_to']));
            }
            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (!empty($filters['payment_method'])) {
                $query->where('payment_method', $filters['payment_method']);
            }

            return $query->exists();
        });

        $sheets = [];
        foreach ($categories as $category) {
            $sheets[] = new OrdersSheetByCategory($category, $this->filters, $this->orderIds, $this->allowedBranchIds);
        }

        if (empty($sheets)) {
            $sheets[] = new OrdersSheetByCategory(null, $this->filters, $this->orderIds, $this->allowedBranchIds);
        }

        return $sheets;
    }
}

class OrdersSheetByCategory implements FromCollection, WithHeadings, WithMapping, WithEvents, WithTitle
{
    protected $category;
    protected $filters;
    protected $orderIds;
    protected $allowedBranchIds;
    protected $rowNumber = 0;

    public function __construct(?Category $category, $filters = [], $orderIds = null, $allowedBranchIds = null)
    {
        $this->category         = $category;
        $this->filters          = $filters ?? [];
        $this->orderIds         = $orderIds;
        $this->allowedBranchIds = $allowedBranchIds;
    }

    public function title(): string
    {
        return $this->category ? mb_substr($this->category->name, 0, 31) : 'Tất cả';
    }

    public function collection()
    {
        $query = Order::query()
            ->where('exclude_from_stats', false)
            ->with([
                'items.product.roomTimeSlots.timeSlot',
                'items.product.categories',
                'services',
            ])
            ->where(function ($q) {
                if (!empty($this->filters['date_from'])) {
                    $q->where('created_at', '>=', Carbon::parse($this->filters['date_from']));
                }
                if (!empty($this->filters['date_to'])) {
                    $q->where('created_at', '<=', Carbon::parse($this->filters['date_to']));
                }
                if (!empty($this->filters['status'])) {
                    $q->where('status', $this->filters['status']);
                }
                if (!empty($this->filters['payment_method'])) {
                    $q->where('payment_method', $this->filters['payment_method']);
                }
            })
            ->orderBy('created_at', 'desc');

        if ($this->category) {
            $categoryIds = $this->category->children->pluck('id')->push($this->category->id)->toArray();
            $query->whereHas('items.product.categories', function ($q) use ($categoryIds) {
                $q->whereIn('categories.id', $categoryIds);
            });
        } elseif ($this->allowedBranchIds !== null) {
            // Fallback sheet: vẫn giới hạn theo chi nhánh được phân quyền
            $allowedCategoryIds = Category::with('children')
                ->whereIn('id', $this->allowedBranchIds)
                ->get()
                ->flatMap(fn ($cat) => $cat->children->pluck('id')->push($cat->id))
                ->unique()
                ->values()
                ->toArray();
            if (!empty($allowedCategoryIds)) {
                $query->whereHas('items.product.categories', function ($q) use ($allowedCategoryIds) {
                    $q->whereIn('categories.id', $allowedCategoryIds);
                });
            }
        }

        if (!empty($this->orderIds)) {
            $query->whereIn('id', $this->orderIds);
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'STT',
            'Ngày tháng',
            'Mã số thuế/Số CCCD',
            'Tên khách đặt phòng/Tên khách chuyển khoản',
            'Địa chỉ',
            'Loại phòng khách đặt',
            'Số lượng',
            'Đơn vị tính (Ngày, giờ, đêm)',
            'Số tiền khách đã thanh toán',
        ];
    }

    public function map($order): array
    {
        $this->rowNumber++;

        $date         = $order->created_at ? Carbon::parse($order->created_at)->format('d/m/Y') : '';
        $buyerName    = $this->sanitizeVal($order->buyer_name);
        $cccdFullName = $this->sanitizeVal($order->cccd_data['full_name'] ?? null);
        // Ghép tên khách nhập tay và tên trên CCCD (chỉ ghép khi cả 2 khác nhau và cùng tồn tại)
        $customerName = ($buyerName && $cccdFullName && $buyerName !== $cccdFullName)
            ? "{$buyerName} - {$cccdFullName}"
            : ($buyerName ?: $cccdFullName);
        $cccdNumber   = $this->sanitizeVal($order->cccd_data['cccd'] ?? null);
        $address      = $this->sanitizeVal($order->buyer_address ?: ($order->cccd_data['address'] ?? null));

        $items     = $order->items ?? collect();
        $slotCount = $items->count();

        // --- Gộp tên loại phòng từng khung giờ ---
        $roomTypeLines = [];

        foreach ($items as $item) {
            $roomType = $item->product->name ?? 'N/A';
            $label    = $item->slot_label ?? '';

            if ($item->checkin_date && $item->checkout_date && $item->product && $item->product->roomTimeSlots) {
                $checkin  = Carbon::parse($item->checkin_date);
                $checkout = Carbon::parse($item->checkout_date);
                foreach ($item->product->roomTimeSlots as $rts) {
                    if ($rts->timeSlot) {
                        $ts = $rts->timeSlot;
                        if ($checkin->format('H:i') === Carbon::parse($ts->start_time)->format('H:i') &&
                            $checkout->format('H:i') === Carbon::parse($ts->end_time)->format('H:i')) {
                            $label = $ts->label;
                            break;
                        }
                    }
                }
            }
            if ($label) $roomType .= ' - ' . $label;
            $roomTypeLines[] = $roomType;
        }

        [$quantity, $unit] = $this->computeStayInfo($items);

        // --- Dịch vụ đi kèm (chỉ dùng để tính tổng, không xuất riêng cột) ---
        $servicesTotal = $order->services ? $order->services->sum('subtotal') : 0;

        // --- Số tiền khách đã thanh toán ---
        // Ưu tiên: dùng amount đã lưu trong order (chính xác nhất, đã bao gồm mọi loại giảm giá)
        if (!empty($order->amount) && $order->amount > 0) {
            $sumPaid = $order->amount;
        } else {
            // Fallback: tự tính lại nếu order không lưu amount
            $sumPaid = $this->calculateSumPaid($items, $slotCount, $servicesTotal);
        }

        $roomTypeStr = implode("\n", $roomTypeLines);

        return [
            $this->rowNumber,
            $date,
            $cccdNumber,
            $customerName,
            $address,
            $roomTypeStr,
            $quantity,
            $unit,
            $sumPaid,
        ];
    }

    /**
     * Tính Số lượng + Đơn vị tính thể hiện thời gian khách ở, dựa trên toàn bộ
     * khung giờ (item) của đơn:
     * - 1 khung, qua đêm            → tính theo Giờ
     * - 1 khung, không qua đêm      → tính theo Phút
     * - Nhiều khung, trong cùng 1 ngày (tổng thời gian ≤ 24h) → tính theo Giờ
     * - Nhiều khung, trải qua nhiều ngày (tổng thời gian > 24h) → tính theo Ngày (kèm giờ lẻ)
     */
    protected function computeStayInfo(Collection $items): array
    {
        if ($items->isEmpty()) {
            return ['', ''];
        }

        $checkins  = [];
        $checkouts = [];
        $hasOvernight = false;

        foreach ($items as $item) {
            if (!$item->checkin_date || !$item->checkout_date) {
                continue;
            }

            $checkin  = Carbon::parse($item->checkin_date);
            $checkout = Carbon::parse($item->checkout_date);
            $checkins[]  = $checkin;
            $checkouts[] = $checkout;

            $label = $item->slot_label ?? '';
            if ($item->product && $item->product->roomTimeSlots) {
                foreach ($item->product->roomTimeSlots as $rts) {
                    if ($rts->timeSlot &&
                        $checkin->format('H:i') === Carbon::parse($rts->timeSlot->start_time)->format('H:i') &&
                        $checkout->format('H:i') === Carbon::parse($rts->timeSlot->end_time)->format('H:i')) {
                        $label = $rts->timeSlot->label;
                        break;
                    }
                }
            }
            if (stripos($label, 'Qua đêm') !== false) {
                $hasOvernight = true;
            }
        }

        if (empty($checkins)) {
            // Không có mốc giờ (item bán theo lượt) → dùng tổng số lượng đã đặt
            return [$items->sum('quantity'), 'Lượt'];
        }

        $firstCheckin = min($checkins);
        $lastCheckout = max($checkouts);
        $totalMinutes = $firstCheckin->diffInMinutes($lastCheckout);
        $itemCount    = $items->count();

        // Nhiều khung, trải qua nhiều ngày (> 24h)
        if ($itemCount > 1 && $totalMinutes > 24 * 60) {
            $days          = intdiv($totalMinutes, 24 * 60);
            $remainMinutes = $totalMinutes % (24 * 60);
            $remainHours   = intdiv($remainMinutes, 60);
            $remainMins    = $remainMinutes % 60;

            $quantity = "{$days} ngày";
            if ($remainHours > 0) $quantity .= " {$remainHours} giờ";
            if ($remainMins > 0)  $quantity .= " {$remainMins} phút";

            return [$quantity, 'Ngày'];
        }

        // 1 khung qua đêm, hoặc nhiều khung trong cùng 1 ngày → tính theo Giờ
        if ($hasOvernight || $itemCount > 1) {
            $hours   = intdiv($totalMinutes, 60);
            $minutes = $totalMinutes % 60;

            $quantity = $minutes > 0 ? "{$hours} giờ {$minutes} phút" : $hours;

            return [$quantity, 'Giờ'];
        }

        // 1 khung, không qua đêm → tính theo Phút
        return [$totalMinutes, 'Phút'];
    }

    /**
     * Tính tiền khách thực trả khi order không lưu sẵn total_amount.
     * Ưu tiên: full_booking_discount > giảm theo số khung giờ (5%/10%).
     * Chỉ áp dụng discount khi khách đặt ĐỦ toàn bộ khung giờ trong ngày của phòng (full day).
     * Nếu chọn ít hơn tổng số khung giờ của phòng → không áp dụng discount.
     */
    protected function calculateSumPaid($items, int $slotCount, float $servicesTotal): float
    {
        $roomSubtotal = $items->sum(fn($oi) => $oi->price * $oi->quantity + ($oi->extra_fee ?? 0));

        // Lấy tổng số khung giờ có thể đặt của phòng (từ roomTimeSlots)
        $firstProduct = $items->first()?->product;
        $totalSlots   = $firstProduct?->roomTimeSlots?->count() ?? 0;

        // Chỉ áp dụng discount khi đặt full (slotCount = tổng khung giờ của phòng)
        $isFullDay = $totalSlots > 0 && $slotCount >= $totalSlots;

        if (!$isFullDay) {
            // Không full → không giảm giá
            return $roomSubtotal + $servicesTotal;
        }

        // --- Full day: kiểm tra full_booking_discount ---
        $fullBookingDiscount = $firstProduct?->full_booking_discount ?? null;
        $discountRate        = 0;

        if (!empty($fullBookingDiscount)) {
            $raw = trim($fullBookingDiscount);

            if (str_contains($raw, '%')) {
                // Giảm theo %
                $discountRate = (float) str_replace('%', '', $raw) / 100;
            } else {
                // Giảm số tiền cố định
                $fixedDiscount = (float) $raw;
                return max(0, $roomSubtotal - $fixedDiscount) + $servicesTotal;
            }
        } else {
            // Không có full_booking_discount → fallback theo số khung giờ (5%/10%)
            $discountRate = match (true) {
                $slotCount === 2 => 0.05,
                $slotCount >= 3  => 0.10,
                default          => 0,
            };
        }

        $sumPaid = 0;
        foreach ($items as $oi) {
            $oiTotal  = $oi->price * $oi->quantity;
            $oiExtra  = ($oi->extra_fee ?? 0) > 0 ? $oi->extra_fee : 0;
            $sumPaid += ($oiTotal * (1 - $discountRate)) + $oiExtra;
        }
        $sumPaid += $servicesTotal;

        return $sumPaid;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet      = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();

                // Header
                $sheet->getStyle('A1:I1')->applyFromArray([
                    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => '00B050']],
                    'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
                ]);

                if ($highestRow >= 2) {
                    // Format tiền tệ cột I (Số tiền khách đã thanh toán)
                    $sheet->getStyle('I2:I' . $highestRow)->getNumberFormat()->setFormatCode('#,##0"₫"');

                    // Border + căn giữa dọc (A–I = 9 cột)
                    $sheet->getStyle('A1:I' . $highestRow)->applyFromArray([
                        'borders'   => ['allBorders' => ['borderStyle' => 'thin']],
                        'alignment' => ['vertical' => 'center'],
                    ]);

                    // WrapText cho các cột đa dòng
                    foreach (['E', 'F'] as $col) {
                        $sheet->getStyle($col . '2:' . $col . $highestRow)
                              ->getAlignment()->setWrapText(true);
                    }

                    $sheet->getColumnDimension('E')->setWidth(30);
                    $sheet->getColumnDimension('F')->setWidth(35);
                }

                $fixedCols = ['E', 'F'];
                foreach (range('A', 'I') as $col) {
                    if (!in_array($col, $fixedCols)) {
                        $sheet->getColumnDimension($col)->setAutoSize(true);
                    }
                }
            },
        ];
    }

    protected function sanitizeVal($val, $isMultiline = false)
    {
        if (is_null($val) || $val === '') return null;
        $val = (string) $val;
        $val = $isMultiline
            ? preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $val)
            : preg_replace('/[\x00-\x1F\x7F]/', '', $val);
        if (preg_match('/^[=\+\-@]/', $val)) $val = "'" . $val;
        return $val;
    }
}