<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lịch ngừng nhận voucher của 1 đối tác (toàn bộ hoặc một số chi nhánh). Khách đặt phòng ở chi nhánh đó vào ngày khớp
 * lịch thì KHÔNG dùng được các nhóm voucher đã chọn — chỉ chặn việc DÙNG mã khi đặt phòng, không tắt trò chơi / điểm
 * danh / lên hạng. Kiểm tra ở App\Services\VoucherBlockService (mọi luồng áp mã: app, web, khách vãng lai, sửa đơn).
 */
class VoucherBlock extends Model
{
    use LogsAuditTrail;

    public const GROUP_PLATFORM   = 'platform_code';
    public const GROUP_MEMBERSHIP = 'membership';
    public const GROUP_CHECKIN    = 'checkin';
    public const GROUP_GAME       = 'game';
    public const GROUP_PARTNER    = 'partner_code';

    /** Nhóm voucher (khoá => tên hiển thị) — xem VoucherBlockService::groupOf(). */
    public const GROUPS = [
        self::GROUP_PLATFORM   => 'Mã khuyến mãi chung 365home',
        self::GROUP_MEMBERSHIP => 'Quà hạng thành viên',
        self::GROUP_CHECKIN    => 'Quà điểm danh',
        self::GROUP_GAME       => 'Quà trò chơi & giới thiệu',
        self::GROUP_PARTNER    => 'Mã riêng của đối tác',
    ];

    public const GROUP_HINTS = [
        self::GROUP_PLATFORM   => 'Mã công khai khách tự nhập (VD BAOTOANSILVER)',
        self::GROUP_MEMBERSHIP => 'Voucher tặng khi lên hạng, quà theo hạng',
        self::GROUP_CHECKIN    => 'Voucher nhận khi điểm danh đủ chu kỳ',
        self::GROUP_GAME       => 'Thẻ cào, Caro, mảnh ghép, đổi điểm game, giới thiệu bạn',
        self::GROUP_PARTNER    => 'Mã do chính đối tác tạo',
    ];

    public const WEEKDAYS = [1 => 'Thứ 2', 2 => 'Thứ 3', 3 => 'Thứ 4', 4 => 'Thứ 5', 5 => 'Thứ 6', 6 => 'Thứ 7', 7 => 'Chủ nhật'];

    public const MIXED_ANY = 'any';
    public const MIXED_ALL = 'all';

    protected $fillable = [
        'partner_id', 'name', 'is_active', 'category_ids',
        'all_days', 'use_price_board_holidays', 'date_ranges', 'weekdays', 'recurring_ranges',
        'valid_from', 'valid_to', 'except_dates', 'blocked_groups', 'mixed_mode', 'note', 'created_by',
    ];

    protected $casts = [
        'is_active'                => 'boolean',
        'all_days'                 => 'boolean',
        'use_price_board_holidays' => 'boolean',
        'category_ids'             => 'array',
        'date_ranges'              => 'array',
        'weekdays'                 => 'array',
        'recurring_ranges'         => 'array',
        'except_dates'             => 'array',
        'blocked_groups'           => 'array',
        'valid_from'               => 'date',
        'valid_to'                 => 'date',
    ];

    protected $attributes = [
        'is_active'  => true,
        'mixed_mode' => self::MIXED_ANY,
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Tóm tắt lịch để hiện ở danh sách / API, VD "Ngày lễ trong bảng giá · T6, T7, CN · 18/12/2026 → 02/01/2027". */
    public function scheduleSummary(): string
    {
        $parts = [];

        if ($this->all_days) {
            $parts[] = 'Mọi ngày';
        }
        if ($this->use_price_board_holidays) {
            $parts[] = 'Ngày lễ trong bảng giá';
        }
        foreach ((array) $this->date_ranges as $r) {
            $parts[] = trim(($r['name'] ?? '') . ' ' . self::dmy($r['from'] ?? null) . ' → ' . self::dmy($r['to'] ?? null));
        }
        if ($this->weekdays) {
            $short = [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'];
            $days  = collect($this->weekdays)->map(fn ($d) => (int) $d)->sort()->map(fn ($d) => $short[$d] ?? $d)->implode(', ');
            $parts[] = $days;
        }
        foreach ((array) $this->recurring_ranges as $r) {
            $parts[] = trim(($r['name'] ?? '') . ' ' . self::dm($r['from'] ?? null) . ' → ' . self::dm($r['to'] ?? null) . ' hằng năm');
        }

        return $parts ? implode(' · ', $parts) : '—';
    }

    public static function dmy(?string $ymd): string
    {
        return $ymd ? \Carbon\Carbon::parse($ymd)->format('d/m/Y') : '?';
    }

    /** "24/12", "24-12"… (ngày/tháng) hoặc "12-24" (MM-DD) → "12-24"; null nếu không hợp lệ. */
    public static function normalizeMonthDay(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $value, $m)) {
            [$day, $month] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/^(\d{2})-(\d{2})$/', $value, $m)) {
            [$month, $day] = [(int) $m[1], (int) $m[2]];
        } else {
            return null;
        }

        return checkdate($month, $day, 2024) ? sprintf('%02d-%02d', $month, $day) : null;
    }

    public static function dm(?string $md): string
    {
        return $md && str_contains($md, '-') ? implode('/', array_reverse(explode('-', $md))) : '?';
    }

    protected function auditLabel(): string
    {
        return $this->name;
    }
}
