<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Partner;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;

// Cột + bộ lọc "Đối tác" dùng chung cho mọi Filament Table có model gắn partner_id (trực tiếp
// hoặc qua quan hệ) — để super_admin nhìn vào danh sách là biết bản ghi thuộc đối tác nào mà
// KHÔNG cần đối tác phải tự đặt tên gợi nhớ trong dữ liệu (tên phòng/coupon/... vẫn bình thường
// như khách hàng thấy ở trang chủ). Với tài khoản đối tác/nhân viên, dữ liệu vốn đã được
// getEloquentQuery() lọc sẵn nên bộ lọc chọn đối tác chỉ hiện với super_admin.
class PartnerTableHelpers
{
    // Bảng màu cố định, xoay vòng theo partner_id — mỗi đối tác luôn ra cùng 1 màu mỗi lần tải
    // lại trang (không cần đối tác tự cấu hình màu).
    private const COLOR_PALETTE = [
        'blue', 'emerald', 'amber', 'rose', 'indigo', 'cyan',
        'purple', 'orange', 'pink', 'lime', 'teal', 'fuchsia',
    ];

    public static function column(string $relationOrColumn = 'partner.name'): TextColumn
    {
        $idPath = preg_replace('/\.name$/', '.id', $relationOrColumn);

        return TextColumn::make($relationOrColumn)
            ->label('Đối tác')
            ->badge()
            ->color(fn ($record) => static::colorForPartnerId(data_get($record, $idPath)))
            ->tooltip(fn ($record) => data_get($record, $relationOrColumn)
                ? 'Thuộc đối tác: ' . data_get($record, $relationOrColumn)
                : 'Dữ liệu dùng chung / hệ thống — không thuộc đối tác nào')
            ->placeholder('— Dùng chung / dữ liệu hệ thống —')
            ->toggleable()
            ->sortable()
            ->searchable();
    }

    public static function filter(string $column = 'partner_id'): SelectFilter
    {
        return SelectFilter::make($column)
            ->label('Đối tác')
            ->relationship('partner', 'name')
            ->searchable()
            ->preload()
            // Chỉ super_admin mới cần lọc theo đối tác — tài khoản đối tác/nhân viên vốn chỉ
            // thấy dữ liệu của chính mình nên bộ lọc này không có ý nghĩa với họ.
            ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false);
    }

    // Dùng cho model không có partner_id trực tiếp mà lọc qua 1 quan hệ trung gian (vd
    // room_services/room_specials/room_images lọc qua product.partner_id).
    public static function filterThroughRelation(string $relation, string $column = 'partner_id'): SelectFilter
    {
        return SelectFilter::make($column)
            ->label('Đối tác')
            ->options(fn () => Partner::orderBy('name')->pluck('name', 'id'))
            ->searchable()
            ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
            ->query(function ($query, array $data) use ($relation, $column) {
                if (empty($data['value'])) {
                    return $query;
                }

                return $query->whereHas($relation, function ($q) use ($column, $data) {
                    $q->where($column, $data['value']);
                });
            });
    }

    // Cùng 1 partner_id luôn ra cùng 1 màu (hash ổn định), khác đối tác thường ra khác màu.
    private static function colorForPartnerId(?string $partnerId): string|array
    {
        if (! $partnerId) {
            return 'gray';
        }

        $colorName = self::COLOR_PALETTE[abs(crc32($partnerId)) % count(self::COLOR_PALETTE)];

        return constant(Color::class . '::' . ucfirst($colorName));
    }
}
