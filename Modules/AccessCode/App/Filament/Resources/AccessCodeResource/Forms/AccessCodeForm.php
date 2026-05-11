<?php

declare(strict_types=1);

namespace Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Forms;

use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Set;
use Illuminate\Database\Eloquent\Model;

class AccessCodeForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Thông tin mã truy cập')
                    ->schema([
                        Grid::make()
                            ->schema([
                                TextInput::make('code')
                                    ->label('Mã vào cổng')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->placeholder('012345')
                                    ->hintIcon('heroicon-m-key')
                                    ->hintColor('primary')
                                    ->hintIconTooltip('Mã này sẽ được sử dụng để vào cổng tối đa 10 ký tự.')
                                    ->maxLength(10)
                                    ->suffixAction(
                                        Action::make('generateCode')
                                            ->label('Tự sinh mã')
                                            ->icon('heroicon-m-arrow-path')
                                            ->tooltip('Tự động sinh mã ngẫu nhiên 6 chữ số')
                                            ->action(function (Set $set) {
                                                $set('code', (string) random_int(100000, 999999));
                                            })
                                    ),

                                Select::make('category_id')
                                    ->label('Chi nhánh')
                                    ->relationship('category', 'name', function ($query) {
                                        $query->where('category_type', 'product');
                                    })
                                    ->searchable()
                                    ->hintIcon('heroicon-m-map-pin')
                                    ->hintColor('primary')
                                    ->hintIconTooltip('Chi nhánh nơi mã này có hiệu lực.')
                                    ->preload()
                                    ->required(),

                                Select::make('status')
                                    ->label('Trạng thái')
                                    ->options([
                                        'active' => 'Hoạt động',
                                        'unactive' => 'Đã dừng',
                                        'expired' => 'Hết hạn',
                                    ])
                                    ->default('active')
                                    ->required(),
                            ])
                            ->columns(3),

                        Grid::make()
                            ->schema([
                                DateTimePicker::make('valid_from')
                                    ->label('Hiệu lực từ')
                                    ->nullable()
                                    ->default(fn() => now()),

                                DateTimePicker::make('valid_until')
                                    ->label('Hết hạn')
                                    ->nullable()
                                    ->default(fn() => now()->addDays(2)),

                                DateTimePicker::make('used_at')
                                    ->label('Thời gian dùng')
                                    ->nullable()
                                    ->visible(fn($get) => $get('status') === 'disabled'),

                                TextInput::make('max_uses')
                                    ->label('Số lần sử dụng tối đa')
                                    ->numeric()
                                    ->minValue(1)
                                    ->nullable()
                                    ->default(null)
                                    ->hintIcon('heroicon-m-numbered-list')
                                    ->hintColor('primary')
                                    ->hintIconTooltip('Để trống nếu muốn mã sử dụng được nhiều đơn.'),

                            ])->columns(3),
                        TextInput::make('gate_location')
                            ->label('Cổng vào (nếu có)')
                            ->placeholder('Cổng A, Cổng B, Cổng C, ...')
                            ->helperText('Không bắt buộc.')
                            ->nullable(),

                        Textarea::make('notes')
                            ->label('Ghi chú')
                            ->rows(3)
                            ->helperText('Không bắt buộc.')
                            ->nullable(),
                    ])
                    ->columns(1),
            ]);
    }
}
