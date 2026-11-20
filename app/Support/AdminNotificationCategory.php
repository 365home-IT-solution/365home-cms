<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 8 nhóm thông báo admin mà FE dùng cho cài đặt (GET/PUT /api/admin/notification-settings) và bộ lọc
 * (?type= / ?exclude_type= của GET /api/admin/notifications). Field 'type' thực lưu trong
 * viewData của từng thông báo có rất nhiều giá trị (xem AdminNotificationService) — lớp này gom về
 * 8 nhóm, nên loại mới chỉ cần khớp 1 prefix là tự vào đúng nhóm.
 *
 * Quy tắc khớp: kiểm tra theo thứ tự trong RULES (đầu tiên khớp thắng — 'partner_payment_channel_changed'
 * thuộc 'partner' chứ không phải 'payment' vì 'partner_' đứng trước). Type không khớp quy tắc nào, hoặc
 * thông báo cũ không có type, rơi về 'order' (khớp mặc định lịch sử của toItem()).
 */
final class AdminNotificationCategory
{
    public const ORDER        = 'order';
    public const PAYMENT      = 'payment';
    public const CHAT         = 'chat';
    public const STAY         = 'stay';
    public const APPROVAL     = 'approval';
    public const PARTNER      = 'partner';
    public const SUBSCRIPTION = 'subscription';
    public const APP_ERROR    = 'app_error';

    public const KEYS = [
        self::ORDER, self::PAYMENT, self::CHAT, self::STAY,
        self::APPROVAL, self::PARTNER, self::SUBSCRIPTION, self::APP_ERROR,
    ];

    /**
     * Thứ tự quan trọng. 'exact' = so khớp nguyên chuỗi, 'prefix' = bắt đầu bằng.
     * Nhóm ORDER không liệt kê ở đây vì là mặc định (fallback).
     */
    private const RULES = [
        self::APP_ERROR    => ['exact' => ['app_error'], 'prefix' => []],
        self::APPROVAL     => ['exact' => [], 'prefix' => ['approval_']],
        self::SUBSCRIPTION => ['exact' => [], 'prefix' => ['subscription_']],
        self::PARTNER      => ['exact' => [], 'prefix' => ['partner_', 'minihouse_signup_']],
        self::CHAT         => ['exact' => ['message'], 'prefix' => ['minihouse_message']],
        self::STAY         => ['exact' => ['checkin', 'checkout', 'room_unlock'], 'prefix' => []],
        self::PAYMENT      => [
            'exact'  => ['payment', 'extra_charge', 'cash_auto_confirmed', 'manual_payment_claimed', 'minihouse_invoice_unallocated'],
            'prefix' => ['escrow_', 'settlement_', 'refund_claim_', 'cash_cancel_', 'order_remaining_paid', 'order_extra_charge_paid', 'vehicle_payment_', 'vehicle_refund_'],
        ],
    ];

    public static function isValid(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /**
     * Chuẩn hóa cài đặt lưu trong DB: thiếu field/nhóm = bật. Luôn trả đủ 8 nhóm theo thứ tự KEYS.
     *
     * @return array{enabled: bool, types: array<string, bool>}
     */
    public static function normalizeSettings(?array $stored): array
    {
        $types = [];
        foreach (self::KEYS as $key) {
            $types[$key] = (bool) ($stored['types'][$key] ?? true);
        }

        return ['enabled' => (bool) ($stored['enabled'] ?? true), 'types' => $types];
    }

    /** Nhóm của một giá trị viewData.type (null/rỗng = thông báo cũ → 'order'). */
    public static function of(?string $rawType): string
    {
        if ($rawType === null || $rawType === '') {
            return self::ORDER;
        }

        foreach (self::RULES as $category => $rule) {
            if (in_array($rawType, $rule['exact'], true)) {
                return $category;
            }
            foreach ($rule['prefix'] as $prefix) {
                if (str_starts_with($rawType, $prefix)) {
                    return $category;
                }
            }
        }

        return self::ORDER;
    }

    /** Chỉ giữ thông báo thuộc MỘT TRONG các nhóm $categories (OR giữa các nhóm). */
    public static function include($query, array $categories): void
    {
        if ($categories === []) {
            return;
        }

        $query->where(function ($q) use ($categories) {
            foreach ($categories as $category) {
                $q->orWhere(fn ($g) => self::matchGroup($g, $category));
            }
        });
    }

    /**
     * Loại thông báo thuộc các nhóm $categories. Type NULL (thông báo cũ) thuộc 'order' nên chỉ bị loại
     * khi 'order' nằm trong danh sách — phải xử lý riêng vì NOT(NULL) trong SQL là NULL (làm rơi cả dòng).
     */
    public static function exclude($query, array $categories): void
    {
        foreach ($categories as $category) {
            if ($category === self::ORDER) {
                $query->whereNot(fn ($g) => self::matchGroup($g, $category));
            } else {
                $query->where(fn ($q) => $q->whereNull('data->viewData->type')
                    ->orWhereNot(fn ($g) => self::matchGroup($g, $category)));
            }
        }
    }

    private static function matchGroup($q, string $category): void
    {
        $col = 'data->viewData->type';

        if ($category === self::ORDER) {
            $q->whereNull($col)->orWhere(function ($inner) use ($col) {
                foreach (self::RULES as $rule) {
                    foreach ($rule['exact'] as $v) {
                        $inner->where($col, '!=', $v);
                    }
                    foreach ($rule['prefix'] as $p) {
                        $inner->where($col, 'not like', $p . '%');
                    }
                }
            });

            return;
        }

        $rule = self::RULES[$category];
        foreach ($rule['exact'] as $v) {
            $q->orWhere($col, $v);
        }
        foreach ($rule['prefix'] as $p) {
            $q->orWhere($col, 'like', $p . '%');
        }
    }

    /**
     * Áp bộ lọc ?type= / ?exclude_type= (danh sách nhóm, ngăn cách bằng dấu phẩy hoặc mảng).
     * Giá trị không phải nhóm hợp lệ bị bỏ qua.
     *
     * @return list<string>
     */
    public static function parseList(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_unique(array_filter(
            array_map(fn ($v) => trim((string) $v), $items),
            fn ($v) => self::isValid($v),
        )));
    }
}
