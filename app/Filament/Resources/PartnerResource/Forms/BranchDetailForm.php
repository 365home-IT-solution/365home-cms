<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerResource\Forms;

use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;

// Form "Chi tiết cơ sở lưu trú" (1 chi nhánh cụ thể) — trang thật (không phải modal), xem
// PartnerResource\Pages\BranchDetail.php. Cố tình KHÔNG đụng vào Modules/Category
// (CategoryResource dùng chung cho mọi vai trò khác) — toàn bộ nằm gọn trong khu vực Đối tác.
class BranchDetailForm
{
    private const LODGING_TYPES = [
        'hotel'     => 'Khách sạn',
        'villa'     => 'Biệt thự nghỉ dưỡng (Villa)',
        'homestay'  => 'Homestay',
        'apartment' => 'Căn hộ dịch vụ',
    ];

    private const BED_TYPES = [
        'single'      => 'Giường đơn',
        'double'      => 'Giường đôi',
        'double_x2'   => '2 Giường đôi',
        'king'        => 'King Size',
    ];

    // $branch được truyền tường minh (đóng gói qua use()) thay vì dựa vào injection tự động
    // ?Category $record của Filament — vì Forms\Components\Actions\Action (action nằm NGAY
    // TRONG 1 form) không có sẵn ->record()/InteractsWithRecord.
    public static function schema(Category $branch): array
    {
        return [
            Forms\Components\Grid::make(4)->schema([
                Forms\Components\Group::make([
                    self::accountStatusSection(),
                    self::operationSection($branch),
                    self::checkinCheckoutSection(),
                    self::roomsSection($branch),
                ])->columnSpan(3),

                Forms\Components\Group::make([
                    self::summarySection($branch),
                    self::supportSection(),
                    self::locationSection($branch),
                ])->columnSpan(1),
            ]),
        ];
    }

