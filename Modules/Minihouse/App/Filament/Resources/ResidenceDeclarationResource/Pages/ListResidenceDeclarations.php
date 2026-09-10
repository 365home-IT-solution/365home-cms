<?php

namespace Modules\Minihouse\App\Filament\Resources\ResidenceDeclarationResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Minihouse\App\Filament\Resources\ResidenceDeclarationResource;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\ResidenceDeclaration;
use Modules\Minihouse\App\Services\ResidenceDeclarationExcelExporter;

class ListResidenceDeclarations extends ListRecords
{
    protected static string $resource = ResidenceDeclarationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Xuất Excel (cần khai báo hôm nay)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => app(ResidenceDeclarationExcelExporter::class)
                    ->stream('ThongBaoLuuTru_' . now()->format('Ymd_His') . '.xlsx')),

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
                    Select::make('building_ids')
                        ->label('Toà nhà')
                        ->helperText('Chọn 1 hoặc nhiều toà nhà cần xuất — để trống = tất cả.')
                        ->multiple()
                        ->searchable()
                        ->options(fn () => Building::query()->pluck('name', 'id')),
                ])
                ->action(function (array $data) {
                    $ids = ResidenceDeclaration::idsForDateRangeExport(
                        $data['from'],
                        $data['until'],
                        $data['building_ids'] ?? []
                    );

                    if (empty($ids)) {
                        Notification::make()
                            ->title('Không có dữ liệu khai báo lưu trú trong khoảng ngày/toà nhà đã chọn')
                            ->warning()
                            ->send();

                        return;
                    }

                    return app(ResidenceDeclarationExcelExporter::class)
                        ->stream('ThongBaoLuuTru_' . now()->format('Ymd_His') . '.xlsx', $ids);
                }),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'today';
    }

    public function getTabs(): array
    {
        $todayIds    = ResidenceDeclaration::idsNeedingDeclarationToday();
        $upcomingIds = ResidenceDeclaration::idsUpcomingDeclaration();

        return [
            'today' => Tab::make('Cần khai báo hôm nay')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('id', $todayIds))
                ->badge(count($todayIds)),

            'upcoming' => Tab::make('Sắp đến hạn')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('id', $upcomingIds))
                ->badge(count($upcomingIds)),

            'declared' => Tab::make('Đã khai báo')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('declared_at'))
                ->badge(ResidenceDeclaration::whereNotNull('declared_at')->count()),

            'all' => Tab::make('Tất cả'),
        ];
    }
}
