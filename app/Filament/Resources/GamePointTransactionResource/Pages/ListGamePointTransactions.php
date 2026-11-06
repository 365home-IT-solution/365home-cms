<?php

declare(strict_types=1);

namespace App\Filament\Resources\GamePointTransactionResource\Pages;

use App\Filament\Resources\GamePointTransactionResource;
use App\Models\Customer;
use App\Services\GamePointService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

class ListGamePointTransactions extends ListRecords
{
    protected static string $resource = GamePointTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('adjust')
                ->label('Cộng / trừ điểm')
                ->icon('heroicon-o-plus-circle')
                ->modalHeading('Cộng / trừ điểm game')
                ->modalSubmitActionLabel('Lưu')
                ->form([
                    Select::make('customer_id')->label('Khách hàng')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Customer::where('fullname', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")
                            ->limit(20)->get()->mapWithKeys(fn (Customer $c) => [$c->id => "{$c->fullname} — {$c->phone}"]))
                        ->getOptionLabelUsing(fn ($value) => ($c = Customer::find($value)) ? "{$c->fullname} — {$c->phone}" : $value)
                        ->required(),
                    TextInput::make('amount')->label('Số điểm')->helperText('Số âm để trừ điểm.')
                        ->numeric()->integer()->minValue(-1000000)->maxValue(1000000)->notIn([0])->required(),
                    TextInput::make('note')->label('Lý do')->maxLength(255)->required(),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        app(GamePointService::class)->adjust($data['customer_id'], (int) $data['amount'], $data['note'], auth()->user());
                    } catch (ValidationException $e) {
                        Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                        $action->halt();
                    }

                    Notification::make()->title('Đã cập nhật điểm')->success()->send();
                }),
        ];
    }
}