    private static function accountStatusSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make()
            ->schema([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\Placeholder::make('status_description')
                        ->label('Trạng thái tài khoản')
                        ->content(fn (Get $get) => $get('status')
                            ? 'Cơ sở của bạn hiện đang hiển thị trên trang tìm kiếm và có thể nhận đặt phòng.'
                            : 'Cơ sở của bạn đang tạm ẩn — không hiển thị trên trang tìm kiếm, không nhận đặt phòng mới.'),

                    Forms\Components\Toggle::make('status')
                        ->label('Đang hoạt động')
                        ->live()
                        ->inline(false),
                ]),
            ]);
    }

    private static function operationSection(Category $branch): Forms\Components\Section
    {
        return Forms\Components\Section::make('Thông số vận hành')
            ->schema([
                Forms\Components\Grid::make(4)->schema([
                    Forms\Components\Select::make('lodging_type')
                        ->label('Loại chỗ nghỉ')
                        ->options(self::LODGING_TYPES),

                    Forms\Components\Placeholder::make('room_count')
                        ->label('Số lượng phòng hiện có')
                        ->content($branch->products()->count()),

                    Forms\Components\Select::make('timezone')
                        ->label('Múi giờ hoạt động')
                        ->options(['Asia/Ho_Chi_Minh' => 'GMT+7 — Việt Nam'])
                        ->default('Asia/Ho_Chi_Minh'),

                    Forms\Components\TextInput::make('operation_manager_name')
                        ->label('Người quản lý vận hành')
                        ->maxLength(255),
                ]),
            ]);
    }

    private static function checkinCheckoutSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Quy định Check-in / Check-out')
            ->schema([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TimePicker::make('checkin_time')
                        ->label('Giờ nhận phòng (Check-in)')
                        ->seconds(false),

                    Forms\Components\TimePicker::make('checkout_time')
                        ->label('Giờ trả phòng (Check-out)')
                        ->seconds(false),
                ]),

                Forms\Components\Textarea::make('default_policy')
                    ->label('Quy định mặc định')
                    ->rows(3)
                    ->placeholder("Quý khách vui lòng xuất trình CCCD hoặc Hộ chiếu khi nhận phòng.\nNhận phòng sớm trước 10:00 phụ thu 50% tiền phòng.\nTrả phòng muộn sau 14:00 phụ thu 30% tiền phòng.")
                    ->columnSpanFull(),
            ]);
    }

    private static function summarySection(Category $branch): Forms\Components\Section
    {
        return Forms\Components\Section::make('Tóm tắt cơ sở')
            ->schema([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('area_sqm')
                        ->label('Diện tích (m²)')
                        ->numeric()
                        ->suffix('m²'),

                    Forms\Components\TextInput::make('established_year')
                        ->label('Năm đi vào hoạt động')
                        ->numeric()
                        ->minValue(1990)
                        ->maxValue((int) date('Y')),
                ]),

                Forms\Components\Placeholder::make('avg_rating')
                    ->label('Đánh giá TB')
                    ->content(function () use ($branch) {
                        $roomIds = $branch->products()->pluck('products.id');
                        $count   = \App\Models\RoomRating::whereIn('room_id', $roomIds)->count();
                        $avg     = \App\Models\RoomRating::whereIn('room_id', $roomIds)->avg('star');

                        return $avg
                            ? '★ ' . number_format((float) $avg, 1) . " ({$count} lượt)"
                            : '— Chưa có đánh giá —';
                    }),
            ]);
    }

    private static function supportSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Hỗ trợ đối tác')
            ->schema([
                Forms\Components\Placeholder::make('support')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<div style="font-size:0.85rem;">'
                        . '<div style="margin-bottom:8px;">📞 Hotline 24/7<br><strong>1900 1234</strong> (Nhánh 2)</div>'
                        . '<div>📄 Tài liệu hướng dẫn<br><a href="#" style="color:#2563eb;">Xem chi tiết tại đây</a></div>'
                        . '</div>'
                    )),
            ]);
    }

    // Category (chi nhánh) không có cột tọa độ riêng — dùng tọa độ phòng đầu tiên có lat/lng
    // (Product đã có sẵn cột latitude/longitude) làm vị trí đại diện cho cả cơ sở.
    private static function locationSection(Category $branch): Forms\Components\Section
    {
        return Forms\Components\Section::make('Vị trí cơ sở')
            ->schema([
                Forms\Components\Placeholder::make('location')
                    ->hiddenLabel()
                    ->content(function () use ($branch) {
                        $roomWithCoords = $branch->products()
                            ->whereNotNull('latitude')
                            ->whereNotNull('longitude')
                            ->first();

                        $address = $roomWithCoords?->address ?? $branch->description ?? '— Chưa có địa chỉ —';

                        if ($roomWithCoords) {
                            $lat = $roomWithCoords->latitude;
                            $lng = $roomWithCoords->longitude;
                            $bbox = ($lng - 0.01) . ',' . ($lat - 0.01) . ',' . ($lng + 0.01) . ',' . ($lat + 0.01);
                            $mapSrc = "https://www.openstreetmap.org/export/embed.html?bbox={$bbox}&marker={$lat},{$lng}";

                            $map = '<iframe src="' . $mapSrc . '" style="width:100%;height:160px;border:0;border-radius:10px;margin-bottom:8px;" loading="lazy"></iframe>';
                        } else {
                            $map = '<div style="padding:24px;background:#f3f4f6;border-radius:10px;text-align:center;color:#9ca3af;margin-bottom:8px;font-size:0.78rem;">Chưa có tọa độ — cập nhật ở phòng bất kỳ trong cơ sở này.</div>';
                        }

                        return new HtmlString(
                            '<div style="font-size:0.85rem;color:#4b5563;">' . $map . e($address) . '</div>'
                        );
                    }),
            ]);
    }

    private static function roomsSection(Category $branch): Forms\Components\Section
    {
        return Forms\Components\Section::make('Danh sách phòng')
            ->headerActions([
                Forms\Components\Actions\Action::make('addRoom')
                    ->label('+ Thêm phòng mới')
                    ->icon('heroicon-o-plus')
                    ->form([
                        Forms\Components\TextInput::make('name')
                            ->label('Tên phòng')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, callable $set) => $set('slug', \Illuminate\Support\Str::slug($state))),
                        Forms\Components\TextInput::make('slug')
                            ->label('Slug')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('bed_type')
                            ->label('Loại giường')
                            ->options(self::BED_TYPES),
                        Forms\Components\TextInput::make('room_area_sqm')
                            ->label('Diện tích phòng')
                            ->numeric()
                            ->suffix('m²'),
                        Forms\Components\TextInput::make('price')
                            ->label('Giá')
                            ->numeric()
                            ->suffix('VNĐ'),
                    ])
                    ->action(function (array $data) use ($branch) {
                        $product = Product::create([
                            ...$data,
                            'partner_id'   => $branch->partner_id,
                            'is_activated' => true,
                            'is_in_stock'  => true,
                        ]);

                        $product->categories()->attach($branch->id);

                        Notification::make()->title('Đã thêm phòng mới')->success()->send();
                    }),
            ])
            ->schema([
                Forms\Components\Placeholder::make('rooms_grid')
                    ->hiddenLabel()
                    ->content(fn () => new HtmlString(self::renderRoomsGrid($branch->products()->get())))
                    ->columnSpanFull(),
            ]);
    }

    // Trạng thái phòng THẬT dựa trên dữ liệu vận hành thực tế — không phải dữ liệu giả:
    // Bảo trì (housekeeping_status=maintenance) > Trống (chưa kích hoạt bán) >
    // Đã đặt (đang có khách lưu trú ngay lúc này) > Hoạt động (sẵn sàng, không ai đang ở).
    private static function roomStatusBadge(Product $room): array
    {
        if ($room->housekeeping_status === 'maintenance') {
            return ['Bảo trì', '#ef4444'];
        }

        if (! $room->is_activated) {
            return ['Trống', '#6b7280'];
        }

        $hasActiveBooking = OrderItem::where('product_id', $room->id)
            ->where('checkin_date', '<=', now())
            ->where('checkout_date', '>=', now())
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['paid', 'deposit', 'shipped']))
            ->exists();

        if ($hasActiveBooking) {
            return ['Đã đặt', '#f59e0b'];
        }

        return ['Hoạt động', '#10b981'];
    }

    private static function renderRoomsGrid($rooms): string
    {
        if ($rooms->isEmpty()) {
            return '<div style="padding:24px;text-align:center;color:#9ca3af;font-size:0.85rem;">Chưa có phòng nào trong cơ sở này.</div>';
        }

        $cards = $rooms->map(function ($room) {
            [$label, $color] = self::roomStatusBadge($room);
            $bedType = self::BED_TYPES[$room->bed_type] ?? null;
            $area    = $room->room_area_sqm ? number_format((float) $room->room_area_sqm, 0) . ' m²' : null;
            $subtitle = collect([$bedType, $area])->filter()->implode(' · ') ?: '—';

            $imageUrl = $room->getFirstMediaUrl('Ảnh bìa');
            $image    = $imageUrl
                ? '<img src="' . e($imageUrl) . '" style="width:100%;height:100px;object-fit:cover;border-radius:8px;margin-bottom:8px;" />'
                : '<div style="width:100%;height:100px;background:#f3f4f6;border-radius:8px;margin-bottom:8px;display:flex;align-items:center;justify-content:center;color:#d1d5db;font-size:0.72rem;">Chưa có ảnh</div>';

            return <<<HTML
                <div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;">
                    {$image}
                    <div style="font-weight:600;font-size:0.9rem;color:#111827;">{$room->name}</div>
                    <div style="font-size:0.78rem;color:#6b7280;margin-top:2px;">{$subtitle}</div>
                    <span style="display:inline-block;margin-top:6px;padding:2px 8px;border-radius:9999px;background:{$color}22;color:{$color};font-size:0.72rem;font-weight:700;">{$label}</span>
                </div>
            HTML;
        })->implode('');

        return <<<HTML
            <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(160px, 1fr));gap:12px;">
                {$cards}
            </div>
        HTML;
    }
}
