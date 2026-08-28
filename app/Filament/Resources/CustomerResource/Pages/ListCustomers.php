<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerCheckinResource;
use App\Filament\Resources\CustomerResource;
use App\Models\MembershipTier;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Promotion\App\Exports\CustomerVoucherUsageExport;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('customerCheckin')
                ->label('Điểm danh khách hàng')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->url(CustomerCheckinResource::getUrl('index')),

            // ACTION: XUẤT KHÁCH HÀNG ĐÃ DÙNG VOUCHER - CHỈ SUPER ADMIN (cùng mức gate với
            // export_customers ở OrderResource vì dữ liệu chứa PII khách hàng).
            Action::make('export_voucher_usage')
                ->label('Xuất khách hàng đã dùng voucher')
                ->icon('heroicon-o-ticket')
                ->color('info')
                ->requiresConfirmation()
                ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
                ->form([
                    DatePicker::make('date_from')
                        ->label('Từ ngày')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->helperText('Bỏ trống để lấy toàn bộ thời gian.'),
                    DatePicker::make('date_to')
                        ->label('Đến ngày')
                        ->native(false)
                        ->displayFormat('d/m/Y'),
                ])
                ->action(function (array $data) {
                    $fileName = 'khach_hang_dung_voucher_' . now()->format('Y-m-d_His') . '.xlsx';
                    return Excel::download(new CustomerVoucherUsageExport($data), $fileName);
                }),

            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make('Tất cả'),
            'no_tier' => Tab::make('Chưa có hạng')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('membership_tier_id')),
        ];

        foreach (MembershipTier::where('is_active', true)->orderBy('sort_order')->get() as $tier) {
            $tabs[$tier->slug] = Tab::make($tier->name)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('membership_tier_id', $tier->id));
        }

        return $tabs;
    }
}
