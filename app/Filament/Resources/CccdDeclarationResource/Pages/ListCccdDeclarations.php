<?php

declare(strict_types=1);

namespace App\Filament\Resources\CccdDeclarationResource\Pages;

use App\Filament\Resources\CccdDeclarationResource;
use App\Models\CccdDeclaration;
use App\Services\CccdDeclarationExcelExporter;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Category\Entities\Category;

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

            // Xuất theo khoảng ngày tuỳ chọn (kèm chọn chi nhánh) — dùng để tra cứu/báo cáo, KHÁC
            // với nút "Xuất Excel (cần khai báo hôm nay)" ở trên vốn chỉ phục vụ nộp KBTT trong
            // ngày. Vẫn dùng chung mẫu chính thức tblt_vn_import.xlsx qua CccdDeclarationExcelExporter,
            // chỉ khác tập bản ghi (ids) được lọc theo ngày đến + chi nhánh do nhân viên tự chọn.
            Action::make('exportExcelByDateRange')
                ->label('Xuất Excel theo khoảng ngày')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->modalHeading('Xuất Excel khai báo lưu trú theo khoảng ngày')
                ->modalSubmitActionLabel('Xuất Excel')
                ->form([
                    Grid::make(2)->schema([
                        DatePicker::make('from')
                            ->label('Từ ngày (ngày đến)')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y'),

                        DatePicker::make('until')
                            ->label('Đến ngày (ngày đến)')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->afterOrEqual('from'),
                    ]),

                    Select::make('category_ids')
                        ->label('Chi nhánh')
                        ->helperText('Chọn 1 hoặc nhiều chi nhánh cần xuất.')
                        ->multiple()
                        ->searchable()
                        ->required()
                        ->options(function () {
                            $user  = auth()->user();
                            $query = Category::query()
                                ->where('category_type', 'product')
                                ->whereNull('parent_id');

                            // Không phải super_admin: chỉ được chọn trong chi nhánh của đối tác
                            // mình, và thu hẹp thêm theo allowedBranchIds() nếu có gán riêng —
                            // giống đúng cách lọc "Chi nhánh" ở OrderFilter.
                            if (! $user?->isSuperAdmin()) {
                                $query->where('partner_id', $user?->partner_id);

                                $allowedIds = $user?->allowedBranchIds() ?? [];
                                if (! empty($allowedIds)) {
                                    $query->whereIn('id', $allowedIds);
                                }
                            }

                            return $query->pluck('name', 'id');
                        }),
                ])
                ->action(function (array $data) {
                    // Chi nhánh đã chọn là category gốc — mở rộng thêm cả khu vực con (đơn hàng có
                    // thể gắn category_id là 1 khu vực con của chi nhánh gốc), cùng quy ước với
                    // OrderFilter::filter() (category_id).
                    $categoryIds = Category::query()
                        ->where(function (Builder $q) use ($data) {
                            $q->whereIn('id', $data['category_ids'])
                                ->orWhereIn('parent_id', $data['category_ids']);
                        })
                        ->pluck('id')
                        ->all();

                    $ids = CccdDeclaration::idsForDateRangeExport($data['from'], $data['until'], $categoryIds);

                    if (empty($ids)) {
                        Notification::make()
                            ->title('Không có dữ liệu khai báo lưu trú trong khoảng ngày/chi nhánh đã chọn')
                            ->warning()
                            ->send();

                        return;
                    }

                    return app(CccdDeclarationExcelExporter::class)
                        ->stream('ThongBaoLuuTru_' . now()->format('Ymd_His') . '.xlsx', $ids);
                }),
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
