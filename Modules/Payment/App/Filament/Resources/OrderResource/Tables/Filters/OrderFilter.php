<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Tables\Filters;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Radio;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

class OrderFilter
{
    public static function filter(): array
    {
        return [
            SelectFilter::make('status')
                ->label(__('Trạng thái thanh toán'))
                ->options([
                    'pending'           => __('Đang xử lý'),
                    'paid'              => __('Đã thanh toán'),
                    'deposit'           => __('Đã đặt cọc'),
                    'failed'            => __('Thất bại'),
                    'cancelled_payment' => __('Hủy QR'),
                    'refunded'          => __('Hoàn tiền'),
                ]),

            SelectFilter::make('order_status')
                ->label(__('Nhận/trả phòng'))
                ->options([
                    'pending'     => __('Chờ nhận phòng'),
                    'checked_in'  => __('Đã nhận phòng'),
                    'staying'     => __('Đang ở'),
                    'checked_out' => __('Đã trả phòng'),
                ]),

            SelectFilter::make('category_id')
                ->label(__('Chi nhánh'))
                ->options(function () {
                    $user = auth()->user();
                    $query = Category::query()
                        ->where('category_type', 'product')
                        ->whereNull('parent_id');

                    if (! $user?->isSuperAdmin()) {
                        // Category không có global scope partner_id riêng — mặc định thấy chi
                        // nhánh của đối tác mình; allowedBranchIds() chỉ thu hẹp thêm nếu có.
                        $query->where('partner_id', $user?->partner_id);

                        $allowedIds = $user?->allowedBranchIds() ?? [];
                        if (! empty($allowedIds)) {
                            $query->whereIn('id', $allowedIds);
                        }
                    }

                    return $query->pluck('name', 'id');
                })
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
                ->options(function () {
                    $user = auth()->user();
                    $query = Product::query();

                    if (! $user?->isSuperAdmin()) {
                        // Product đã tự lọc theo partner_id (BelongsToPartner); allowedCategoryIds
                        // chỉ thu hẹp thêm, không dùng để chặn hết khi rỗng.
                        $allowedIds = $user?->allowedCategoryIds() ?? [];
                        if (! empty($allowedIds)) {
                            $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $allowedIds));
                        }
                    }

                    return $query->pluck('name', 'id');
                })
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

            Filter::make('created_at')
                ->form([
                    Grid::make(['default' => 1, 'sm' => 2])
                        ->schema([
                            DateTimePicker::make('created_from')
                                ->label(__('Ngày tạo đơn — Từ ngày'))
                                ->native(false)
                                ->seconds(false)
                                ->timezone('Asia/Ho_Chi_Minh')
                                ->displayFormat('d/m/Y H:i'),
                            DateTimePicker::make('created_to')
                                ->label(__('Ngày tạo đơn — Đến ngày'))
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
                ->columnSpan(['default' => 1, 'sm' => 2, 'lg' => 3]),

            Filter::make('checkin_date_single')
                ->form([
                    DatePicker::make('checkin_day')
                        ->label(__('Nhận phòng — Chọn ngày'))
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
                ->columnSpan(['default' => 1, 'sm' => 1, 'lg' => 1]),

            Filter::make('checkout_date_single')
                ->form([
                    DatePicker::make('checkout_day')
                        ->label(__('Trả phòng — Chọn ngày'))
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
                ->columnSpan(['default' => 1, 'sm' => 1, 'lg' => 1]),

            Filter::make('items_count')
                ->form([
                    Radio::make('items_count_value')
                        ->label('Số khung giờ trong đơn')
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

                    return $query->has('items', '=', (int) $value);
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
                ->columnSpan(['default' => 1, 'sm' => 2, 'lg' => 5]),
        ];
    }
}
