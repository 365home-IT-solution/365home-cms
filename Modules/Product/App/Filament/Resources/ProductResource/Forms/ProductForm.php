<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ProductResource\Forms;

use AmidEsfahani\FilamentTinyEditor\TinyEditor;
use CodeWithDennis\FilamentSelectTree\SelectTree;
use App\Models\Tag;
use App\Services\BranchServiceSync;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Category\Entities\Category;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Form;
use TomatoPHP\FilamentMediaManager\Form\MediaManagerInput;

class ProductForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Grid::make(3)->schema([
                // ── Cột trái (2/3) ──────────────────────────────────────────
                Grid::make(1)->columnSpan(2)->schema([
                    self::categoriesCard(),
                    self::basicInfoCard(),
                    self::priceSourceCard(),
                    self::descriptionsCard(),
                ]),

                // ── Cột phải (1/3) ──────────────────────────────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    self::videoCard(),
                    self::imagesCard(),
                ]),
            ]),
        ]);
    }

    // ── Phân loại: chi nhánh, tiện ích, dịch vụ bổ sung ────────────────────
    private static function categoriesCard(): Section
    {
        return Section::make()->schema([
            SelectTree::make('categories')
                ->label(__('product::product.form.label.categories'))
                ->relationship(
                    relationship: 'categories',
                    titleAttribute: 'name',
                    parentAttribute: 'parent_id',
                    modifyQueryUsing: function ($query) {
                        $query->where('category_type', 'product');

                        $user = auth()->user();
                        if ($user && ! $user->seesAllPartners()) {
                            // Mặc định thấy toàn bộ chi nhánh của đối tác mình (đã lọc theo
                            // partner_id); nếu được gán quyền chi nhánh cụ thể thì thu hẹp thêm.
                            $query->where('partner_id', $user->partner_id);

                            $allowedIds = $user->allowedCategoryIds();
                            if (! empty($allowedIds)) {
                                $query->whereIn('id', $allowedIds);
                            }
                        }

                        return $query;
                    }
                )
                ->required()
                ->placeholder(__('product::product.form.placeholder.categories'))
                ->enableBranchNode()
                ->helperText(__('product::product.form.helper_text.categories'))
                ->inlineLabel(),

            Select::make('room_type_id')
                ->label(__('product::product.form.label.room_type'))
                ->relationship('roomType', 'name')
                ->searchable()
                ->preload()
                ->placeholder(__('product::product.form.placeholder.room_type'))
                ->helperText(__('product::product.form.helper_text.room_type'))
                ->inlineLabel(),

            Select::make('tags')
                ->label(__('product::product.form.label.tags'))
                // Tiện ích mẫu hệ thống + tiện ích riêng của đối tác đang đăng nhập (tags.partner_id).
                ->relationship('tags', 'name', modifyQueryUsing: function ($query) {
                    $user = auth()->user();

                    return $user && ! $user->seesAllPartners()
                        ? $query->usableBy($user->partner_id)
                        : $query;
                })
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->getTranslation('name', app()->getLocale()))
                ->searchable()
                ->preload()
                ->multiple()
                // Thêm mới tiện ích ngay trong form phòng: thành tiện ích RIÊNG của đối tác (super_admin
                // chưa chọn đối tác → tiện ích mẫu hệ thống), trùng tên thì dùng lại tiện ích đã có.
                ->createOptionForm([
                    TextInput::make('name')->label('Tên tiện ích')->required()->maxLength(255),
                    FileUpload::make('image')->label('Ảnh')->image()->disk('public')->directory('Amenity'),
                ])
                ->createOptionUsing(function (array $data) {
                    $user      = auth()->user();
                    $partnerId = $user && ! $user->seesAllPartners() ? $user->partner_id : null;
                    $name      = trim((string) $data['name']);
                    $locale    = app()->getLocale();

                    $existing = Tag::usableBy($partnerId)->get()
                        ->first(fn (Tag $t) => mb_strtolower((string) $t->getTranslation('name', $locale, false)) === mb_strtolower($name));

                    if ($existing) {
                        return $existing->getKey();
                    }

                    $attributes = ['name' => $name, 'type' => 'product', 'partner_id' => $partnerId];
                    if (! empty($data['image'])) {
                        $attributes['image'] = $data['image'];
                    }

                    return Tag::create($attributes)->getKey();
                })
                ->placeholder(__('product::product.form.placeholder.tags'))
                ->helperText(__('product::product.form.helper_text.tags'))
                ->inlineLabel(),

            // Dịch vụ đi kèm gắn theo CHI NHÁNH, không gắn từng phòng: phòng tự nhận dịch vụ của chi
            // nhánh nó thuộc về. Ở đây xem danh sách của chi nhánh và thêm mới nhanh cho chi nhánh đó.
            Placeholder::make('inherited_services')
                ->label('Dịch vụ đi kèm')
                ->content(function (?Product $record, Get $get) {
                    $names = static::branchServicesFor($record, $get('categories'))->pluck('name')->all();

                    return $names ? implode(', ', $names) : 'Chưa có dịch vụ nào cho chi nhánh này.';
                })
                ->helperText('Dịch vụ của chi nhánh phòng đang thuộc về — mọi phòng cùng chi nhánh đều có. Thêm mới bằng nút bên dưới hoặc ở menu Dịch vụ.')
                ->inlineLabel(),

            Actions::make([
                Action::make('add_branch_service')
                    ->label('Thêm dịch vụ cho chi nhánh')
                    ->icon('heroicon-o-plus')
                    ->color('gray')
                    ->modalHeading('Thêm dịch vụ đi kèm cho chi nhánh')
                    ->form([
                        TextInput::make('name')->label('Tên dịch vụ')->required()->maxLength(255),
                        TextInput::make('price')->label('Đơn giá')->numeric()->prefix('₫')->required()->minValue(0),
                        FileUpload::make('image')->label('Ảnh')->image()->disk('public')->directory('addition-services'),
                        Toggle::make('is_active')->label('Kích hoạt')->default(true)->inline(false),
                    ])
                    ->action(function (array $data, ?Product $record, Get $get) {
                        $branchIds = static::branchIdsFor($record, $get('categories'));

                        if (! $branchIds) {
                            Notification::make()->title('Chọn danh mục/chi nhánh cho phòng trước khi thêm dịch vụ.')->warning()->send();

                            return;
                        }

                        $user      = auth()->user();
                        $partnerId = $user && ! $user->seesAllPartners()
                            ? $user->partner_id
                            : Category::withoutGlobalScopes()->whereIn('id', $branchIds)->value('partner_id');

                        $service = AdditionService::create([
                            'partner_id' => $partnerId,
                            'name'       => trim($data['name']),
                            'price'      => (int) $data['price'],
                            'is_active'  => (bool) ($data['is_active'] ?? true),
                            'image'      => $data['image'] ?? null,
                        ]);
                        $service->branches()->sync($branchIds);

                        app(BranchServiceSync::class)->syncBranches($branchIds);

                        Notification::make()->title('Đã thêm dịch vụ cho chi nhánh')->success()->send();
                    }),
            ]),
        ])->columns(1);
    }

    /** Chi nhánh gốc của phòng: theo bản ghi đã lưu, hoặc theo danh mục đang chọn trên form. */
    private static function branchIdsFor(?Product $record, mixed $selectedCategories): array
    {
        $sync = app(BranchServiceSync::class);

        if ($record?->exists) {
            $ids = $sync->rootBranchIdsForRoomId((string) $record->getKey());
            if ($ids) {
                return $ids;
            }
        }

        return $sync->rootBranchIdsForCategoryIds((array) $selectedCategories);
    }

    private static function branchServicesFor(?Product $record, mixed $selectedCategories)
    {
        $branchIds = static::branchIdsFor($record, $selectedCategories);

        if (! $branchIds) {
            return collect();
        }

        return AdditionService::withoutGlobalScopes()
            ->where('is_active', true)
            ->whereHas('branches', fn ($q) => $q->whereIn('categories.id', $branchIds))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    // ── Thông tin cơ bản ────────────────────────────────────────────────────
    private static function basicInfoCard(): Section
    {
        return Section::make()->schema([
            TextInput::make('name')
                ->label(__('product::product.form.label.name'))
                ->helperText(__('product::product.form.helper_text.name'))
                ->required()
                ->live(onBlur: true)
                ->placeholder(__('product::product.form.placeholder.name'))
                ->afterStateUpdated(fn ($state, callable $set) => $set('slug', str()->slug($state)))
                ->inlineLabel(),

            TextInput::make('slug')
                ->label(__('product::product.form.label.slug'))
                ->required()
                ->unique(ignoreRecord: true)
                ->placeholder(__('product::product.form.placeholder.slug'))
                ->inlineLabel(),

            TextInput::make('wifi')
                ->label('Pass Wifi')
                ->maxLength(100)
                ->placeholder('Nhập mật khẩu wifi')
                ->inlineLabel(),

            TextInput::make('home_code')
                ->label('Mã mở khóa')
                ->maxLength(255)
                ->placeholder('Nhập mã mở khóa')
                ->inlineLabel(),

            TextInput::make('address')
                ->label('Địa chỉ')
                ->maxLength(100)
                ->placeholder('Nhập địa chỉ chi nhánh')
                ->inlineLabel(),

            TextInput::make('latitude')
                ->label('Vĩ độ (Latitude)')
                ->numeric()
                ->placeholder('10.7769')
                ->inlineLabel(),

            TextInput::make('longitude')
                ->label('Kinh độ (Longitude)')
                ->numeric()
                ->placeholder('106.7009')
                ->inlineLabel(),

            TextInput::make('map_url')
                ->label('Link Google Maps')
                ->url()
                ->maxLength(500)
                ->placeholder('https://maps.app.goo.gl/...')
                ->helperText('Link Google Maps trực tiếp đến địa chỉ phòng')
                ->inlineLabel(),

            TextInput::make('hotline')
                ->label('Hotline chi nhánh')
                ->maxLength(255)
                ->inlineLabel(),

            ToggleButtons::make('styles')
                ->label('Kiểu đặt phòng')
                ->options([
                    1 => 'Theo khung giờ',
                    2 => 'Theo ngày',
                ])
                ->default(1)
                ->required()
                ->live()
                ->grouped()
                ->colors([1 => 'success', 2 => 'info'])
                ->icons([1 => 'heroicon-o-clock', 2 => 'heroicon-o-calendar-days'])
                ->inlineLabel(),
        ])->columns(1);
    }

    // ── Giá gốc: lấy từ bảng giá / bảng giá mẫu, HIỂN THỊ ngay khung giờ + giá và cho chỉnh riêng cho phòng này ──
    // Các ô chọn không lưu vào bảng products (dehydrated = false); áp ở afterCreate/afterSave (trait AppliesRoomPriceSource).
    private static function priceSourceCard(): Section
    {
        $refresh = function (Get $get, \Filament\Forms\Set $set, $livewire): void {
            $source = (string) ($get('pick_price_source') ?? 'none');
            $preview = app(\App\Services\PriceBoards\RoomPriceBoardPicker::class)->preview(
                $source,
                filled($get('pick_price_board_id')) ? (int) $get('pick_price_board_id') : null,
                filled($get('pick_price_sheet_id')) ? (int) $get('pick_price_sheet_id') : null,
                filled($get('pick_price_template_id')) ? (int) $get('pick_price_template_id') : null,
                (int) ($get('styles') ?: 1),
                self::priceSourcePartnerId($livewire, $get),
            );

            $set('pick_price_rows', $preview['rows']);
            $set('pick_price_title', $preview['title']);
            $set('pick_price_daily_price', $preview['daily']['price'] ?? null);
            $set('pick_price_daily_checkin', $preview['daily']['default_checkin'] ?? null);
            $set('pick_price_daily_checkout', $preview['daily']['default_checkout'] ?? null);
        };

        return Section::make('Giá gốc của phòng')
            ->description('Lấy khung giờ + giá (hoặc giá/đêm) từ một bảng giá hoặc bảng giá mẫu có sẵn, xem ngay bên dưới và chỉnh theo ý cho riêng phòng này. Không bắt buộc.')
            ->schema([
                \Filament\Forms\Components\Radio::make('pick_price_source')
                    ->label('Lấy giá từ')
                    ->options(['none' => 'Không chọn', 'board' => 'Bảng giá', 'template' => 'Bảng giá mẫu'])
                    ->default('none')
                    ->inline()
                    ->inlineLabel(false)
                    ->live()
                    ->dehydrated(false)
                    ->afterStateUpdated(function (Get $get, \Filament\Forms\Set $set, $livewire) use ($refresh) {
                        $set('pick_price_board_id', null);
                        $set('pick_price_sheet_id', null);
                        $set('pick_price_template_id', null);
                        $refresh($get, $set, $livewire);
                    })
                    ->columnSpanFull(),

                Select::make('pick_price_board_id')
                    ->label('Bảng giá')
                    ->searchable()
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(function (Get $get, \Filament\Forms\Set $set, $livewire) use ($refresh) {
                        $set('pick_price_sheet_id', null);
                        $refresh($get, $set, $livewire);
                    })
                    ->options(function ($livewire, Get $get) {
                        $pid = self::priceSourcePartnerId($livewire, $get);

                        if (! $pid) {
                            return [];
                        }

                        return app(\App\Services\PriceBoards\RoomPriceBoardPicker::class)
                            ->options($pid, null, (int) ($get('styles') ?: 1))
                            ->mapWithKeys(fn ($b) => [$b->id => $b->name . ($b->is_active ? '' : ' (đang tắt)')])
                            ->all();
                    })
                    ->visible(fn (Get $get) => $get('pick_price_source') === 'board'),

                Select::make('pick_price_sheet_id')
                    ->label('Bộ giá')
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(fn (Get $get, \Filament\Forms\Set $set, $livewire) => $refresh($get, $set, $livewire))
                    ->placeholder('Bộ mặc định theo loại phòng')
                    ->options(function (Get $get) {
                        $boardId = $get('pick_price_board_id');

                        if (! $boardId) {
                            return [];
                        }

                        $style  = (int) ($get('styles') ?: 1);
                        $board  = \Modules\Product\App\Models\PriceBoard::with('sheets.slots')->find($boardId);
                        $picker = app(\App\Services\PriceBoards\RoomPriceBoardPicker::class);

                        return $board ? $board->sheets
                            ->filter(fn ($s) => $picker->eligible($s, $style))
                            ->mapWithKeys(fn ($s) => [$s->id => $s->name . ($s->is_default ? ' (mặc định)' : '')])
                            ->all() : [];
                    })
                    ->visible(fn (Get $get) => $get('pick_price_source') === 'board' && filled($get('pick_price_board_id'))),

                Select::make('pick_price_template_id')
                    ->label('Bảng giá mẫu')
                    ->searchable()
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(fn (Get $get, \Filament\Forms\Set $set, $livewire) => $refresh($get, $set, $livewire))
                    ->options(function ($livewire, Get $get) {
                        return \Modules\Product\App\Models\PriceTemplate::usableBy(self::priceSourcePartnerId($livewire, $get))
                            ->where('is_active', true)
                            ->orderBy('sort_order')->orderBy('id')
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->visible(fn (Get $get) => $get('pick_price_source') === 'template')
                    ->helperText('Chỉ dùng cho phòng theo khung giờ.'),

                \Filament\Forms\Components\Hidden::make('pick_price_title')->dehydrated(false),

                \Filament\Forms\Components\Placeholder::make('pick_price_title_view')
                    ->hiddenLabel()
                    ->content(fn (Get $get) => new \Illuminate\Support\HtmlString('<span style="font-weight:600">Đang xem: ' . e((string) $get('pick_price_title')) . '</span> — chỉnh giá bên dưới chỉ áp cho phòng này, không đổi bảng giá / mẫu.'))
                    ->visible(fn (Get $get) => filled($get('pick_price_title')))
                    ->columnSpanFull(),

                // Phòng THEO GIỜ: bảng khung giờ + giá, sửa giá từng khung hoặc xoá khung không dùng.
                \Filament\Forms\Components\Repeater::make('pick_price_rows')
                    ->label('Khung giờ + giá của phòng')
                    ->dehydrated(false)
                    ->addActionLabel('Thêm khung giờ')
                    ->reorderable(false)
                    ->deletable()
                    ->defaultItems(0)
                    ->columns(4)
                    ->schema([
                        \Filament\Forms\Components\Hidden::make('timeslot_id'),
                        \Filament\Forms\Components\TimePicker::make('start_time')->label('Từ giờ')->seconds(false)->format('H:i')->required(),
                        \Filament\Forms\Components\TimePicker::make('end_time')->label('Đến giờ')->seconds(false)->format('H:i')->required(),
                        \Filament\Forms\Components\Toggle::make('over_night')->label('Qua đêm')->inline(false),
                        \Filament\Forms\Components\TextInput::make('price')->label('Giá (đ)')->numeric()->minValue(0)->step(1000)->required()->suffix('đ'),
                    ])
                    ->helperText('Nếu phòng thuộc bảng giá đang bật thì giá hiển thị cho khách vẫn theo bảng; giá chỉnh ở đây là giá gốc của phòng khi bảng tắt / hết hạn.')
                    ->visible(fn (Get $get) => ((int) ($get('styles') ?: 1)) === 1 && filled($get('pick_price_rows')))
                    ->columnSpanFull(),

                // Phòng THEO NGÀY: giá/đêm + giờ nhận, trả.
                \Filament\Forms\Components\Grid::make(3)->schema([
                    \Filament\Forms\Components\TextInput::make('pick_price_daily_price')->label('Giá một đêm (đ)')->numeric()->minValue(0)->step(1000)->suffix('đ')->dehydrated(false),
                    \Filament\Forms\Components\TextInput::make('pick_price_daily_checkin')->label('Giờ nhận phòng')->placeholder('14:00')->dehydrated(false),
                    \Filament\Forms\Components\TextInput::make('pick_price_daily_checkout')->label('Giờ trả phòng')->placeholder('12:00')->dehydrated(false),
                ])->visible(fn (Get $get) => ((int) ($get('styles') ?: 1)) === 2 && filled($get('pick_price_title')))->columnSpanFull(),
            ])
            ->columns(2)
            ->collapsible();
    }

    /** Đối tác đang làm việc trên form phòng: tài khoản đối tác / super_admin đã chọn đối tác, hoặc suy từ chi nhánh đã chọn. */
    private static function priceSourcePartnerId($livewire, Get $get): ?string
    {
        $user = auth()->user();

        if ($user && ! $user->seesAllPartners() && $user->partner_id) {
            return (string) $user->partner_id;
        }

        $raw = $get('categories');
        $id  = is_array($raw) ? ($raw[0] ?? null) : $raw;

        if ($id) {
            return Category::withoutGlobalScopes()->whereKey($id)->value('partner_id');
        }

        return isset($livewire->record) ? ($livewire->record->partner_id ?: null) : null;
    }

    // ── Mô tả ───────────────────────────────────────────────────────────────
    private static function descriptionsCard(): Section
    {
        return Section::make()->schema([
            TinyEditor::make('short_description')
                ->label(__('product::product.form.label.short_description'))
                ->id('product_short_description')
                ->profile('custom-full')
                ->inlineLabel(),

            TinyEditor::make('description')
                ->label(__('product::product.form.label.description'))
                ->id('product_description')
                ->nullable()
                ->profile('custom-full')
                ->inlineLabel(),
        ])->columns(1);
    }

    // ── Video ───────────────────────────────────────────────────────────────
    private static function videoCard(): Section
    {
        return Section::make()
            ->schema([
                TextInput::make('setting_video_room.url')
                    ->label('URL Video')
                    ->placeholder('https://www.youtube.com/watch?v=...')
                    ->helperText('Hỗ trợ: YouTube · Shorts · TikTok · Facebook · .mp4')
                    ->maxLength(1000)
                    ->inlineLabel(),

                ToggleButtons::make('setting_video_room.ratio')
                    ->label('Tỉ lệ khung hình')
                    ->options([
                        '16:9' => '16:9 Ngang',
                        '9:16' => '9:16 Dọc',
                        '4:3'  => '4:3 Cổ điển',
                    ])
                    ->default('16:9')
                    ->grouped()
                    ->colors(['16:9' => 'info', '9:16' => 'success', '4:3' => 'warning'])
                    ->inlineLabel(),

                TextInput::make('setting_video_room.title')
                    ->label('Tiêu đề video')
                    ->placeholder('VD: Trải nghiệm phòng Deluxe - 365 Home')
                    ->maxLength(200)
                    ->inlineLabel(),
            ])
            ->columns(1);
    }

    // ── Hình ảnh ────────────────────────────────────────────────────────────
    private static function imagesCard(): Section
    {
        return Section::make()->schema([
            MediaManagerInput::make('Ảnh bìa')
                ->label(__('product::product.form.label.image_main'))
                ->required()
                ->maxSize(3072)
                ->schema([])
                ->defaultItems(1)
                ->minItems(1)
                ->maxItems(1)
                ->columnSpanFull()
                ->inlineLabel(),

            MediaManagerInput::make('Thư viện')
                ->label(__('product::product.form.label.gallery'))
                ->schema([])
                ->defaultItems(0)
                ->minItems(0)
                ->grid(2)
                ->maxItems(18)
                ->columnSpanFull(),
        ])->columns(1);
    }
}
