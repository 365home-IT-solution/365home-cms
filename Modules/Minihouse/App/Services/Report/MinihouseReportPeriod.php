<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Services\Report;

use Illuminate\Support\Carbon;

// Suy ra [start, end] (Carbon, đã startOfDay()/endOfDay()) từ tham số filter — PORT lại đúng logic
// Modules\Minihouse\App\Filament\Pages\FinanceReports::periodDates()/resolveCustomRange(), chỉ đổi
// từ đọc property Livewire ($this->period/$this->customStart/$this->customEnd) sang tham số truyền
// vào, để dùng chung được cho cả trang Filament (sau này có thể refactor lại dùng chung) lẫn API.
// Giữ nguyên "quý" (không có ở bản Home) vì MiniHouse cần báo cáo theo quý/năm.
class MinihouseReportPeriod
{
    public const LABELS = [
        'today'        => 'Hôm nay',
        'this_month'   => 'Tháng này',
        'last_month'   => 'Tháng trước',
        'this_quarter' => 'Quý này',
        'last_quarter' => 'Quý trước',
        'this_year'    => 'Năm nay',
        'last_year'    => 'Năm trước',
        'custom'       => 'Tuỳ chọn',
    ];

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function resolve(string $filter, ?string $startDate = null, ?string $endDate = null): array
    {
        $now = Carbon::now();

        return match ($filter) {
            'today'        => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'last_month'   => [
                $now->copy()->subMonthNoOverflow()->startOfMonth()->startOfDay(),
                $now->copy()->subMonthNoOverflow()->endOfMonth()->endOfDay(),
            ],
            'this_quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()->min($now->copy()->endOfDay())],
            'last_quarter' => [
                $now->copy()->subQuarterNoOverflow()->startOfQuarter(),
                $now->copy()->subQuarterNoOverflow()->endOfQuarter(),
            ],
            'this_year'    => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            'last_year'    => [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear()],
            'custom'       => self::resolveCustomRange($now, $startDate, $endDate),
            default        => [$now->copy()->startOfMonth()->startOfDay(), $now->copy()->endOfDay()], // this_month
        };
    }

    private static function resolveCustomRange(Carbon $now, ?string $startDate, ?string $endDate): array
    {
        $start = $startDate ? self::parseOrFail($startDate, 'start_date')->startOfDay() : $now->copy()->startOfMonth()->startOfDay();
        $end   = $endDate ? self::parseOrFail($endDate, 'end_date')->endOfDay() : $now->copy()->endOfDay();

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }

    // BUG THẬT phát hiện qua review: Carbon::parse() không được bắt lỗi trước đây — trên trang
    // Filament vô hại vì input chỉ tới từ DatePicker (luôn hợp lệ), nhưng endpoint API mới
    // (ReportController) nhận thẳng start_date/end_date từ query string của app di động — 1 chuỗi
    // không phải ngày hợp lệ (VD "not-a-date") ném thẳng CarbonException không bắt được, trả 500 thô
    // thay vì lỗi validate rõ ràng. Ném ValidationException để Laravel tự trả 422 kèm thông báo cho
    // cả 2 nơi gọi (Filament lẫn API) thay vì để crash.
    private static function parseOrFail(string $value, string $field): Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                $field => "Giá trị \"{$field}\" không phải ngày hợp lệ.",
            ]);
        }
    }
}
