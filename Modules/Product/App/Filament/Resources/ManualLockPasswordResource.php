<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Mật khẩu khóa thủ công';

    protected static ?string $modelLabel = 'Mật khẩu khóa thủ công';

    protected static ?string $pluralModelLabel = 'Mật khẩu khóa thủ công';

    protected static ?int $navigationSort = 10;

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        $allowedIds = $user->allowedCategoryIds();

        if (empty($allowedIds)) {
            return $query->whereRaw('1 = 0');
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
                    TextInput::make('name')
                        ->label('Tên / Ghi chú')
                        ->placeholder('VD: Tòa A - Tầng 2')
                        ->maxLength(255)
                        ->inlineLabel(),

                    Select::make('category_id')
                        ->label('Chi nhánh')
                        ->options(function () {
                            $user  = auth()->user();
                            $query = Category::query()
                                ->where('category_type', 'product')
                                ->orderBy('name');
                            if ($user && ! $user->isSuperAdmin()) {
                                $allowedIds = $user->allowedCategoryIds();
                                if (empty($allowedIds)) return [];
                                $query->whereIn('id', $allowedIds);
                            }
                            return $query->pluck('name', 'id');
                        })
                        ->searchable()
                        ->required()
                        ->native(false)
                        ->inlineLabel(),

                    TextInput::make('gate_password')
                        ->label('Pass Cổng')
                        ->placeholder('Mật khẩu cổng chung')
                        ->required()
                        ->maxLength(100)
                        ->inlineLabel(),

                    TextInput::make('room_password')
                        ->label('Pass Phòng')
                        ->placeholder('Mật khẩu phòng (nếu có)')
                        ->maxLength(100)
                        ->nullable()
                        ->inlineLabel(),

                    Textarea::make('notes')
                        ->label('Ghi chú')
                        ->placeholder('Thông tin thêm về cách vào phòng, tòa nhà...')
                        ->rows(3)
                        ->nullable()
                        ->inlineLabel(),
                ])
                ->columns(1),

            Section::make('Phòng áp dụng')
                ->icon('heroicon-o-home')
                ->iconColor('primary')
                ->description('Chọn các phòng sử dụng bộ mật khẩu này. Các phòng được chọn sẽ được đánh dấu là dùng khóa thủ công.')
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
                                    return $query->whereRaw('1 = 0');
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
                        ->label('Phòng áp dụng')
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
                    ->weight('bold'),

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
                    ->icon('heroicon-o-key'),

                TextColumn::make('room_password')
                    ->label('Pass Phòng')
                    ->copyable()
                    ->copyMessage('Đã sao chép!')
                    ->placeholder('—')
                    ->icon('heroicon-o-lock-closed'),

                TextColumn::make('products_count')
                    ->label('Số phòng')
                    ->counts('products')
                    ->badge()
                    ->color('success')
                    ->sortable(),

                TextColumn::make('notes')
                    ->label('Ghi chú')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
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
