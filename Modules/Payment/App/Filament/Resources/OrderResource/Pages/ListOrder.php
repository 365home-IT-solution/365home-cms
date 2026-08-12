<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Pages;

use Filament\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Payment\App\Exports\OrdersExport;
use Modules\Payment\App\Exports\CustomerOrderCountExport;
use Modules\Payment\App\Filament\Resources\OrderResource;

class ListOrder extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()?->can('create_order') ?? false),

            // ACTION: XUẤT EXCEL
            Actions\Action::make('export')
                ->label('Xuất Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->requiresConfirmation()
                ->color('success')
                ->form([
                  DateTimePicker::make('date_from')
                    ->label('Từ ngày')
                    ->native(false)
                            ->seconds(false)
                            ->timezone('Asia/Ho_Chi_Minh')
                            ->displayFormat('d/m/Y H:i'),
                DateTimePicker::make('date_to')
                    ->label('Đến ngày')
                    ->native(false)
                            ->seconds(false)
                            ->timezone('Asia/Ho_Chi_Minh')
                            ->displayFormat('d/m/Y H:i'),
                    Select::make('status')->label('Trạng thái')->options(['pending' => 'Đang chờ','paid' => 'Đã thanh toán','deposit' => 'Đã đặt cọc','failed' => 'Thất bại','cancelled_payment' => 'Hủy QR','refunded' => 'Hoàn tiền']),
                    Select::make('payment_method')->label('Phương thức thanh toán')->options(['PayOS' => 'PayOS','cod' => 'Tiền mặt']),
                ])
                ->action(function (array $data) {
                    $user             = auth()->user();
                    $allowedBranchIds = ($user && ! $user->isSuperAdmin()) ? $user->allowedBranchIds() : null;

                    $fileName = 'orders_' . now()->format('Y-m-d_His') . '.xlsx';
                    return Excel::download(new OrdersExport($data, null, $allowedBranchIds), $fileName);
                }),

            // ACTION: XUẤT DANH SÁCH KHÁCH HÀNG (GỘP THEO SỐ ĐIỆN THOẠI) - CHỈ SUPER ADMIN
            Actions\Action::make('export_customers')
                ->label('Xuất khách hàng')
                ->icon('heroicon-o-users')
                ->requiresConfirmation()
                ->color('info')
                ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
                ->form([
                    DateTimePicker::make('date_from')
                        ->label('Từ ngày')
                        ->native(false)
                        ->seconds(false)
                        ->timezone('Asia/Ho_Chi_Minh')
                        ->displayFormat('d/m/Y H:i'),
                    DateTimePicker::make('date_to')
                        ->label('Đến ngày')
                        ->native(false)
                        ->seconds(false)
                        ->timezone('Asia/Ho_Chi_Minh')
                        ->displayFormat('d/m/Y H:i'),
                    Select::make('status')->label('Trạng thái')->options(['pending' => 'Đang chờ','paid' => 'Đã thanh toán','deposit' => 'Đã đặt cọc','failed' => 'Thất bại','cancelled_payment' => 'Hủy QR','refunded' => 'Hoàn tiền']),
                ])
                ->action(function (array $data) {
                    $fileName = 'khach_hang_' . now()->format('Y-m-d_His') . '.xlsx';
                    return Excel::download(new CustomerOrderCountExport($data), $fileName);
                }),
        ];
    }
}