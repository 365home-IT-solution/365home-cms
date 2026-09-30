<?php

namespace Modules\Minihouse\App\Filament\Resources;

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
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\RoomReviewResource\Pages;

// NHẬN XÉT PHÒNG hiển thị trên trang chi tiết phòng (người xem phòng TRƯỚC KHI thuê viết) — KHÁC HẲN
// "Phản hồi khách thuê" (TenantFeedbackResource: khách ĐÃ thuê báo cáo tình trạng/sự cố qua Portal,
// không công khai). Dùng chung bảng room_ratings với đánh giá Homestay nhưng CHỈ hiện phòng MiniHouse
// (RoomRating::scopeForMinihouse()). Khách tự viết qua app/trang phòng — nhân viên chỉ xem, trả lời,
// quản lý ảnh đính kèm và xoá (vd spam, ngôn từ không phù hợp), không tạo mới.
class RoomReviewResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = RoomRating::class;
    protected static ?string $navigationIcon  = 'heroicon-o-star';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Nhận xét phòng';
    protected static ?int $navigationSort = 11;

    public static function getModelLabel(): string { return 'Nhận xét'; }
    public static function getPluralModelLabel(): string { return 'Nhận xét phòng'; }

    public static function permissionGroup(): string
    {
        return 'room_reviews';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->forMinihouse()->with(['customer:id,fullname,phone', 'room:id,name,building_id', 'media']);
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        // Nhân viên chỉ thấy nhận xét của phòng thuộc toà nhà mình được quản lý.
        return $query->whereHas('room', fn (Builder $room) => $room->whereIn('building_id', $user->rootBuildingIds()));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Nhận xét của khách')->schema([
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
                    ->openable()
                    ->maxFiles(RoomRating::MAX_IMAGES)
                    ->maxSize(RoomRating::MAX_IMAGE_KB)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->helperText('Tối đa ' . RoomRating::MAX_IMAGES . ' ảnh, mỗi ảnh ≤ ' . (RoomRating::MAX_IMAGE_KB / 1024) . 'MB.')
                    ->columnSpanFull(),
            ])->columns(2),

            Section::make('Phản hồi của chủ nhà')
                ->description('Hiển thị công khai dưới nhận xét trên trang phòng.')
                ->schema([
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
                SpatieMediaLibraryImageColumn::make('images')->label('Ảnh')
                    ->collection(RoomRating::IMAGE_COLLECTION)->conversion('thumb')
                    ->circular()->stacked()->limit(3)->limitedRemainingText(),
                TextColumn::make('room.name')->label('Phòng')->searchable(),
                TextColumn::make('customer.fullname')->label('Khách hàng')->default('Ẩn danh')->searchable(),
                TextColumn::make('star')->label('Sao')->icon('heroicon-s-star')->iconColor('warning')->sortable(),
                TextColumn::make('comment')->label('Nhận xét')->limit(60)->wrap()->searchable(),
                IconColumn::make('admin_reply')->label('Đã phản hồi')->boolean()
                    ->state(fn (RoomRating $record) => filled($record->admin_reply)),
                TextColumn::make('created_at')->label('Ngày đăng')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('star')->label('Số sao')
                    ->options([5 => '5 sao', 4 => '4 sao', 3 => '3 sao', 2 => '2 sao', 1 => '1 sao']),
                TernaryFilter::make('has_reply')->label('Phản hồi')
                    ->placeholder('Tất cả')->trueLabel('Đã phản hồi')->falseLabel('Chưa phản hồi')
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
            'index' => Pages\ListRoomReviews::route('/'),
            'edit'  => Pages\EditRoomReview::route('/{record}/edit'),
        ];
    }
}
