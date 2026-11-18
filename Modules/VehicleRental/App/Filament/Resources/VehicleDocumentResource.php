<?php

declare(strict_types=1);

namespace Modules\VehicleRental\App\Filament\Resources;

use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\VehicleRental\App\Filament\Resources\VehicleDocumentResource\Pages;
use Modules\VehicleRental\App\Models\VehicleDocument;
use Modules\VehicleRental\App\Support\VehicleRentalAccess;

// Duyệt giấy tờ (CCCD + GPLX + ảnh chân dung) của khách thuê xe. Super Admin + vai trò "Nhân viên thuê xe".
class VehicleDocumentResource extends Resource
{
    protected static ?string $model = VehicleDocument::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Duyệt giấy tờ';

    protected static ?string $modelLabel = 'Hồ sơ giấy tờ';

    protected static ?string $pluralModelLabel = 'Duyệt giấy tờ';

    protected static ?string $slug = 'rental-vehicle-documents';

    protected static ?int $navigationSort = 4;

    public const STATUSES = [
        VehicleDocument::STATUS_PENDING  => 'Chờ duyệt',
        VehicleDocument::STATUS_APPROVED => 'Đã duyệt',
        VehicleDocument::STATUS_REJECTED => 'Từ chối',
    ];

    public static function canViewAny(): bool
    {
        return VehicleRentalAccess::canReviewDocuments(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function getNavigationBadge(): ?string
    {
        $n = VehicleDocument::query()->where('status', VehicleDocument::STATUS_PENDING)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('customer');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->orderByRaw("status = 'pending' desc"))
            ->columns([
                Tables\Columns\TextColumn::make('customer.fullname')->label('Khách')->placeholder('—')->searchable()
                    ->description(fn (VehicleDocument $r) => $r->customer?->phone),
                Tables\Columns\TextColumn::make('full_name')->label('Họ tên trên giấy tờ')->placeholder('—'),
                Tables\Columns\TextColumn::make('license_class')->label('Hạng GPLX')->placeholder('—'),
                Tables\Columns\TextColumn::make('license_expires_on')->label('GPLX hết hạn')->date('d/m/Y')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        VehicleDocument::STATUS_APPROVED => 'success',
                        VehicleDocument::STATUS_REJECTED => 'danger',
                        default                          => 'warning',
                    }),
                Tables\Columns\TextColumn::make('updated_at')->label('Gửi lúc')->dateTime('d/m/Y H:i'),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->label('Trạng thái')->options(self::STATUSES)])
            ->actions([Tables\Actions\ViewAction::make()->label('Xem & duyệt')])
            ->emptyStateHeading('Chưa có hồ sơ giấy tờ nào');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Thông tin')->columns(3)->schema([
                Infolists\Components\TextEntry::make('customer.fullname')->label('Khách')->placeholder('—'),
                Infolists\Components\TextEntry::make('customer.phone')->label('Điện thoại')->placeholder('—'),
                Infolists\Components\TextEntry::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state),
                Infolists\Components\TextEntry::make('full_name')->label('Họ tên trên giấy tờ')->placeholder('—'),
                Infolists\Components\TextEntry::make('id_number')->label('Số CCCD')->placeholder('—'),
                Infolists\Components\TextEntry::make('license_number')->label('Số GPLX')->placeholder('—'),
                Infolists\Components\TextEntry::make('license_class')->label('Hạng GPLX')->placeholder('—'),
                Infolists\Components\TextEntry::make('license_expires_on')->label('GPLX hết hạn')->date('d/m/Y')->placeholder('—'),
                Infolists\Components\TextEntry::make('reject_reason')->label('Lý do từ chối')->placeholder('—'),
            ]),
            Infolists\Components\Section::make('Ảnh giấy tờ')->columns(3)->schema([
                Infolists\Components\ImageEntry::make('id_front')->label('CCCD mặt trước')->disk('public')->height(180),
                Infolists\Components\ImageEntry::make('id_back')->label('CCCD mặt sau')->disk('public')->height(180),
                Infolists\Components\ImageEntry::make('selfie')->label('Ảnh chân dung')->disk('public')->height(180),
                Infolists\Components\ImageEntry::make('license_front')->label('GPLX mặt trước')->disk('public')->height(180),
                Infolists\Components\ImageEntry::make('license_back')->label('GPLX mặt sau')->disk('public')->height(180),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicleDocuments::route('/'),
            'view'  => Pages\ViewVehicleDocument::route('/{record}'),
        ];
    }
}
