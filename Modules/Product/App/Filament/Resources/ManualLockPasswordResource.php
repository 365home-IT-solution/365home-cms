<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources;

use Carbon\Carbon;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use App\Filament\Support\PartnerTableHelpers;
use Illuminate\Database\Eloquent\Builder;
use Modules\Category\Entities\Category;
use Modules\Category\Entities\Categorizable;
use Modules\Product\App\Filament\Resources\ManualLockPasswordResource\Pages;
use Modules\Product\App\Models\ManualLockPassword;
use Modules\Product\App\Models\Product;

class ManualLockPasswordResource extends Resource
{
    protected static ?string $model = ManualLockPassword::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Cấu hình web';

    protected static ?string $navigationLabel = 'Khóa thủ công';

    protected static ?string $modelLabel = 'Mật khẩu khóa thủ công';

    protected static ?string $pluralModelLabel = 'Mật khẩu khóa thủ công';

    protected static ?int $navigationSort = 10;

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $expiredCount = static::getEloquentQuery()
            ->where('is_active', true)
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', now())
            ->count();

        return $expiredCount > 0 ? 'danger' : 'primary';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        $allowedIds = $user->allowedCategoryIds();

        // Chưa gán quyền chi nhánh cụ thể thì mặc định thấy các bộ mật khẩu gắn với chi nhánh của
        // đối tác mình (category.partner_id), không chặn hết.
        if (empty($allowedIds)) {
            return $query->whereHas('category', fn (Builder $q) => $q->where('partner_id', $user->partner_id));
        }

        return $query->whereIn('category_id', $allowedIds);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin bộ mật khẩu')
                ->icon('heroicon-o-key')
                ->iconColor('warning')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')
                            ->label('Tên / Ghi chú')
                            ->placeholder('VD: Tòa A - Tầng 2')
                            ->maxLength(255)
                            ->columnSpan(1),

                        Select::make('category_id')
                            ->label('Chi nhánh')
                            ->options(function () {
                                $user  = auth()->user();
                                $query = Category::query()
                                    ->where('category_type', 'product')
                                    ->orderBy('name');
                                if ($user && ! $user->isSuperAdmin()) {
                                    $allowedIds = $user->allowedCategoryIds();
                                    if (! empty($allowedIds)) {
                                        $query->whereIn('id', $allowedIds);
                                    } else {
                                        $query->where('partner_id', $user->partner_id);
                                    }
                                }
                                return $query->pluck('name', 'id');
                            })
                            ->searchable()
                            ->required()
                            ->native(false)
                            ->columnSpan(1),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('gate_password')
                            ->label('Pass Cổng')
                            ->placeholder('Mật khẩu cổng chung')
                            ->required()
                            ->maxLength(100)
                            ->prefixIcon('heroicon-o-key'),

