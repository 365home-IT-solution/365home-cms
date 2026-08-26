<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationFcmResource\Pages;
use App\Filament\Resources\NotificationFcmResource\RelationManagers;
use App\Models\Customer;
use App\Models\NotificationFcm;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Modules\Payment\Entities\Order;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NotificationFcmResource extends Resource
{
    protected static ?string $model = NotificationFcm::class;

    protected static ?string $navigationIcon   = 'heroicon-o-bell-alert';
    protected static ?string $navigationGroup  = 'Quản lý';
    protected static ?string $navigationLabel  = 'Gửi thông báo đến khách';
    protected static ?string $modelLabel       = 'Thông báo';
    protected static ?string $pluralModelLabel = 'Push Notifications';
    protected static ?int    $navigationSort   = 10;

    // Trước đây hardcode isSuperAdmin() — bỏ qua NotificationFcmPolicy (đã đúng, kiểm tra
    // view_any_notification::fcm), khiến tick/bỏ tick quyền này ở Roles & Permissions vô tác dụng.
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_notification::fcm') ?? false;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // FORM (Create)
    // ──────────────────────────────────────────────────────────────────────────

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Nội dung thông báo')->schema([
                TextInput::make('title')
                    ->label('Tiêu đề')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Ví dụ: Khuyến mãi đặc biệt tháng 6'),

                Textarea::make('body')
                    ->label('Nội dung')
                    ->required()
                    ->rows(4)
                    ->maxLength(1000)
                    ->placeholder('Nhập nội dung thông báo gửi đến khách hàng...'),

                TextInput::make('url')
                    ->label('URL điều hướng')
                    ->helperText('Đường dẫn app/website sẽ mở khi khách bấm vào thông báo (không bắt buộc).')
                    ->placeholder('VD: /orders/123 hoặc myapp://order/123')
                    ->maxLength(500),
            ]),

            Section::make('Người nhận')->schema([
                Radio::make('sent_for')
                    ->label('Gửi đến')
                    ->options([
                        'all'   => 'Tất cả khách hàng (có token)',
                        'users' => 'Chọn từng người',
                        'order' => 'Theo đơn hàng',
                    ])
                    ->default('users')
                    ->inline()
                    ->live()
                    ->required(),

                Placeholder::make('all_count_hint')
                    ->label('')
                    ->content(function (): string {
                        $count = Customer::whereNotNull('token_device')
                            ->where('status', Customer::STATUS_ACTIVE)
                            ->count();

                        return "Sẽ gửi đến {$count} khách hàng đang có token thiết bị.";
                    })
                    ->visible(fn (Get $get): bool => $get('sent_for') === 'all'),

                Select::make('customer_ids')
                    ->label('Chọn khách hàng')
                    ->helperText('Chỉ hiển thị khách hàng đã đăng nhập trên thiết bị di động (có token).')
                    ->multiple()
                    ->searchable()
                    ->required()
                    ->visible(fn (Get $get): bool => $get('sent_for') === 'users')
                    ->getSearchResultsUsing(function (string $search): array {
                        return Customer::whereNotNull('token_device')
                            ->where('status', Customer::STATUS_ACTIVE)
                            ->where(function ($q) use ($search) {
                                $q->where('fullname', 'like', "%{$search}%")
                                  ->orWhere('phone', 'like', "%{$search}%");
                            })
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (Customer $c) => [
                                $c->id => "{$c->fullname} — {$c->phone}",
                            ])
                            ->toArray();
                    })
                    ->getOptionLabelsUsing(function (array $values): array {
                        return Customer::whereIn('id', $values)
                            ->get()
                            ->mapWithKeys(fn (Customer $c) => [
                                $c->id => "{$c->fullname} — {$c->phone}",
                            ])
                            ->toArray();
                    })
                    ->noSearchResultsMessage('Không tìm thấy khách hàng có token thiết bị.')
                    ->loadingMessage('Đang tìm kiếm...')
                    ->placeholder('Tìm theo tên hoặc số điện thoại...'),

                Select::make('order_id')
                    ->label('Tìm đơn hàng')
                    ->helperText('Nhập mã đơn, tên hoặc số điện thoại người mua để tìm kiếm.')
                    ->searchable()
                    ->live()
                    ->required()
                    ->visible(fn (Get $get): bool => $get('sent_for') === 'order')
                    ->getSearchResultsUsing(function (string $search): array {
                        return Order::where(function ($q) use ($search) {
                            $q->where('order_code', 'like', "%{$search}%")
                              ->orWhere('buyer_name', 'like', "%{$search}%")
                              ->orWhere('buyer_phone', 'like', "%{$search}%");
                        })
                        ->orderByDesc('created_at')
                        ->limit(30)
                        ->get()
                        ->mapWithKeys(function (Order $o): array {
                            $tokenLabel = $o->device_token
                                ? '[guest token]'
                                : ($o->customer_id ? '[customer]' : '[không có token]');

                            return [
                                $o->id => "#{$o->order_code} — {$o->buyer_name} ({$o->buyer_phone}) {$tokenLabel}",
                            ];
                        })
                        ->toArray();
                    })
                    ->getOptionLabelUsing(function (int $value): string {
                        $o = Order::find($value);
                        if (! $o) {
                            return "Đơn #{$value}";
                        }

                        $tokenLabel = $o->device_token
                            ? '[guest token]'
                            : ($o->customer_id ? '[customer]' : '[không có token]');

                        return "#{$o->order_code} — {$o->buyer_name} ({$o->buyer_phone}) {$tokenLabel}";
                    })
                    ->noSearchResultsMessage('Không tìm thấy đơn hàng.')
                    ->loadingMessage('Đang tìm kiếm...')
                    ->placeholder('Nhập mã đơn, tên hoặc SĐT...'),

                Placeholder::make('order_recipient_hint')
                    ->label('Người nhận')
                    ->content(function (Get $get): string {
                        $orderId = $get('order_id');
                        if (! $orderId) {
                            return 'Chọn đơn hàng để xem thông tin người nhận.';
                        }

                        $order = Order::with('customer')->find($orderId);
                        if (! $order) {
                            return 'Không tìm thấy đơn hàng.';
                        }

                        if ($order->customer_id && $order->customer?->token_device) {
                            return "✓ Gửi đến khách hàng: {$order->customer->fullname} ({$order->buyer_phone}) — thiết bị đã đăng nhập.";
                        }

                        if ($order->device_token) {
                            return "✓ Gửi đến guest: {$order->buyer_name} ({$order->buyer_phone}) — token từ đơn hàng.";
                        }

                        if ($order->customer_id && ! $order->customer?->token_device) {
                            return "⚠ Khách hàng {$order->buyer_name} chưa đăng nhập trên thiết bị di động (không có token).";
                        }

                        return "⚠ Đơn hàng này không có token thiết bị nào để gửi thông báo.";
                    })
                    ->visible(fn (Get $get): bool => $get('sent_for') === 'order'),
            ]),

            Section::make('Lịch gửi')->schema([
                DateTimePicker::make('scheduled_at')
                    ->label('Gửi vào lúc')
                    ->helperText('Để trống nếu muốn gửi ngay. Chọn thời điểm trong tương lai để lên lịch.')
                    ->nullable()
                    ->minDate(now())
                    ->seconds(false)
                    ->native(false)
                    ->displayFormat('d/m/Y H:i')
                    ->timezone(config('app.timezone', 'Asia/Ho_Chi_Minh')),
            ]),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // TABLE (List)
    // ──────────────────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Tiêu đề')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                TextColumn::make('body')
                    ->label('Nội dung')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->body)
                    ->color('gray'),

                TextColumn::make('url')
                    ->label('URL điều hướng')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('delivery_status')
                    ->label('Trạng thái')
                    ->badge()
                    ->getStateUsing(function (NotificationFcm $record): string {
                        if ($record->isPending()) {
                            return 'scheduled';
                        }

                        return $record->fail_count > 0 && $record->sent_count === 0
                            ? 'failed'
                            : 'sent';
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'scheduled' => 'Đã lên lịch',
                        'failed'    => 'Thất bại',
                        default     => 'Đã gửi',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'scheduled' => 'warning',
                        'failed'    => 'danger',
                        default     => 'success',
                    }),

                TextColumn::make('total_recipients')
                    ->label('Người nhận')
                    ->getStateUsing(fn (NotificationFcm $record) => $record->sent_count + $record->fail_count)
                    ->suffix(' thiết bị')
                    ->alignCenter(),

                TextColumn::make('sent_count')
                    ->label('Đã gửi')
                    ->badge()
                    ->color('success')
                    ->alignCenter(),

                TextColumn::make('fail_count')
                    ->label('Thất bại')
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray')
                    ->alignCenter(),

                TextColumn::make('scheduled_at')
                    ->label('Lịch gửi')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Gửi ngay')
                    ->color('warning')
                    ->sortable(),

                TextColumn::make('creator.name')
                    ->label('Người gửi')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Thời gian tạo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                ViewAction::make()->label('Chi tiết'),
            ])
            ->paginated([10, 25, 50]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // INFOLIST (View)
    // ──────────────────────────────────────────────────────────────────────────

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfoSection::make('Nội dung thông báo')->schema([
                TextEntry::make('title')->label('Tiêu đề')->weight('bold'),
                TextEntry::make('body')->label('Nội dung'),
                TextEntry::make('url')->label('URL điều hướng')->placeholder('—'),
            ]),

            InfoSection::make('Kết quả gửi')->schema([
                Grid::make(3)->schema([
                    TextEntry::make('total')
                        ->label('Tổng thiết bị')
                        ->getStateUsing(fn (NotificationFcm $r) => ($r->sent_count + $r->fail_count) . ' thiết bị'),

                    TextEntry::make('sent_count')
                        ->label('Gửi thành công')
                        ->badge()
                        ->color('success')
                        ->formatStateUsing(fn (int $state) => "{$state} thiết bị"),

                    TextEntry::make('fail_count')
                        ->label('Thất bại')
                        ->badge()
                        ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray')
                        ->formatStateUsing(fn (int $state) => "{$state} thiết bị"),
                ]),

                Grid::make(3)->schema([
                    TextEntry::make('creator.name')->label('Người gửi'),
                    TextEntry::make('scheduled_at')
                        ->label('Lịch gửi')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('Gửi ngay'),
                    TextEntry::make('created_at')->label('Thời gian tạo')->dateTime('d/m/Y H:i'),
                ]),
            ]),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PAGES & RELATION MANAGERS
    // ──────────────────────────────────────────────────────────────────────────

    public static function getRelationManagers(): array
    {
        return [
            RelationManagers\RecipientsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListNotificationFcm::route('/'),
            'create' => Pages\CreateNotificationFcm::route('/create'),
            'view'   => Pages\ViewNotificationFcm::route('/{record}'),
        ];
    }
}
