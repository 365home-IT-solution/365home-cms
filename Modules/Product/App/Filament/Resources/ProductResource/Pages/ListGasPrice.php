<?php

namespace Modules\Product\App\Filament\Resources\ProductResource\Pages;

use Filament\Actions\Action as Action1;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\IconSize;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Product\App\Filament\Resources\ProductResource;
use Filament\Resources\Pages\Page;
use Modules\Product\App\Models\GasPrice;

class ListGasPrice extends Page implements HasForms, HasTable, HasActions
{
    use InteractsWithActions, InteractsWithTable, InteractsWithForms;

    protected static string $resource = ProductResource::class;

    protected static ?string $title = 'Danh sách bảng giá Gas';

    protected static string $view = 'product::filament.resources.product-resource.pages.list-gas-price';

    public function table(Table $table): Table
    {
        return $table
            ->query(GasPrice::query())
            ->columns([
                TextColumn::make('date')
                    ->label('Thời gian')
                    ->searchable()
                    ->alignCenter()
                    ->weight(FontWeight::Bold)
                    ->sortable(),

//                TextColumn::make('meta')
//                    ->label('Dữ liệu')
//                    ->badge()
//                    ->alignCenter()
//                    ->sortable(),
//
            ])
            ->emptyStateHeading('Không có giá trị')
            ->emptyStateIcon('heroicon-o-strikethrough')
            ->bulkActions(self::bulkActions())
            ->actions([
                ActionGroup::make([
                    ViewAction::make('Xem chi tiết')
                        ->label('Xem chi tiết')
                        ->icon('heroicon-o-eye')
                        ->infolist([
                            Grid::make(1)
                                ->schema([
                                    TextEntry::make('date')
                                        ->label('Thời gian')
                                        ->weight(FontWeight::Bold)
                                        ->columnSpan(3),

                                    TextEntry::make('meta')
                                        ->label('Giá trị')
                                        ->columnSpan(3),
                                ])->columns(12)
                        ])
                        ->iconSize(IconSize::Large)
                        ->color(Color::Blue)
                        ->modalHeading('Chi tiết giá trị'),

                    EditAction::make('Cập nhật')
                        ->label('Cập nhật')
                        ->icon('heroicon-o-pencil-square')
                        ->color(Color::Amber)
                        ->iconSize(IconSize::Large)
                        ->modalHeading('Cập nhật giá trị')
                        ->form([
                            // Chọn ngày
                            DatePicker::make('date')
                                ->label('Chọn ngày')
                                ->default(Carbon::today())
                                ->required(),

                            // Repeater để nhập danh sách giá
                            Section::make('Danh sách giá gas')
                                ->schema([
                                    Repeater::make('meta')
                                        ->label('Giá gas theo khu vực')
                                        ->schema([
                                            TextInput::make('region')
                                                ->label('Khu vực')
                                                ->required(),

                                            Repeater::make('gas_prices')
                                                ->label('Các loại bình gas')
                                                ->schema([
                                                    TextInput::make('gas_type')
                                                        ->label('Loại bình (VD: Bình 12KG)')
                                                        ->required(),

                                                    TextInput::make('price')
                                                        ->label('Giá (VNĐ)')
                                                        ->numeric()
                                                        ->required(),
                                                ])
                                                ->defaultItems(2)
                                                ->addActionLabel('Thêm bình gas')
                                                ->columns(2),
                                        ])
                                        ->columns(1)
                                        ->defaultItems(1)
                                        ->addActionLabel('Thêm khu vực')
                                        ->itemLabel(fn (array $state): ?string => $state['region'] ?? null)
                                        ->collapsible(),
                                ])
                        ])
                        ->action(function (array $data) {
                            $meta = array_map(function ($entry) {
                                return [
                                    'region' => $entry['region'],
                                    'gas_prices' => array_map(function ($gas) {
                                        return [
                                            'gas_type' => $gas['gas_type'],
                                            'price' => (float) $gas['price'],
                                        ];
                                    }, $entry['gas_prices'] ?? []),
                                ];
                            }, $data['meta']);

                            GasPrice::create([
                                'date' => $data['date'],
                                'meta' => $meta,
                            ]);
                            Notification::make()
                                ->title("Đã thêm bảng giá gas thành công!")
                                ->success()
                                ->send();
                        }),

                    DeleteAction::make('Xóa')
                        ->label('Xóa')
                        ->icon('heroicon-o-trash')
                        ->iconSize(IconSize::Large)
                        ->modalHeading('Xóa'),
                ])

            ]);
    }

    public function getHeaderActions(): array
    {
        return [
            Action1::make('create_gas_prices')
                ->label('Thêm bảng giá gas')
                ->color('primary')
                ->icon('heroicon-o-plus-circle')
                ->modalHeading('Thêm bảng giá gas')
                ->modalSubmitActionLabel('Lưu')
                ->modalCancelActionLabel('Hủy')
                ->modalWidth(MaxWidth::FiveExtraLarge)
                ->slideOver()
                ->form([
                    // Chọn ngày
                    DatePicker::make('date')
                        ->label('Chọn ngày')
                        ->default(Carbon::today())
                        ->required(),

                    // Repeater để nhập danh sách giá
                    Section::make('Danh sách giá gas')
                        ->schema([
                            Repeater::make('meta')
                                ->label('Giá gas theo khu vực')
                                ->schema([
                                    TextInput::make('region')
                                        ->label('Khu vực')
                                        ->required(),

                                    Repeater::make('gas_prices')
                                        ->label('Các loại bình gas')
                                        ->schema([
                                            TextInput::make('gas_type')
                                                ->label('Loại bình (VD: Bình 12KG)')
                                                ->required(),

                                            TextInput::make('price')
                                                ->label('Giá (VNĐ)')
                                                ->numeric()
                                                ->required(),
                                        ])
                                        ->defaultItems(2)
                                        ->addActionLabel('Thêm bình gas')
                                        ->columns(2),
                                ])
                                ->columns(1)
                                ->defaultItems(1)
                                ->addActionLabel('Thêm khu vực')
                                ->itemLabel(fn (array $state): ?string => $state['region'] ?? null)
                                ->collapsible(),
                        ])
                ])
                ->action(function (array $data) {
                    $meta = array_map(function ($entry) {
                        return [
                            'region' => $entry['region'],
                            'gas_prices' => array_map(function ($gas) {
                                return [
                                    'gas_type' => $gas['gas_type'],
                                    'price' => (float) $gas['price'],
                                ];
                            }, $entry['gas_prices'] ?? []),
                        ];
                    }, $data['meta']);

                    GasPrice::create([
                        'date' => $data['date'],
                        'meta' => $meta,
                    ]);
                    Notification::make()
                        ->title("Đã thêm bảng giá gas thành công!")
                        ->success()
                        ->send();
                }),

            Action1::make('Danh sách bảng giá')
                ->label('Quay lại Danh sách')
                ->icon('heroicon-o-arrow-left-end-on-rectangle')
                ->url(ProductResource::getUrl('index')),

        ];
    }

    public static function bulkActions(): array
    {
        return [
            BulkActionGroup::make([
                DeleteBulkAction::make()->modalHeading('Xóa tất cả'),
            ]),
        ];
    }
}
