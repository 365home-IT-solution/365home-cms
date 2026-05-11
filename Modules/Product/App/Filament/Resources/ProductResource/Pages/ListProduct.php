<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ProductResource\Pages;

use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Modules\Product\App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Carbon;
use Modules\Product\App\Models\GasPrice;

class ListProduct extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Sử dụng Action::make() để thêm bản ghi mới
//            Actions\Action::make('create_gas_prices')
//                ->label('Thêm bảng giá gas')
//                ->color('primary')
//                ->icon('heroicon-o-plus-circle')
//                ->modalHeading('Thêm bảng giá gas')
//                ->modalSubmitActionLabel('Lưu')
//                ->modalCancelActionLabel('Hủy')
//                ->modalWidth(MaxWidth::FiveExtraLarge)
//                ->slideOver()
//                ->form([
//                    // Chọn ngày
//                    DatePicker::make('date')
//                        ->label('Chọn ngày')
//                        ->default(Carbon::today())
//                        ->required(),
//
//                    // Repeater để nhập danh sách giá
//                    Section::make('Danh sách giá gas')
//                        ->schema([
//                            Repeater::make('meta')
//                                ->label('Giá gas theo khu vực')
//                                ->schema([
//                                    TextInput::make('region')
//                                        ->label('Khu vực')
//                                        ->required(),
//
//                                    Repeater::make('gas_prices')
//                                        ->label('Các loại bình gas')
//                                        ->schema([
//                                            TextInput::make('gas_type')
//                                                ->label('Loại bình (VD: Bình 12KG)')
//                                                ->required(),
//
//                                            TextInput::make('price')
//                                                ->label('Giá (VNĐ)')
//                                                ->numeric()
//                                                ->required(),
//                                        ])
//                                        ->defaultItems(2)
//                                        ->addActionLabel('Thêm bình gas')
//                                        ->columns(2),
//                                ])
//                                ->columns(1)
//                                ->defaultItems(1)
//                                ->addActionLabel('Thêm khu vực')
//                                ->itemLabel(fn (array $state): ?string => $state['region'] ?? null)
//                                ->collapsible(),
//                        ])
//                ])
//                ->action(function (array $data) {
//                    $meta = array_map(function ($entry) {
//                        return [
//                            'region' => $entry['region'],
//                            'gas_prices' => array_map(function ($gas) {
//                                return [
//                                    'gas_type' => $gas['gas_type'],
//                                    'price' => (float) $gas['price'],
//                                ];
//                            }, $entry['gas_prices'] ?? []),
//                        ];
//                    }, $data['meta']);
//
//                    GasPrice::create([
//                        'date' => $data['date'],
//                        'meta' => $meta,
//                    ]);
//                    Notification::make()
//                        ->title("Đã thêm bảng giá gas thành công!")
//                        ->success()
//                        ->send();
//                }),
//
//            Actions\Action::make('Danh sách bảng giá gas')
////                ->label('')
////                ->icon('heroicon-o-wallet')
//                ->label('Danh sách bảng giá theo ngày')
////                ->extraAttributes(['style' =>  'display: inline-block;'])
//                ->url(ListGasPrice::getUrl()),

            Actions\CreateAction::make(),
        ];
    }

}