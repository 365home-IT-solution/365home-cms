<?php

namespace Modules\Minihouse\App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Transaction;

class MinihouseStatsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $totalRooms  = Room::count();
        $rentedRooms = Room::where('status', Room::STATUS_RENTED)->count();
        $emptyRooms  = Room::where('status', Room::STATUS_EMPTY)->count();

        $unpaidInvoiceTotal = Invoice::where('status', Invoice::STATUS_UNPAID)->sum('total_amount');

        $thisMonthIncome = Transaction::where('type', Transaction::TYPE_IN)
            ->whereBetween('transaction_date', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
            ->sum('amount');
        $thisMonthExpense = Transaction::where('type', Transaction::TYPE_OUT)
            ->whereBetween('transaction_date', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()])
            ->sum('amount');

        return [
            Stat::make('Toà nhà', Building::count())
                ->icon('heroicon-o-building-office-2'),

            Stat::make('Phòng', $totalRooms)
                ->description("{$rentedRooms} đang thuê · {$emptyRooms} trống")
                ->icon('heroicon-o-home-modern')
                ->color('success'),

            Stat::make('Hợp đồng đang hiệu lực', Contract::where('status', Contract::STATUS_ACTIVE)->count())
                ->icon('heroicon-o-document-text'),

            Stat::make('Hoá đơn chưa thanh toán', number_format((float) $unpaidInvoiceTotal, 0, ',', '.') . 'đ')
                ->icon('heroicon-o-receipt-percent')
                ->color($unpaidInvoiceTotal > 0 ? 'danger' : 'success'),

            Stat::make('Thu tháng này', number_format((float) $thisMonthIncome, 0, ',', '.') . 'đ')
                ->icon('heroicon-o-arrow-trending-up')
                ->color('success'),

            Stat::make('Chi tháng này', number_format((float) $thisMonthExpense, 0, ',', '.') . 'đ')
                ->icon('heroicon-o-arrow-trending-down')
                ->color('danger'),
        ];
    }
}
