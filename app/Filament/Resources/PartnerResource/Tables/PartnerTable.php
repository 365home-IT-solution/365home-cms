<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerResource\Tables;

use App\Models\Partner;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PartnerTable
{
    private const STATUS_LABELS = [
        'pending'   => 'Chờ phê duyệt',
        'approved'  => 'Đang hoạt động',
        'suspended' => 'Ngừng hoạt động',
        'rejected'  => 'Từ chối',
    ];

    private const STATUS_COLORS = [
        'pending'   => 'warning',
        'approved'  => 'success',
        'suspended' => 'gray',
        'rejected'  => 'danger',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Tên đối tác')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('id')
                    ->label('Mã đối tác')
                    ->formatStateUsing(fn (string $state): string => 'PART-' . strtoupper(substr($state, 0, 6)))
                    ->copyable(),

                TextColumn::make('representative_name')
                    ->label('Người liên hệ')
                    ->placeholder('—')
                    ->description(fn (Partner $record) => $record->email, position: 'below'),

                TextColumn::make('verification_status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => self::STATUS_COLORS[$state] ?? 'gray')
                    ->sortable(),

                TextColumn::make('categories_count')
                    ->label('Số cơ sở')
                    ->counts('categories')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('created_at')
                    ->label('Ngày đăng ký')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('verification_status')
                    ->label('Trạng thái')
                    ->options(self::STATUS_LABELS),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                ViewAction::make()->label('Xem'),
                EditAction::make()->label('Sửa'),
                DeleteAction::make()->label('Xóa'),
            ]);
    }
}
