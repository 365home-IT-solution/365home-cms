<?php

declare(strict_types=1);

namespace Modules\VehicleRental\App\Filament\Resources\VehicleResource\RelationManagers;

use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\VehicleRental\App\Services\VehicleAvailabilityService;

// Khoá xe theo khoảng thời gian (bảo dưỡng, sửa chữa, đăng kiểm...). Không cho khoá chồng lên đơn thuê đang giữ xe.
class BlocksRelationManager extends RelationManager
{
    protected static string $relationship = 'blocks';

    protected static ?string $title = 'Khoá xe';

    protected static ?string $modelLabel = 'khoảng khoá';

    public function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\DateTimePicker::make('start_at')->label('Từ')->seconds(false)->required(),
            Forms\Components\DateTimePicker::make('end_at')->label('Đến')->seconds(false)->required()->after('start_at'),
            Forms\Components\TextInput::make('reason')->label('Lý do')->maxLength(255)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reason')
            ->defaultSort('start_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('start_at')->label('Từ')->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('end_at')->label('Đến')->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('reason')->label('Lý do')->placeholder('—'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Thêm khoảng khoá')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['created_by'] = auth()->id();

                        return $data;
                    })
                    ->before(fn (Tables\Actions\CreateAction $action, array $data) => $this->haltOnConflict($action, $data)),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->before(fn (Tables\Actions\EditAction $action, array $data) => $this->haltOnConflict($action, $data)),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    private function haltOnConflict($action, array $data): void
    {
        $conflicts = app(VehicleAvailabilityService::class)
            ->conflictingBookings(Carbon::parse($data['start_at']), Carbon::parse($data['end_at']))
            ->where('vehicle_id', $this->getOwnerRecord()->id)
            ->pluck('code');

        if ($conflicts->isNotEmpty()) {
            Notification::make()->danger()->title('Khoảng khoá trùng đơn thuê đang giữ xe')
                ->body('Đơn: ' . $conflicts->implode(', ') . '. Xử lý các đơn này trước.')->send();
            $action->halt();
        }
    }
}
