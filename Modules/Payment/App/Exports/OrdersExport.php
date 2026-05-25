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

            $query = Order::whereHas('items.product.categories', function ($q) use ($categoryIds) {
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
            'Tên đơn vị',
            'Tên khách đặt / Chuyển khoản',
            'Thông tin người dùng',
            'Loại phòng khách đặt',
            'SL',
            'ĐVT',
            'Dịch vụ đi kèm (SL x Đơn giá)',
            'Đơn giá phòng',
            'Thành tiền (Phòng + Dịch vụ)',
            'Khách thực trả (Sau giảm giá)',
            'Tình trạng xuất hóa đơn',
        ];
    }

    public function map($order): array
    {
        $this->rowNumber++;

        $date         = $order->created_at ? Carbon::parse($order->created_at)->format('d/m/Y') : '';
        $unitName     = $this->category ? $this->category->name : '';
        $customerName = $order->buyer_name;
        $userInfo     = $this->sanitizeVal($order->note_for_admin, true);

        $items     = $order->items ?? collect();
        $slotCount = $items->count();

        // --- Gộp thông tin từng khung giờ ---
        $roomTypeLines = [];
        $quantityLines = [];
        $unitLines     = [];
        $priceLines    = [];
        $roomSubtotal  = 0;

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

            $isOvernight = stripos($label, 'Qua đêm') !== false;
            $unit        = 'Lượt';
            $quantity    = 1;

            if ($item->checkin_date && $item->checkout_date) {
                $checkin  = Carbon::parse($item->checkin_date);
                $checkout = Carbon::parse($item->checkout_date);
                if ($isOvernight) {
                    $unit     = 'Giờ';
                    $quantity = round($checkout->diffInMinutes($checkin) / 60, 1);
                } else {
                    $unit     = 'Phút';
                    $quantity = $checkout->diffInMinutes($checkin);
                }
            } else {
                $quantity = $item->quantity;
            }

            $quantityLines[] = $quantity;
            $unitLines[]     = $unit;
            $priceLines[]    = number_format($item->price, 0, ',', '.') . '₫';

            $roomSubtotal += ($item->price * $item->quantity) + ($item->extra_fee ?? 0);
        }

        // --- Dịch vụ đi kèm ---
        $servicesText  = '';
        $servicesTotal = 0;
        if ($order->services && $order->services->count() > 0) {
            $servicesText = $order->services->map(function ($svc) {
                return "• " . $svc->service_name . " (x" . $svc->quantity . ") - " . number_format($svc->price, 0, ',', '.') . "₫";
            })->implode("\n");
            $servicesTotal = $order->services->sum('subtotal');
        }

        // --- Thành tiền (Cột K) ---
        $finalRowTotal = $roomSubtotal + $servicesTotal;

        // --- Khách thực trả (Cột L) ---
        // Ưu tiên: dùng amount đã lưu trong order (chính xác nhất, đã bao gồm mọi loại giảm giá)
        if (!empty($order->amount) && $order->amount > 0) {
            $sumPaid = $order->amount;
        } else {
            // Fallback: tự tính lại nếu order không lưu amount
            $sumPaid = $this->calculateSumPaid($items, $slotCount, $servicesTotal);
        }

        // --- Gộp nhiều dòng bằng xuống dòng ---
        $roomTypeStr = implode("\n", $roomTypeLines);
        $quantityStr = implode("\n", $quantityLines);
        $unitStr     = implode("\n", $unitLines);
        $priceStr    = implode("\n", $priceLines);

        return [
            $this->rowNumber,
            $date,
            $unitName,
            $customerName,
            $userInfo,
            $roomTypeStr,
            $quantityStr,
            $unitStr,
            $servicesText,
            $priceStr,
            $finalRowTotal,
            $sumPaid,
            'Chưa xuất',
        ];
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
                $sheet->getStyle('A1:M1')->applyFromArray([
                    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => '00B050']],
                    'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
                ]);

                if ($highestRow >= 2) {
                    // Format tiền tệ cột K, L
                    $sheet->getStyle('K2:L' . $highestRow)->getNumberFormat()->setFormatCode('#,##0"₫"');

                    // Border + căn giữa dọc (A–M = 13 cột)
                    $sheet->getStyle('A1:M' . $highestRow)->applyFromArray([
                        'borders'   => ['allBorders' => ['borderStyle' => 'thin']],
                        'alignment' => ['vertical' => 'center'],
                    ]);

                    // WrapText cho các cột đa dòng
                    foreach (['E', 'F', 'G', 'H', 'I', 'J'] as $col) {
                        $sheet->getStyle($col . '2:' . $col . $highestRow)
                              ->getAlignment()->setWrapText(true);
                    }

                    $sheet->getColumnDimension('F')->setWidth(35);
                    $sheet->getColumnDimension('I')->setWidth(35);
                    $sheet->getColumnDimension('E')->setWidth(30);
                    $sheet->getColumnDimension('J')->setWidth(18);
                }

                $fixedCols = ['E', 'F', 'I', 'J'];
                foreach (range('A', 'M') as $col) {
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