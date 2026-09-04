<?php

namespace Modules\Minihouse\App\Filament\Widgets;

use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Modules\Minihouse\App\Models\Reminder;

class UpcomingRemindersWidget extends TableWidget
{
    protected static ?string $heading = 'Nhắc việc sắp tới';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Reminder::query()
                    ->where('is_done', false)
                    ->orderBy('remind_date')
            )
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Tiêu đề'),
                Tables\Columns\TextColumn::make('remind_date')->label('Ngày nhắc')->date('d/m/Y'),
                Tables\Columns\TextColumn::make('room.code')->label('Phòng'),
            ])
            ->paginated([5])
            ->emptyStateHeading('Không có việc cần nhắc');
    }
}
