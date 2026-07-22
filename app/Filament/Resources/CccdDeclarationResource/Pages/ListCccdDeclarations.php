<?php

declare(strict_types=1);

namespace App\Filament\Resources\CccdDeclarationResource\Pages;

use App\Filament\Resources\CccdDeclarationResource;
use App\Models\CccdDeclaration;
use App\Services\CccdDeclarationExcelExporter;
use Filament\Actions\Action;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListCccdDeclarations extends ListRecords
{
    protected static string $resource = CccdDeclarationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Chỉ xuất đúng nhóm "Cần khai báo hôm nay" (chưa khai báo + đã/đang tới hạn trong
            // ngày) — KHÔNG xuất toàn bộ lịch sử, vì KBTT chỉ chấp nhận "Ngày đến" là hôm nay/hôm
            // qua (xem CccdDeclarationExcelExporter); khách chưa tới hạn xuất ra cũng vô nghĩa.
            Action::make('exportExcel')
                ->label('Xuất Excel (cần khai báo hôm nay)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => app(CccdDeclarationExcelExporter::class)
                    ->stream('ThongBaoLuuTru_' . now()->format('Ymd_His') . '.xlsx')),
        ];
    }

    // Mặc định mở tab "Cần khai báo hôm nay" — đúng ý nghĩa "cuối ngày vào xem thì thấy đúng khách
    // phải nộp trong ngày hôm nay", thay vì lọc theo ngày ĐẶT ĐƠN như trước (sai mốc thời gian).
    public function getDefaultActiveTab(): string | int | null
    {
        return 'today';
    }

    public function getTabs(): array
    {
        // Tính 1 lần rồi tái sử dụng cho cả badge lẫn query của từng tab — tránh chạy trùng 2 lần
        // (lọc bằng PHP theo declarationDeadline(), xem CccdDeclaration::idsNeedingDeclarationToday()).
        $todayIds    = CccdDeclaration::idsNeedingDeclarationToday();
        $upcomingIds = CccdDeclaration::idsUpcomingDeclaration();

        return [
            'today' => Tab::make('Cần khai báo hôm nay')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('id', $todayIds))
                ->badge(count($todayIds)),

            'upcoming' => Tab::make('Sắp đến hạn')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('id', $upcomingIds))
                ->badge(count($upcomingIds)),

            'declared' => Tab::make('Đã khai báo')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('declared_at'))
                ->badge(CccdDeclaration::whereNotNull('declared_at')->count()),

            'all' => Tab::make('Tất cả'),
        ];
    }
}
