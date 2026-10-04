<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TermsVersionResource\Pages;
use App\Models\TermsVersion;
use App\Services\TermsService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

// Điều khoản dịch vụ khi đăng ký (Homestay / MiniHouse mua gói) — CHỈ Super Admin. Mỗi lần bổ sung/cập nhật là TẠO PHIÊN BẢN MỚI;
// phiên bản cũ không sửa/xoá được (khách đã đồng ý bản nào thì lưu đúng bản đó để đối chiếu — xem "Lịch sử đồng ý Điều khoản").
class TermsVersionResource extends Resource
{
    protected static ?string $model = TermsVersion::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $modelLabel = 'phiên bản Điều khoản';

    // Mỗi panel chỉ thấy/quản lý Điều khoản của chính mình (Homestay / MiniHouse).
    public static function getNavigationLabel(): string
    {
        return 'Điều khoản ' . TermsVersion::panelLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return 'Điều khoản dịch vụ ' . TermsVersion::panelLabel();
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->where('type', TermsVersion::typeForCurrentPanel());
    }

    protected static ?string $slug = 'terms-versions';

    protected static ?int $navigationSort = 20;

    private static function isSuperAdmin(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canViewAny(): bool
    {
        return self::isSuperAdmin();
    }

    public static function canCreate(): bool
    {
        return self::isSuperAdmin();
    }

    public static function canView($record): bool
    {
        return self::isSuperAdmin();
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Placeholder::make('note')->hiddenLabel()->columnSpanFull()
                ->visible(fn (string $operation) => $operation === 'create')
                ->content('Lưu sẽ tạo PHIÊN BẢN MỚI có hiệu lực theo ngày chọn; các phiên bản cũ được giữ nguyên để đối chiếu. Khách đăng ký ở thời điểm nào thì lưu đúng phiên bản đã đồng ý ở thời điểm đó.'),
            TextInput::make('version')->label('Số phiên bản')->required()->maxLength(20)
                ->default(fn () => app(TermsService::class)->nextVersionLabel(TermsVersion::typeForCurrentPanel()))
                ->helperText('Tự gợi ý số tiếp theo; không được trùng phiên bản đã có.'),
            TextInput::make('title')->label('Tiêu đề')->required()->maxLength(255)->columnSpanFull(),
            DateTimePicker::make('effective_at')->label('Hiệu lực từ')->required()->seconds(false)->default(now()),
            Textarea::make('content')->label('Nội dung Điều khoản')->required()->minLength(20)->rows(22)->columnSpanFull()
                ->helperText('Văn bản thuần (xuống dòng giữ nguyên). Sau khi lưu KHÔNG sửa được — cần thay đổi thì tạo phiên bản mới.'),
            TextInput::make('content_hash')->label('Mã băm SHA-256')->disabled()->visible(fn (string $operation) => $operation === 'view')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')->label('Phiên bản')->badge()->sortable(),
                TextColumn::make('title')->label('Tiêu đề')->searchable()->wrap(),
                TextColumn::make('effective_at')->label('Hiệu lực từ')->dateTime('H:i d/m/Y')->sortable(),
                TextColumn::make('acceptances_count')->label('Lượt đồng ý')->counts('acceptances')->badge()->color('gray'),
                TextColumn::make('creator.fullname')->label('Người tạo')->placeholder('Hệ thống'),
                TextColumn::make('content_hash')->label('Mã băm')->limit(12)->tooltip(fn (TermsVersion $r) => $r->content_hash)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('effective_at', 'desc')
            ->actions([ViewAction::make()->label('Xem')])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTermsVersions::route('/'),
            'create' => Pages\CreateTermsVersion::route('/create'),
            'view'   => Pages\ViewTermsVersion::route('/{record}'),
        ];
    }
}
