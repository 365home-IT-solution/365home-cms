<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerCheckinResource;
use App\Filament\Resources\CustomerResource;
use App\Models\MembershipTier;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

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