                        TextInput::make('room_password')
                            ->label('Pass Phòng')
                            ->placeholder('Mật khẩu phòng (nếu có)')
                            ->maxLength(100)
                            ->nullable()
                            ->prefixIcon('heroicon-o-lock-closed'),
                    ]),

                    Textarea::make('notes')
                        ->label('Ghi chú')
                        ->placeholder('Thông tin thêm về cách vào phòng, tòa nhà...')
                        ->rows(2)
                        ->nullable(),
                ])
                ->columns(1),

            Section::make('Thời hạn hiệu lực')
                ->icon('heroicon-o-clock')
                ->iconColor('info')
                ->description('Thiết lập thời gian hiệu lực. Khi hết hạn, bộ mật khẩu sẽ tự động được đánh dấu ngừng hoạt động.')
                ->schema([
                    Grid::make(2)->schema([
                        DateTimePicker::make('valid_from')
                            ->label('Bắt đầu hiệu lực')
                            ->displayFormat('d/m/Y H:i')
                            ->nullable()
                            ->native(false)
                            ->prefixIcon('heroicon-o-play-circle'),

                        DateTimePicker::make('valid_until')
                            ->label('Hết hạn lúc')
                            ->displayFormat('d/m/Y H:i')
                            ->nullable()
                            ->native(false)
                            ->prefixIcon('heroicon-o-stop-circle')
                            ->after('valid_from'),
                    ]),

                    Toggle::make('is_active')
                        ->label('Đang hoạt động')
                        ->default(true)
                        ->onColor('success')
                        ->offColor('gray')
                        ->helperText('Tắt thủ công nếu muốn ngừng áp dụng bộ mật khẩu này.'),
                ])
                ->columns(1),

            Section::make('Phòng áp dụng')
                ->icon('heroicon-o-home')
                ->iconColor('primary')
                ->description('Chọn các phòng sử dụng bộ mật khẩu này.')
                ->schema([
                    Select::make('products')
                        ->label('Chọn phòng')
                        ->relationship(
                            name: 'products',
                            titleAttribute: 'name',
                            modifyQueryUsing: function (Builder $query) {
                                $query->where('is_activated', true);

                                $user = auth()->user();
                                if (! $user || $user->isSuperAdmin()) {
                                    return $query;
                                }

                                $allowedIds = $user->allowedCategoryIds();
                                if (empty($allowedIds)) {
                                    // Product đã tự lọc theo partner_id (BelongsToPartner) — không cần thu hẹp thêm.
                                    return $query;
                                }

                                $productIds = Categorizable::where('categorizable_type', Product::class)
                                    ->whereIn('category_id', $allowedIds)
                                    ->distinct()
                                    ->pluck('categorizable_id');

                                return $query->whereIn('id', $productIds);
                            }
                        )
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->placeholder('Chọn một hoặc nhiều phòng...')
                        ->columnSpanFull(),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Tên / Ghi chú')
                    ->searchable()
                    ->placeholder('—')
                    ->weight('bold')
                    ->description(fn (ManualLockPassword $r) => $r->notes ? \Str::limit($r->notes, 50) : null),

                TextColumn::make('category.name')
                    ->label('Chi nhánh')
                    ->badge()
                    ->color('primary')
                    ->icon('heroicon-m-building-office-2')
                    ->searchable(),

                TextColumn::make('gate_password')
                    ->label('Pass Cổng')
                    ->copyable()
                    ->copyMessage('Đã sao chép!')
                    ->icon('heroicon-o-key')
                    ->fontFamily('mono'),

                TextColumn::make('room_password')
                    ->label('Pass Phòng')
                    ->copyable()
                    ->copyMessage('Đã sao chép!')
                    ->placeholder('—')
                    ->icon('heroicon-o-lock-closed')
                    ->fontFamily('mono'),

                TextColumn::make('valid_from')
                    ->label('Bắt đầu')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->icon('heroicon-o-play-circle')
                    ->color('gray'),

                TextColumn::make('valid_until')
                    ->label('Hết hạn')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Không giới hạn')
                    ->sortable()
                    ->icon('heroicon-o-stop-circle')
                    ->color(fn (ManualLockPassword $r) => $r->isExpired() ? 'danger' : 'warning'),

                TextColumn::make('status_label')
                    ->label('Trạng thái')
                    ->badge()
                    ->color(fn (ManualLockPassword $r) => $r->status_color)
                    ->icon(fn (ManualLockPassword $r) => match ($r->status_label) {
                        'Đang hoạt động' => 'heroicon-o-check-circle',
                        'Sắp hết hạn'    => 'heroicon-o-exclamation-circle',
                        'Đã hết hạn'     => 'heroicon-o-x-circle',
                        default          => 'heroicon-o-minus-circle',
                    }),

                TextColumn::make('products_count')
                    ->label('Số phòng')
                    ->counts('products')
                    ->badge()
                    ->color('success')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                PartnerTableHelpers::column('category.partner.name'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Trạng thái')
                    ->trueLabel('Đang hoạt động')
                    ->falseLabel('Ngừng hoạt động')
                    ->native(false),

                SelectFilter::make('category_id')
                    ->label('Chi nhánh')
                    ->options(fn () => Category::where('category_type', 'product')->pluck('name', 'id'))
                    ->native(false),

                PartnerTableHelpers::filterThroughRelation('category'),
            ])
            ->actions([
                Action::make('deactivate')
                    ->label('Ngừng hoạt động')
                    ->icon('heroicon-o-stop-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Ngừng hoạt động bộ mật khẩu này?')
                    ->modalDescription('Bộ mật khẩu sẽ không còn được áp dụng cho các phòng liên quan.')
                    ->visible(fn (ManualLockPassword $r) => $r->is_active)
                    ->action(fn (ManualLockPassword $r) => $r->deactivate()),

                Action::make('activate')
                    ->label('Kích hoạt lại')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Kích hoạt lại bộ mật khẩu này?')
                    ->visible(fn (ManualLockPassword $r) => ! $r->is_active)
                    ->action(fn (ManualLockPassword $r) => $r->update(['is_active' => true])),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListManualLockPasswords::route('/'),
            'create' => Pages\CreateManualLockPassword::route('/create'),
            'edit'   => Pages\EditManualLockPassword::route('/{record}/edit'),
        ];
    }
}
