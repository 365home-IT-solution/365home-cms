<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationFcmResource\Pages;
use App\Filament\Resources\NotificationFcmResource\RelationManagers;
use App\Models\Customer;
use App\Models\NotificationFcm;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NotificationFcmResource extends Resource
{
    protected static ?string $model = NotificationFcm::class;

    protected static ?string $navigationIcon   = 'heroicon-o-bell-alert';
    protected static ?string $navigationGroup  = 'Thông báo';
    protected static ?string $navigationLabel  = 'Push Notification';
    protected static ?string $modelLabel       = 'Thông báo';
    protected static ?string $pluralModelLabel = 'Push Notifications';
    protected static ?int    $navigationSort   = 10;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
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
            ]),

            Section::make('Người nhận')->schema([
                Select::make('customer_ids')
                    ->label('Chọn khách hàng')
                    ->helperText('Chỉ hiển thị khách hàng đã đăng nhập trên thiết bị di động (có token).')
                    ->multiple()
                    ->searchable()
                    ->required()
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

                // Nút "Chọn tất cả" — helper text giải thích
                \Filament\Forms\Components\Placeholder::make('all_hint')
                    ->label('')
                    ->content(function (): string {
                        $count = Customer::whereNotNull('token_device')
                            ->where('status', Customer::STATUS_ACTIVE)
                            ->count();

                        return "Hiện có {$count} khách hàng đang có token thiết bị.";
                    }),
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

                TextColumn::make('total_recipients')
                    ->label('Người nhận')
                    ->getStateUsing(fn (NotificationFcm $record) => $record->sent_count + $record->fail_count)
                    ->suffix(' thiết bị')
                    ->alignCenter(),

                BadgeColumn::make('sent_count')
                    ->label('Đã gửi')
                    ->color('success')
                    ->suffix(' ✓')
                    ->alignCenter(),

                BadgeColumn::make('fail_count')
                    ->label('Thất bại')
                    ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray')
                    ->alignCenter(),

                TextColumn::make('creator.name')
                    ->label('Người gửi')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Thời gian gửi')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
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

                Grid::make(2)->schema([
                    TextEntry::make('creator.name')->label('Người gửi'),
                    TextEntry::make('created_at')->label('Thời gian gửi')->dateTime('d/m/Y H:i'),
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
