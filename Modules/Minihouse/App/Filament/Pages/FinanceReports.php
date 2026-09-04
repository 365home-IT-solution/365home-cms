<?php

namespace Modules\Minihouse\App\Filament\Pages;

use Filament\Pages\Page;
use Modules\Minihouse\App\Filament\Pages\Concerns\HasPlaceholderContent;

class FinanceReports extends Page
{
    use HasPlaceholderContent;

    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Thu chi & Báo cáo';
    protected static ?string $title           = 'Thu chi & báo cáo';
    protected static ?int $navigationSort     = 6;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('view_any_reports') ?? false);
    }

    protected static function getPageDescription(): string
    {
        return 'Các báo cáo doanh thu, công nợ, tỷ lệ lấp đầy — sổ thu chi chi tiết xem ở mục "Sổ thu chi".';
    }

    protected static function getItems(): array
    {
        return [
            ['title' => 'Báo cáo doanh thu', 'description' => 'Theo tháng / quý / năm.'],
            ['title' => 'Báo cáo công nợ', 'description' => 'Theo dõi khách còn nợ bao nhiêu.'],
            ['title' => 'Tỷ lệ lấp đầy', 'description' => 'Thống kê tỷ lệ phòng trống / đã thuê.'],
        ];
    }
}
