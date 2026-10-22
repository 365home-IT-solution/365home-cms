<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Tables\BulkActions;

use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Actions\BulkAction;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Payment\App\Exports\OrdersExport;

class OrderBulkAction
{
    public static function bulkActions(): array
    {
        return [
            BulkAction::make('export_excel')
                ->label('Xuất Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(function ($records) {
                    $orderIds         = $records->pluck('id')->toArray();
                    $user             = auth()->user();
                    $allowedBranchIds = ($user && ! $user->isSuperAdmin()) ? $user->allowedBranchIds() : null;

                    return Excel::download(
                        new OrdersExport([], $orderIds, $allowedBranchIds),
                        'don-hang-da-chon-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),
            // Ẩn / hiện lại đơn ở trang khách "Tra cứu đơn đặt phòng" (/ticket-booking) — KHÔNG xoá đơn, doanh thu/đối soát không đổi.
            BulkAction::make('hide_from_lookup')
                ->label('Ẩn khỏi tra cứu đơn')
                ->icon('heroicon-o-eye-slash')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Các đơn đã chọn sẽ không còn hiện khi khách tra cứu đơn ở trang /ticket-booking. Đơn vẫn còn trong hệ thống, bạn vẫn thấy và hiện lại được.')
                ->deselectRecordsAfterCompletion()
                ->action(function ($records) {
                    $count = $records->count();
                    $records->each(fn ($order) => $order->newQuery()->whereKey($order->getKey())->update(['hidden_from_lookup' => true]));

                    Notification::make()->success()->title("Đã ẩn {$count} đơn khỏi trang tra cứu")->send();
                }),
            BulkAction::make('show_in_lookup')
                ->label('Hiện lại ở tra cứu đơn')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->requiresConfirmation()
                ->deselectRecordsAfterCompletion()
                ->action(function ($records) {
                    $count = $records->count();
                    $records->each(fn ($order) => $order->newQuery()->whereKey($order->getKey())->update(['hidden_from_lookup' => false]));

                    Notification::make()->success()->title("Đã hiện lại {$count} đơn ở trang tra cứu")->send();
                }),
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make()
            ]),
        ];
    }
}