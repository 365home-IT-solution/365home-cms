<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Tables\Filters;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

class OrderFilter
{
    public static function filter(): array
    {
        return [
            // HÀNG 1: Các bộ lọc chọn nhanh
            SelectFilter::make('status')
                ->label(__('Trạng thái'))
                ->options([
                    'pending'           => __('Đang xử lý'),
                    'paid'              => __('Đã thanh toán'),
                    'deposit'           => __('Đã đặt cọc'),
                    'failed'            => __('Thất bại'),
                    'cancelled_payment' => __('Hủy QR'),
                ]),

            SelectFilter::make('category_id')
                ->label(__('Chi nhánh'))
                ->options(
                    Category::query()
                        ->where('category_type', 'product')
                        ->whereNull('parent_id')
                        ->pluck('name', 'id')
                )
                ->query(function (Builder $query, array $data): Builder {
                    $value = $data['value'] ?? null;

                    if (blank($value)) {
                        return $query;
                    }

                    $allCategoryIds = Category::query()
                        ->where('id', $value)
                        ->orWhere('parent_id', $value)
                        ->pluck('id')
                        ->toArray();

                    return $query->whereIn('category_id', $allCategoryIds);
                }),

            SelectFilter::make('product_id')
                ->label(__('Phòng'))
                ->options(Product::query()->pluck('name', 'id'))
                ->query(function (Builder $query, array $data): Builder {
                    $value = $data['value'] ?? null;

                    if (blank($value)) {
                        return $query;
                    }

                    return $query->whereHas('items', fn ($q) => $q->where('product_id', $value));
                }),

            SelectFilter::make('payment_method')
                ->label(__('Phương thức thanh toán'))
                ->options([
                    'PayOS' => __('PayOS'),
                    'cod'   => __('Tiền mặt'),
                ]),

            // HÀNG 2: Lọc theo số khung giờ trong đơn
            Filter::make('items_count')
                ->form([
                    Placeholder::make('items_count_label')
                        ->label('')
                        ->content(new HtmlString('<span class="text-sm font-medium text-purple-600 uppercase tracking-wider">Số khung giờ trong đơn</span>')),
                    Radio::make('items_count_value')
                        ->label('')
                        ->options([
                            '1'   => '1 khung giờ',
                            '2'   => '2 khung giờ',
                            '3'   => '3 khung giờ',
                            '4'   => '4 khung giờ',
                            'gt4' => 'Trên 4 khung giờ',
                        ])
                        ->inline()
                        ->inlineLabel(false),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    $value = $data['items_count_value'] ?? null;

                    if (blank($value)) {
                        return $query;
                    }

                    if ($value === 'gt4') {
                        return $query->has('items', '>', 4);
                    }

                    $count = (int) $value;

                    return $query->has('items', '=', $count);
                })
                ->indicateUsing(function (array $data): array {
                    $value = $data['items_count_value'] ?? null;

                    if (blank($value)) {
                        return [];
                    }

                    $label = match ($value) {
                        '1'   => '1 khung giờ',
                        '2'   => '2 khung giờ',
                        '3'   => '3 khung giờ',
                        '4'   => '4 khung giờ',
                        'gt4' => 'Trên 4 khung giờ',
                        default => $value,
                    };

                    return ['Số khung giờ: ' . $label];
                })
                ->columnSpan(4),

            // HÀNG 3: Ngày tạo đơn
            Filter::make('created_at')
                ->form([
                    Placeholder::make('created_label')
                        ->label('')
                        ->content(new HtmlString('<span class="text-sm font-medium text-gray-500 uppercase tracking-wider">Ngày tạo đơn</span>')),
                    Grid::make(2)->schema([
                        DateTimePicker::make('created_from')
                            ->label(__('Từ ngày'))
                            ->native(false)
                            ->seconds(false)
                            ->timezone('Asia/Ho_Chi_Minh')
                            ->displayFormat('d/m/Y H:i'),
                        DateTimePicker::make('created_to')
                            ->label(__('Đến ngày'))
                            ->native(false)
                            ->seconds(false)
                            ->timezone('Asia/Ho_Chi_Minh')
                            ->displayFormat('d/m/Y H:i'),
                    ]),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    return $query
                        ->when(
                            $data['created_from'] ?? null,
                            fn ($q, $value) => $q->where('created_at', '>=', $value)
                        )
                        ->when(
                            $data['created_to'] ?? null,
                            fn ($q, $value) => $q->where('created_at', '<=', $value)
                        );
                })
                ->indicateUsing(function (array $data): array {
                    $indicators = [];
                    if ($data['created_from'] ?? null) {
                        $indicators[] = 'Tạo từ: ' . $data['created_from'];
                    }
                    if ($data['created_to'] ?? null) {
                        $indicators[] = 'Tạo đến: ' . $data['created_to'];
                    }
                    return $indicators;
                })
                ->columnSpan(2),

            // HÀNG 4: Lọc nhận phòng & trả phòng theo 1 ngày cụ thể
            Filter::make('checkin_date_single')
                ->form([
                    Placeholder::make('checkin_single_label')
                        ->label('')
                        ->content(new HtmlString('<span class="text-sm font-medium text-blue-600 uppercase tracking-wider">Nhận phòng — Lọc theo ngày</span>')),
                    DatePicker::make('checkin_day')
                        ->label(__('Chọn ngày nhận phòng'))
                        ->native(false)
                        ->timezone('Asia/Ho_Chi_Minh')
                        ->displayFormat('d/m/Y')
                        ->placeholder('Chọn 1 ngày để xem tất cả khung giờ'),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    $day = $data['checkin_day'] ?? null;

                    if (blank($day)) {
                        return $query;
                    }

                    return $query->whereHas('items', function ($q) use ($day) {
                        $q->whereDate('checkin_date', $day);
                    });
                })
                ->indicateUsing(function (array $data): array {
                    if ($data['checkin_day'] ?? null) {
                        return ['Nhận phòng ngày: ' . \Carbon\Carbon::parse($data['checkin_day'])->format('d/m/Y')];
                    }
                    return [];
                })
                ->columnSpan(1),

            Filter::make('checkout_date_single')
                ->form([
                    Placeholder::make('checkout_single_label')
                        ->label('')
                        ->content(new HtmlString('<span class="text-sm font-medium text-red-600 uppercase tracking-wider">Trả phòng — Lọc theo ngày</span>')),
                    DatePicker::make('checkout_day')
                        ->label(__('Chọn ngày trả phòng'))
                        ->native(false)
                        ->timezone('Asia/Ho_Chi_Minh')
                        ->displayFormat('d/m/Y')
                        ->placeholder('Chọn 1 ngày để xem tất cả khung giờ'),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    $day = $data['checkout_day'] ?? null;

                    if (blank($day)) {
                        return $query;
                    }

                    return $query->whereHas('items', function ($q) use ($day) {
                        $q->whereDate('checkout_date', $day);
                    });
                })
                ->indicateUsing(function (array $data): array {
                    if ($data['checkout_day'] ?? null) {
                        return ['Trả phòng ngày: ' . \Carbon\Carbon::parse($data['checkout_day'])->format('d/m/Y')];
                    }
                    return [];
                })
                ->columnSpan(1),

                // Lọc đơn có dịch vụ thêm
SelectFilter::make('has_services')
    ->label('Dịch vụ thêm')
    ->options([
        'yes' => 'Có dịch vụ',
        'no'  => 'Không có dịch vụ',
    ])
    ->query(function (Builder $query, array $data): Builder {
        $value = $data['value'] ?? null;

        if (blank($value)) {
            return $query;
        }

        return $value === 'yes'
            ? $query->has('services')
            : $query->doesntHave('services');
    }),
        ];
    }
}