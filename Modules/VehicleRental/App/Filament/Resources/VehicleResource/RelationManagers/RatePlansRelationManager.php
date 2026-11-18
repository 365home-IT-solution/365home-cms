<?php

declare(strict_types=1);

namespace Modules\VehicleRental\App\Filament\Resources\VehicleResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\VehicleRental\App\Models\VehicleRatePlan;
use Modules\VehicleRental\App\Services\VehicleRatePlanDefaults;

// Gói giá của 1 xe: gói giờ (4h/8h/12h...) + gói ngày 24h. Sửa ở đây KHÔNG đổi tiền của đơn đã đặt (đơn lưu snapshot giá).
class RatePlansRelationManager extends RelationManager
{
    protected static string $relationship = 'ratePlans';

    protected static ?string $title = 'Gói giá';

    protected static ?string $modelLabel = 'gói giá';

    public function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Forms\Components\TextInput::make('name')->label('Tên gói')->required()->maxLength(100),
            Forms\Components\Select::make('unit')->label('Loại gói')->native(false)->required()->live()
                ->options([VehicleRatePlan::UNIT_HOUR => 'Gói giờ', VehicleRatePlan::UNIT_DAY => 'Gói ngày (24h)', VehicleRatePlan::UNIT_KM => 'Gói theo km'])
                ->afterStateUpdated(fn ($state, Forms\Set $set) => in_array($state, [VehicleRatePlan::UNIT_DAY, VehicleRatePlan::UNIT_KM], true) ? $set('duration_hours', 24) : null),
            Forms\Components\TextInput::make('duration_hours')->label('Số giờ của gói')->numeric()->minValue(1)->maxValue(24)->required()
                ->visible(fn (Forms\Get $get) => $get('unit') !== VehicleRatePlan::UNIT_KM)
                ->helperText('Gói ngày luôn là 24 giờ.'),
            Forms\Components\TextInput::make('price')->label(fn (Forms\Get $get) => $get('unit') === VehicleRatePlan::UNIT_KM ? 'Giá mỗi km' : 'Giá ngày thường')->numeric()->minValue(1)->required()
                ->suffix(fn (Forms\Get $get) => $get('unit') === VehicleRatePlan::UNIT_KM ? 'đ/km' : 'đ'),
            Forms\Components\TextInput::make('min_km')->label('Km tối thiểu tính tiền')->numeric()->minValue(1)->suffix('km')
                ->visible(fn (Forms\Get $get) => $get('unit') === VehicleRatePlan::UNIT_KM)
                ->helperText('Để trống = không có mức tối thiểu. Khách chọn số km dự kiến lúc đặt, tiền chốt theo km thực tế lúc trả xe.'),
            Forms\Components\TextInput::make('weekend_price')->label('Giá cuối tuần (T7, CN)')->numeric()->minValue(1)->suffix('đ')
                ->visible(fn (Forms\Get $get) => $get('unit') === VehicleRatePlan::UNIT_DAY)
                ->helperText('Để trống = dùng giá ngày thường.'),
            Forms\Components\TextInput::make('included_km')->label('Km bao gồm')->numeric()->minValue(0)->suffix('km')
                ->visible(fn (Forms\Get $get) => $get('unit') !== VehicleRatePlan::UNIT_KM)
                ->helperText('Để trống = không giới hạn km. Gói ngày: km tính cho mỗi ngày.'),
            Forms\Components\TextInput::make('extra_km_price')->label('Giá mỗi km vượt')->numeric()->minValue(0)->default(0)->required()->suffix('đ/km')
                ->visible(fn (Forms\Get $get) => $get('unit') !== VehicleRatePlan::UNIT_KM),
            Forms\Components\TextInput::make('overtime_grace_minutes')->label('Ân hạn trả trễ')->numeric()->minValue(0)->default(30)->required()->suffix('phút')
                ->visible(fn (Forms\Get $get) => $get('unit') !== VehicleRatePlan::UNIT_KM),
            Forms\Components\TextInput::make('overtime_hour_price')->label('Giá mỗi giờ trễ')->numeric()->minValue(0)->default(0)->required()->suffix('đ/giờ')
                ->visible(fn (Forms\Get $get) => $get('unit') !== VehicleRatePlan::UNIT_KM),
            Forms\Components\TextInput::make('sort_order')->label('Thứ tự')->numeric()->default(0),
            Forms\Components\Toggle::make('is_active')->label('Đang bán')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        $money = fn ($state) => $state === null ? '—' : number_format((int) $state, 0, ',', '.') . 'đ';

        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Gói')->weight('bold'),
                Tables\Columns\TextColumn::make('unit')->label('Loại')->badge()
                    ->formatStateUsing(fn ($state) => match ($state) { VehicleRatePlan::UNIT_DAY => 'Gói ngày', VehicleRatePlan::UNIT_KM => 'Gói theo km', default => 'Gói giờ' }),
                Tables\Columns\TextColumn::make('duration_hours')->label('Giờ')->formatStateUsing(fn ($state, $record) => $record->unit === VehicleRatePlan::UNIT_KM ? '—' : $state . 'h'),
                Tables\Columns\TextColumn::make('price')->label('Giá')->formatStateUsing($money),
                Tables\Columns\TextColumn::make('weekend_price')->label('Cuối tuần')->formatStateUsing($money),
                Tables\Columns\TextColumn::make('included_km')->label('Km bao gồm')
                    ->formatStateUsing(fn ($state) => $state === null ? 'Không giới hạn' : $state . ' km'),
                Tables\Columns\TextColumn::make('extra_km_price')->label('Km vượt')->formatStateUsing(fn ($state) => $state ? $money($state) . '/km' : '—'),
                Tables\Columns\TextColumn::make('overtime_hour_price')->label('Giờ trễ')->formatStateUsing(fn ($state) => $state ? $money($state) . '/giờ' : '—'),
                Tables\Columns\IconColumn::make('is_active')->label('Bán')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Thêm gói giá'),
                Tables\Actions\Action::make('seedDefaults')->label('Tạo gói giá mẫu')->icon('heroicon-o-sparkles')->color('gray')
                    ->visible(fn () => ! $this->getOwnerRecord()->ratePlans()->exists())
                    ->requiresConfirmation()
                    ->modalDescription('Tạo bộ gói giờ + gói ngày với giá mẫu (số giả) theo loại xe. Bạn chỉnh lại giá sau.')
                    ->action(function () {
                        $n = VehicleRatePlanDefaults::seed($this->getOwnerRecord());
                        Notification::make()->success()->title("Đã tạo {$n} gói giá mẫu")->send();
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
