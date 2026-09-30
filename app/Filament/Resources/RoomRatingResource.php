<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\RoomRatingResource\Pages;
use App\Models\RoomRating;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RoomRatingResource extends Resource
{
    protected static ?string $model = RoomRating::class;

    protected static ?string $navigationIcon   = 'heroicon-o-star';
    protected static ?string $navigationGroup  = 'Quản lý';
    protected static ?string $navigationLabel  = 'Đánh giá phòng';
    protected static ?string $modelLabel       = 'Đánh giá';
    protected static ?string $pluralModelLabel = 'Đánh giá phòng';
    protected static ?int    $navigationSort   = 26;

    // Cùng quy ước với API admin (Api\Admin\RatingController): quyền 'manage_ratings', super_admin bypass.
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user && ($user->isSuperAdmin() || $user->can('manage_ratings'));
    }

    // Khách tự tạo đánh giá qua app — admin chỉ xem, trả lời, quản lý ảnh và xoá.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // Chỉ phòng Homestay — nhận xét phòng MiniHouse quản trị riêng ở panel MiniHouse.
        $query = parent::getEloquentQuery()->forHomestay()->with(['customer:id,fullname,phone', 'room:id,name', 'media']);
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        $allowed = $user->allowedCategoryIds();

        if (empty($allowed)) {
            return $query;
        }

        return $query->whereHas('room.categories', fn (Builder $q) => $q->whereIn('categories.id', $allowed));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Đánh giá của khách')->schema([
                Placeholder::make('room')
                    ->label('Phòng')
                    ->content(fn (?RoomRating $record) => $record?->room?->name ?? '—'),
                Placeholder::make('customer')
                    ->label('Khách hàng')
                    ->content(fn (?RoomRating $record) => trim(($record?->customer?->fullname ?? 'Ẩn danh') . ' ' . ($record?->customer?->phone ?? ''))),
                Placeholder::make('star')
                    ->label('Số sao')
                    ->content(fn (?RoomRating $record) => $record ? $record->star . ' / 5' : '—'),
                Placeholder::make('comment')
                    ->label('Nhận xét')
                    ->content(fn (?RoomRating $record) => filled($record?->comment) ? $record->comment : '—')
                    ->columnSpanFull(),
                SpatieMediaLibraryFileUpload::make('images')
                    ->label('Ảnh đính kèm')
                    ->collection(RoomRating::IMAGE_COLLECTION)
                    ->conversion('thumb')
                    ->multiple()
                    ->reorderable()
                    ->image()
                    ->maxFiles(RoomRating::MAX_IMAGES)
                    ->maxSize(RoomRating::MAX_IMAGE_KB)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->helperText('Tối đa ' . RoomRating::MAX_IMAGES . ' ảnh, mỗi ảnh ≤ ' . (RoomRating::MAX_IMAGE_KB / 1024) . 'MB.')
                    ->columnSpanFull(),
            ])->columns(2),

            Section::make('Phản hồi của quản trị')->schema([
                Textarea::make('admin_reply')
                    ->label('Nội dung phản hồi')
                    ->rows(3)
                    ->maxLength(1000),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                SpatieMediaLibraryImageColumn::make('images')
                    ->label('Ảnh')
                    ->collection(RoomRating::IMAGE_COLLECTION)
                    ->conversion('thumb')
                    ->circular()
                    ->stacked()
                    ->limit(3)
                    ->limitedRemainingText(),
                TextColumn::make('room.name')
                    ->label('Phòng')
                    ->searchable(),
                TextColumn::make('customer.fullname')
                    ->label('Khách hàng')
                    ->default('Ẩn danh')
                    ->searchable(),
                TextColumn::make('star')
                    ->label('Sao')
                    ->icon('heroicon-s-star')
                    ->iconColor('warning')
                    ->sortable(),
                TextColumn::make('comment')
                    ->label('Nhận xét')
                    ->limit(60)
                    ->wrap()
                    ->searchable(),
                IconColumn::make('admin_reply')
                    ->label('Đã phản hồi')
                    ->boolean()
                    ->state(fn (RoomRating $record) => filled($record->admin_reply)),
                TextColumn::make('created_at')
                    ->label('Ngày đăng')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('star')
                    ->label('Số sao')
                    ->options([5 => '5 sao', 4 => '4 sao', 3 => '3 sao', 2 => '2 sao', 1 => '1 sao']),
                TernaryFilter::make('has_reply')
                    ->label('Phản hồi')
                    ->placeholder('Tất cả')
                    ->trueLabel('Đã phản hồi')
                    ->falseLabel('Chưa phản hồi')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('admin_reply'),
                        false: fn (Builder $q) => $q->whereNull('admin_reply'),
                        blank: fn (Builder $q) => $q,
                    ),
            ])
            ->actions([
                EditAction::make()->label('Phản hồi / ảnh'),
                DeleteAction::make()->label('Xóa'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoomRatings::route('/'),
            'edit'  => Pages\EditRoomRating::route('/{record}/edit'),
        ];
    }
}
