<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Partner;

// Nhãn đối tác (partner_id + partner_name) gắn vào từng phần tử danh sách ở API admin để app ghi tên
// đối tác ở dòng phụ khi super_admin chưa chọn đối tác (xem tài liệu API_PhanTachDoiTac). Bảng
// partners nhỏ nên nạp 1 lần/request thay vì eager load quan hệ ở từng controller.
class PartnerLabel
{
    /** @var array<string, string>|null */
    private static ?array $names = null;

    /**
     * @return array{partner_id: ?string, partner_name: ?string}
     */
    public static function for(?string $partnerId): array
    {
        if (blank($partnerId)) {
            return ['partner_id' => null, 'partner_name' => null];
        }

        self::$names ??= Partner::query()->pluck('name', 'id')->all();

        return ['partner_id' => $partnerId, 'partner_name' => self::$names[$partnerId] ?? null];
    }

    /**
     * Gắn partner_name vào từng model của danh sách trả thẳng Eloquent (partner_id đã là cột sẵn có) —
     * dùng cho các API kho, nơi response là paginator của model thay vì mảng tự dựng.
     *
     * @param  iterable<\Illuminate\Database\Eloquent\Model>  $models
     */
    public static function annotate(iterable $models): void
    {
        foreach ($models as $model) {
            $model->setAttribute('partner_name', self::for($model->partner_id)['partner_name']);
        }
    }

    // Dùng cho test / process chạy lâu (queue worker) khi tên đối tác có thể đổi giữa các lần gọi.
    public static function flush(): void
    {
        self::$names = null;
    }
}
