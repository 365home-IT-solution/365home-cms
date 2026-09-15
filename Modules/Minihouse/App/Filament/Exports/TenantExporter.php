<?php

namespace Modules\Minihouse\App\Filament\Exports;

use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Modules\Minihouse\App\Models\Tenant;

// Xuất Excel danh sách Khách thuê — gắn qua ExportAction/ExportBulkAction ở TenantTable. Yêu cầu
// người dùng 2026-09-12: danh sách Khách thuê/Hoá đơn/Hợp đồng/Thu chi chưa có nút xuất Excel để đối
// chiếu kế toán, chỉ có báo cáo tài chính tổng hợp (FinanceReports) và xuất riêng Khai báo tạm trú.
// KHÔNG xuất id_card_number (số CCCD) mặc định — cùng nguyên tắc PII đã áp dụng ở
// Tenant::activityExcludedFields(), tránh phát tán số CCCD ra file Excel tải về máy một cách vô tình.
class TenantExporter extends Exporter
{
    protected static ?string $model = Tenant::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('fullname')->label('Họ tên'),
            ExportColumn::make('phone')->label('Số điện thoại'),
            ExportColumn::make('room.code')->label('Phòng đang ở'),
            ExportColumn::make('room.building.name')->label('Toà nhà'),
            ExportColumn::make('date_of_birth')->label('Ngày sinh')->formatStateUsing(fn ($state) => $state?->format('d/m/Y')),
            ExportColumn::make('gender')->label('Giới tính')->formatStateUsing(fn (?string $state) => match ($state) {
                Tenant::GENDER_MALE   => 'Nam',
                Tenant::GENDER_FEMALE => 'Nữ',
                Tenant::GENDER_OTHER  => 'Khác',
                default => '',
            }),
            ExportColumn::make('occupation')->label('Nghề nghiệp'),
            ExportColumn::make('workplace')->label('Nơi làm việc'),
            ExportColumn::make('emergency_contact_name')->label('Người liên hệ khẩn cấp'),
            ExportColumn::make('emergency_contact_phone')->label('SĐT liên hệ khẩn cấp'),
            ExportColumn::make('residence_declared')->label('Đã khai báo tạm trú')->formatStateUsing(fn (bool $state) => $state ? 'Đã khai báo' : 'Chưa khai báo'),
            ExportColumn::make('created_at')->label('Ngày tạo')->formatStateUsing(fn ($state) => $state?->format('d/m/Y H:i')),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Đã xuất xong ' . number_format($export->successful_rows) . ' ' . str('khách thuê')->plural($export->successful_rows) . '.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' dòng bị lỗi, không xuất được.';
        }

        return $body;
    }
}
