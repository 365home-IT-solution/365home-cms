<?php

declare(strict_types=1);

namespace Modules\VehicleRental\App\Filament\Pages;

use Carbon\Carbon;
use Filament\Pages\Page;
use Modules\VehicleRental\App\Filament\Resources\VehicleBookingResource;
use Modules\VehicleRental\App\Models\Vehicle;
use Modules\VehicleRental\App\Models\VehicleBooking;
use Modules\VehicleRental\App\Services\VehicleScheduleService;
use Modules\VehicleRental\App\Support\VehicleRentalAccess;

// Lịch xe dạng Gantt: mỗi xe một hàng, thanh = đơn thuê / khoảng khoá xe. Bấm vào thanh đơn để mở đơn. Dữ liệu từ
// VehicleScheduleService (cùng nguồn với API GET /api/admin/vehicle-rental/schedule).
class VehicleSchedule extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Lịch xe';

    protected static ?string $title = 'Lịch xe';

    protected static ?string $slug = 'rental-vehicle-schedule';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'vehiclerental::pages.vehicle-schedule';

    public string $from = '';

    public int $days = 14;

    public ?string $type = null;

    public static function canAccess(): bool
    {
        return VehicleRentalAccess::canViewSchedule(auth()->user());
    }

    public function mount(): void
    {
        $this->from = now()->toDateString();
    }

    public function previous(): void
    {
        $this->from = Carbon::parse($this->from)->subDays($this->days)->toDateString();
    }

    public function next(): void
    {
        $this->from = Carbon::parse($this->from)->addDays($this->days)->toDateString();
    }

    public function today(): void
    {
        $this->from = now()->toDateString();
    }

    public function updatedDays(): void
    {
        $this->days = in_array((int) $this->days, [7, 14, 30], true) ? (int) $this->days : 14;
    }

    protected function getViewData(): array
    {
        $start = Carbon::parse($this->from ?: now()->toDateString())->startOfDay();
        $end = $start->copy()->addDays($this->days);
        $span = max(1, $start->diffInMinutes($end));

        $schedule = app(VehicleScheduleService::class)->build($start, $end, null, $this->type ?: null);

        $pct = fn (Carbon $t) => max(0, min(100, $start->diffInMinutes($t, false) / $span * 100));

        $rows = collect($schedule['vehicles'])->map(function (array $row) use ($start, $end, $pct) {
            $row['bars'] = collect($row['items'])->map(function (array $item) use ($start, $end, $pct) {
                $s = Carbon::parse($item['start_at']);
                $e = Carbon::parse($item['end_at']);
                $left = $pct($s->lt($start) ? $start->copy() : $s);
                $right = $pct($e->gt($end) ? $end->copy() : $e);
                $item['left'] = round($left, 3);
                $item['width'] = round(max(0.35, $right - $left), 3);
                $item['url'] = $item['kind'] === 'booking'
                    ? VehicleBookingResource::getUrl('view', ['record' => $item['id']])
                    : null;
                $item['label'] = $item['kind'] === 'booking'
                    ? $item['code'] . ($item['customer_name'] ? ' · ' . $item['customer_name'] : '')
                    : 'Khoá xe' . (! empty($item['reason']) ? ': ' . $item['reason'] : '');
                $item['tooltip'] = $item['kind'] === 'booking'
                    ? implode("\n", array_filter([
                        $item['code'] . ' — ' . (VehicleBookingResource::STATUSES[$item['status']] ?? $item['status']) . ($item['overdue'] ? ' (QUÁ HẠN TRẢ)' : ''),
                        $item['customer_name'] ? 'Khách: ' . $item['customer_name'] . ($item['customer_phone'] ? ' · ' . $item['customer_phone'] : '') : null,
                        'Nhận: ' . $s->format('H:i d/m') . ' → Trả: ' . Carbon::parse($item['planned_end_at'])->format('H:i d/m'),
                        $item['room_order_code'] ? 'Kèm đơn phòng #' . $item['room_order_code'] : null,
                    ]))
                    : 'Khoá xe ' . $s->format('H:i d/m') . ' → ' . $e->format('H:i d/m') . (! empty($item['reason']) ? "\n" . $item['reason'] : '');

                return $item;
            })->all();

            return $row;
        })->all();

        $dayHeaders = collect(range(0, $this->days - 1))->map(function (int $i) use ($start) {
            $d = $start->copy()->addDays($i);

            return ['label' => $d->format('d/m'), 'dow' => ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'][$d->dayOfWeek], 'weekend' => in_array($d->dayOfWeek, [0, 6], true), 'today' => $d->isToday()];
        })->all();

        return [
            'rows'      => $rows,
            'dayHeaders' => $dayHeaders,
            'todayLeft' => now()->between($start, $end) ? round($pct(now()), 3) : null,
            'typeOptions' => [Vehicle::TYPE_MOTORBIKE => 'Xe máy', Vehicle::TYPE_CAR => 'Ô tô'],
            'legend'    => [
                ['key' => 'pending_payment', 'label' => 'Chờ thanh toán'],
                ['key' => 'confirmed', 'label' => 'Đã xác nhận'],
                ['key' => 'picked_up', 'label' => 'Đang thuê'],
                ['key' => 'overdue', 'label' => 'Quá hạn trả'],
                ['key' => 'returned', 'label' => 'Đã nhận lại'],
                ['key' => 'settled', 'label' => 'Đã tất toán'],
                ['key' => 'block', 'label' => 'Khoá xe'],
            ],
        ];
    }
}
