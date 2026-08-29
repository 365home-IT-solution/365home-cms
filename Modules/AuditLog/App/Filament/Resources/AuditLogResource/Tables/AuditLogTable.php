<?php

declare(strict_types=1);

namespace Modules\AuditLog\App\Filament\Resources\AuditLogResource\Tables;

use App\Filament\Support\PartnerTableHelpers;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Forms\Components\DatePicker;
use Illuminate\Support\HtmlString;
use Modules\AuditLog\Entities\AuditLog;

class AuditLogTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Thời gian')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable()
                    ->width('150px'),

                TextColumn::make('user_name')
                    ->label('Người thực hiện')
                    ->description(fn ($record) => $record->user_email)
                    ->searchable(['user_name', 'user_email'])
                    ->weight(FontWeight::Medium),

                BadgeColumn::make('performer_role')
                    ->label('Role')
                    ->colors([
                        'danger'  => 'super_admin',
                        'warning' => 'admin',
                        'primary' => 'manager',
                        'gray'    => fn ($state) => ! in_array($state, ['super_admin', 'admin', 'manager']),
                    ]),

                BadgeColumn::make('action')
                    ->label('Thao tác')
                    ->colors([
                        'success' => 'create',
                        'warning' => 'update',
                        'danger'  => 'delete',
                    ])
                    ->formatStateUsing(fn ($state) => AuditLog::actionLabels()[$state] ?? $state),

                TextColumn::make('module')
                    ->label('Đối tượng')
                    ->description(fn ($record) => $record->target_label)
                    ->formatStateUsing(fn ($state) => AuditLog::moduleLabels()[$state] ?? $state),
                PartnerTableHelpers::column(),
            ])
            ->filters([
                PartnerTableHelpers::filter(),

                // Lọc theo ĐÚNG 1 tài khoản — danh sách lấy từ chính các tác giả đã từng xuất hiện
                // trong audit_logs (query trên model AuditLog nên tự động thừa hưởng đúng scope
                // hiện có: BelongsToPartner thu hẹp theo đối tác cho tài khoản không phải
                // super_admin), tránh phải gõ tìm tên/email thủ công như trước.
                SelectFilter::make('user_id')
                    ->label('Tài khoản')
                    ->options(fn () => AuditLog::query()
                        ->select('user_id', 'user_name', 'user_email')
                        ->distinct()
                        ->orderBy('user_name')
                        ->get()
                        ->mapWithKeys(fn ($row) => [
                            $row->user_id => $row->user_name . ' (' . $row->user_email . ')',
                        ])
                        ->all())
                    ->searchable()
                    ->placeholder('Tất cả'),

                SelectFilter::make('module')
                    ->label('Module')
                    ->options(AuditLog::moduleLabels())
                    ->placeholder('Tất cả'),

                SelectFilter::make('action')
                    ->label('Thao tác')
                    ->options(AuditLog::actionLabels())
                    ->placeholder('Tất cả'),

                Filter::make('created_at')
                    ->label('Khoảng thời gian')
                    ->form([
                        DatePicker::make('from')->label('Từ ngày')->displayFormat('d/m/Y'),
                        DatePicker::make('until')->label('Đến ngày')->displayFormat('d/m/Y'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'], fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
                            ->when($data['until'], fn ($q, $v) => $q->whereDate('created_at', '<=', $v));
                    }),
            ])
            ->actions([
                Action::make('detail')
                    ->label('Chi tiết')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('gray')
                    ->modalHeading(fn ($record) => 'Chi tiết thao tác — ' . (AuditLog::moduleLabels()[$record->module] ?? $record->module))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
                    ->modalWidth('lg')
                    ->modalContent(fn ($record) => new HtmlString(static::buildDetailHtml($record))),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function buildDetailHtml(AuditLog $record): string
    {
        $actionLabel = AuditLog::actionLabels()[$record->action] ?? $record->action;
        $moduleLabel = AuditLog::moduleLabels()[$record->module] ?? $record->module;

        $actionColor = match ($record->action) {
            'create' => ['bg' => '#dcfce7', 'color' => '#166534'],
            'update' => ['bg' => '#fef9c3', 'color' => '#854d0e'],
            'delete' => ['bg' => '#fee2e2', 'color' => '#991b1b'],
            default  => ['bg' => '#f3f4f6', 'color' => '#374151'],
        };

        $html = '
        <div style="font-family:sans-serif;padding:4px 0;">
            <!-- Header thông tin -->
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:16px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div>
                        <div style="font-size:11px;color:#6b7280;margin-bottom:3px;">Người thực hiện</div>
                        <div style="font-weight:600;font-size:14px;color:#111827;">' . e($record->user_name) . '</div>
                        <div style="font-size:12px;color:#6b7280;">' . e($record->user_email) . '</div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:#6b7280;margin-bottom:3px;">Thời gian</div>
                        <div style="font-weight:600;font-size:14px;color:#111827;">' . $record->created_at?->format('d/m/Y H:i:s') . '</div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:#6b7280;margin-bottom:3px;">IP</div>
                        <div style="font-size:13px;color:#374151;">' . e($record->ip_address ?? '—') . '</div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:#6b7280;margin-bottom:3px;">Thao tác</div>
                        <div style="display:inline-flex;align-items:center;background:' . $actionColor['bg'] . ';color:' . $actionColor['color'] . ';padding:3px 10px;border-radius:999px;font-weight:700;font-size:13px;">
                            ' . e($actionLabel) . ' — ' . e($moduleLabel) . '
                        </div>
                    </div>
                </div>
                ' . ($record->target_label ? '<div style="margin-top:10px;font-size:12px;color:#6b7280;">Đối tượng: <span style="font-weight:600;color:#374151;">' . e($record->target_label) . '</span></div>' : '') . '
            </div>';

        // Bảng giá trị thay đổi
        if ($record->action === 'create' && ! empty($record->new_values)) {
            $html .= static::buildValueTable('Giá trị đã tạo', $record->new_values, '#dcfce7', '#166534', $record->module);
        } elseif ($record->action === 'delete' && ! empty($record->old_values)) {
            $html .= static::buildValueTable('Giá trị đã xóa', $record->old_values, '#fee2e2', '#991b1b', $record->module);
        } elseif ($record->action === 'update') {
            if (! empty($record->old_values) || ! empty($record->new_values)) {
                $html .= static::buildCompareTable($record->old_values ?? [], $record->new_values ?? [], $record->module);
            }
        }

        $html .= '</div>';

        return $html;
    }

    // Nhãn tiếng Việt cho các field kỹ thuật hay gặp — field nào không có trong danh sách thì hiện
    // nguyên tên cột (còn hơn không hiện gì, và field đặc thù từng module quá nhiều để liệt kê hết).
    private const FIELD_LABELS = [
        'partner_id'   => 'Đối tác',
        'branch_id'    => 'Chi nhánh',
        'category_id'  => 'Danh mục',
        'created_by'   => 'Người tạo',
        'updated_by'   => 'Người sửa',
        'user_id'      => 'Tài khoản',
        'customer_id'  => 'Khách hàng',
        'code'         => 'Mã',
        'name'         => 'Tên',
        'status'       => 'Trạng thái',
        'is_active'    => 'Đang hoạt động',
        'description'  => 'Mô tả',
        'quantity'     => 'Số lượng',
        'price'        => 'Giá',
        'value'        => 'Giá trị',
        'type'         => 'Loại',
        'tien_ich_da_them' => 'Tiện ích đã thêm',
        'tien_ich_da_bo'   => 'Tiện ích đã bỏ',
        'thay_doi'         => 'Chi tiết thay đổi',
        'diff'             => 'Chênh lệch tiền',
        'change_summary'   => 'Chi tiết thay đổi',
        'dich_vu_da_them'  => 'Dịch vụ đã thêm',
        'dich_vu_da_bo'    => 'Dịch vụ đã bỏ',
        'phong_qua_dem_da_them' => 'Phòng qua đêm đã thêm',
        'phong_qua_dem_da_bo'   => 'Phòng qua đêm đã bỏ',
        'danh_sach_section'     => 'Danh sách section',
        'phong_da_them'       => 'Phòng đã thêm',
        'phong_da_bo'         => 'Phòng đã bỏ',
        'khung_gio_da_them'   => 'Khung giờ đã thêm',
        'khung_gio_da_bo'     => 'Khung giờ đã bỏ',
        'chi_nhanh_da_them'   => 'Chi nhánh đã thêm',
        'chi_nhanh_da_bo'     => 'Chi nhánh đã bỏ',
        'vai_tro_da_them'     => 'Vai trò đã thêm',
        'vai_tro_da_bo'       => 'Vai trò đã bỏ',
        'ma_giam_gia_da_them' => 'Mã giảm giá đã thêm',
        'ma_giam_gia_da_bo'   => 'Mã giảm giá đã bỏ',
        'partner_id_moi'          => 'Đối tác mới',
        'so_phong_bi_anh_huong'   => 'Số phòng bị ảnh hưởng',
        'so_booking_bi_anh_huong' => 'Số booking bị ảnh hưởng',
        'so_ma_khoa_bi_anh_huong' => 'Số mã khoá bị ảnh hưởng',

        // Field phổ biến dùng chung ở nhiều module (khảo sát fillable() của toàn bộ model đang gắn
        // LogsAuditTrail) — thêm 1 lần ở đây để áp dụng chung, không phải sửa từng nơi ghi log.
        'sort_order'      => 'Thứ tự',
        'slug'            => 'Đường dẫn',
        'image'           => 'Hình ảnh',
        'image_width'     => 'Chiều rộng ảnh',
        'image_height'    => 'Chiều cao ảnh',
        'title'           => 'Tiêu đề',
        'icon'            => 'Biểu tượng',
        'note'            => 'Ghi chú',
        'notes'           => 'Ghi chú',
        'phone'           => 'Số điện thoại',
        'phone_number'    => 'Số điện thoại',
        'url'             => 'Đường dẫn',
        'disk'            => 'Ổ lưu trữ',
        'date_of_birth'   => 'Ngày sinh',
        'fullname'        => 'Họ tên',
        'email'           => 'Email',
        'address'         => 'Địa chỉ',
        'username'        => 'Tên đăng nhập',
        'tax_code'        => 'Mã số thuế',
        'valid_from'      => 'Hiệu lực từ',
        'valid_until'     => 'Hiệu lực đến',
        'sent_at'         => 'Đã gửi lúc',
        'received_at'     => 'Ngày nhận hàng',
        'total_amount'    => 'Tổng tiền',
        'error_message'   => 'Lỗi',
        'order_code'      => 'Mã đơn hàng',
        'buyer_name'      => 'Tên người mua',
        'buyer_email'     => 'Email người mua',
        'buyer_phone'     => 'Số điện thoại người mua',
        'amount'          => 'Số tiền',
    ];

    // Các field lưu dạng boolean/tinyint (true/false, 1/0) trên nhiều model khác nhau (Category,
    // Partner, Product, Coupon, Promotion, TtlockAccount, WarehouseItem...) — hiển thị RAW "1"/"0"
    // (hoặc rỗng khi giá trị là false, vì (string) false === '') gây khó hiểu, phải đổi thành nhãn
    // tiếng Việt rõ nghĩa thay vì hiện đúng giá trị thô trong DB.
    private const BOOLEAN_FLAG_FIELDS = [
        'status', 'is_active', 'is_activated', 'is_enabled', 'active',
        'is_default', 'is_published',
    ];

    private static function fieldLabel(string $field): string
    {
        return self::FIELD_LABELS[$field] ?? $field;
    }

    private static function isBooleanFlagValue(string $field, mixed $value): bool
    {
        if (! in_array($field, self::BOOLEAN_FLAG_FIELDS, true)) {
            return false;
        }

        return is_bool($value) || in_array($value, [0, 1, '0', '1'], true);
    }

    private static function booleanFlagLabel(string $field, mixed $value): string
    {
        $isTrue = filter_var($value, FILTER_VALIDATE_BOOLEAN);

        [$onLabel, $offLabel] = match ($field) {
            'is_default'   => ['Mặc định', 'Không phải mặc định'],
            'is_published' => ['Đã xuất bản', 'Chưa xuất bản'],
            default        => ['Đang hoạt động', 'Ngừng hoạt động'],
        };

        return $isTrue ? $onLabel : $offLabel;
    }

    // Nhãn tiếng Việt cho các field lưu dạng chuỗi enum (không phải boolean) — khoá theo
    // "Module.field", tái dùng ĐÚNG nhãn đã có sẵn trong file lang của module đó (order.php) để nhất
    // quán với chữ hiển thị trên các trang khác của admin, không tự bịa nhãn mới.
    private static function moduleEnumLabel(?string $module, string $field, mixed $value): ?string
    {
        if ($module === 'Order' && $field === 'status') {
            $key   = 'payment::order.table.status.' . $value;
            $label = __($key);

            return $label !== $key ? $label : null;
        }

        return null;
    }

    private static function formatFieldValue(string $field, mixed $value, ?string $module = null): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_array($value)) {
            return implode(', ', $value);
        }

        if (static::isBooleanFlagValue($field, $value)) {
            return static::booleanFlagLabel($field, $value);
        }

        $enumLabel = static::moduleEnumLabel($module, $field, $value);
        if ($enumLabel !== null) {
            return $enumLabel;
        }

        return (string) $value;
    }

    private static function buildValueTable(string $title, array $values, string $bg, string $color, ?string $module = null): string
    {
        $rows = '';
        foreach ($values as $field => $value) {
            $displayValue = static::formatFieldValue($field, $value, $module);
            $rows .= '
                <tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:8px 12px;font-size:13px;color:#6b7280;white-space:nowrap;">' . e(static::fieldLabel($field)) . '</td>
                    <td style="padding:8px 12px;font-size:13px;color:#111827;">' . e($displayValue) . '</td>
                </tr>';
        }

        return '
        <div style="margin-bottom:12px;">
            <div style="font-weight:700;font-size:13px;color:' . $color . ';background:' . $bg . ';padding:8px 12px;border-radius:8px 8px 0 0;">' . e($title) . '</div>
            <table style="width:100%;border-collapse:collapse;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;overflow:hidden;">
                <tbody>' . $rows . '</tbody>
            </table>
        </div>';
    }

    private static function buildCompareTable(array $old, array $new, ?string $module = null): string
    {
        $fields = array_unique(array_merge(array_keys($old), array_keys($new)));
        $rows   = '';

        foreach ($fields as $field) {
            // array_key_exists (không phải isset) — false/0/null vẫn là 1 giá trị THẬT SỰ đã ghi
            // nhận, không phải "không có dữ liệu". isset() coi null là "không có", khiến field đổi
            // TỪ có giá trị SANG null hiện nhầm thành "—" ở cả 2 cột thay vì thấy được đã xoá giá trị.
            $oldVal = array_key_exists($field, $old) ? static::formatFieldValue($field, $old[$field], $module) : '—';
            $newVal = array_key_exists($field, $new) ? static::formatFieldValue($field, $new[$field], $module) : '—';

            $rows .= '
                <tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:8px 12px;font-size:13px;color:#6b7280;white-space:nowrap;">' . e(static::fieldLabel($field)) . '</td>
                    <td style="padding:8px 12px;font-size:13px;color:#991b1b;background:#fff5f5;">' . e($oldVal) . '</td>
                    <td style="padding:8px 12px;font-size:13px;color:#166534;background:#f0fdf4;">' . e($newVal) . '</td>
                </tr>';
        }

        return '
        <div style="margin-bottom:12px;">
            <table style="width:100%;border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="padding:8px 12px;font-size:12px;color:#6b7280;text-align:left;">Trường</th>
                        <th style="padding:8px 12px;font-size:12px;color:#991b1b;text-align:left;">Giá trị cũ</th>
                        <th style="padding:8px 12px;font-size:12px;color:#166534;text-align:left;">Giá trị mới</th>
                    </tr>
                </thead>
                <tbody>' . $rows . '</tbody>
            </table>
        </div>';
    }
}
