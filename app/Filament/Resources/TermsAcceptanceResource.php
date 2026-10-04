<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TermsAcceptanceResource\Pages;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

// Lịch sử khách ĐỒNG Ý Điều khoản khi đăng ký — chỉ xem (bất biến), CHỈ Super Admin. Lưu: phiên bản, ngày giờ, thông tin khách, gói/giá, mã đơn/mã giao dịch, tick đồng ý.
class TermsAcceptanceResource extends Resource
{
    protected static ?string $model = TermsAcceptance::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $modelLabel = 'lượt đồng ý Điều khoản';

    public static function getNavigationLabel(): string
    {
        return 'Lịch sử đồng ý ' . TermsVersion::panelLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return 'Lịch sử đồng ý Điều khoản ' . TermsVersion::panelLabel();
    }

    // Mỗi panel chỉ thấy lịch sử đồng ý của loại Điều khoản của mình.
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', TermsVersion::typeForCurrentPanel());
    }

    protected static ?string $slug = 'terms-acceptances';

    protected static ?int $navigationSort = 21;

    private static function isSuperAdmin(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canViewAny(): bool
    {
        return self::isSuperAdmin();
    }

    public static function canView($record): bool
    {
        return self::isSuperAdmin();
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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Điều khoản đã đồng ý')->columns(3)->schema([
                TextInput::make('terms_version_label')->label('Phiên bản'),
                TextInput::make('type')->label('Áp dụng cho')->formatStateUsing(fn ($state) => TermsVersion::TYPES[$state] ?? $state),
                Toggle::make('accepted')->label('Đã tick đồng ý'),
                TextInput::make('accepted_at')->label('Thời điểm đồng ý')->formatStateUsing(fn ($state) => $state ? \Illuminate\Support\Carbon::parse($state)->format('H:i:s d/m/Y') : null),
                TextInput::make('terms_content_hash')->label('Mã băm nội dung (SHA-256)')->columnSpan(2),
                Textarea::make('terms_text')->label('Nội dung Điều khoản đã đồng ý')->rows(14)->columnSpanFull()
                    ->afterStateHydrated(fn (Textarea $c, ?TermsAcceptance $record) => $c->state($record?->version?->content)),
            ]),
            Section::make('Thông tin khách')->columns(2)->schema([
                TextInput::make('full_name')->label('Họ tên'),
                TextInput::make('phone')->label('Số điện thoại'),
                TextInput::make('email')->label('Email'),
                TextInput::make('business_name')->label('Tên cơ sở'),
            ]),
            Section::make('Gói / giá / đơn')->columns(2)->schema([
                TextInput::make('plan_name')->label('Gói'),
                TextInput::make('periods')->label('Số kỳ (tháng)'),
                TextInput::make('amount_vnd')->label('Số tiền (đ)')->formatStateUsing(fn ($state) => $state !== null ? number_format((int) $state, 0, ',', '.') : null),
                TextInput::make('order_code')->label('Mã đơn'),
                TextInput::make('transaction_ref')->label('Mã giao dịch'),
            ]),
            Section::make('Dấu vết kỹ thuật')->columns(2)->collapsed()->schema([
                TextInput::make('ip')->label('IP'),
                TextInput::make('source')->label('Nguồn'),
                Textarea::make('user_agent')->label('Trình duyệt')->rows(2)->columnSpanFull(),
            ]),
        ])->disabled();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('accepted_at')->label('Thời điểm')->dateTime('H:i d/m/Y')->sortable(),
                TextColumn::make('full_name')->label('Khách')->description(fn (TermsAcceptance $r) => $r->phone)->searchable(['full_name', 'phone', 'email']),
                TextColumn::make('business_name')->label('Cơ sở')->searchable()->toggleable(),
                TextColumn::make('terms_version_label')->label('Phiên bản')->badge(),
                IconColumn::make('accepted')->label('Đã tick')->boolean(),
                TextColumn::make('plan_name')->label('Gói')->placeholder('—')->description(fn (TermsAcceptance $r) => $r->periods ? $r->periods . ' kỳ' : null),
                TextColumn::make('amount_vnd')->label('Số tiền')->formatStateUsing(fn ($state) => $state !== null ? number_format((int) $state, 0, ',', '.') . 'đ' : '—'),
                TextColumn::make('order_code')->label('Mã đơn')->placeholder('—')->searchable()->copyable(),
                TextColumn::make('transaction_ref')->label('Mã giao dịch')->placeholder('—')->searchable()->copyable(),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('accepted_at', 'desc')
            ->filters([
                SelectFilter::make('terms_version_id')->label('Phiên bản')->options(fn () => TermsVersion::query()->where('type', TermsVersion::typeForCurrentPanel())->orderByDesc('id')->pluck('version', 'id')->map(fn ($v) => 'v' . $v)->all()),
                Filter::make('accepted_between')->form([
                    DatePicker::make('from')->label('Từ ngày'), DatePicker::make('to')->label('Đến ngày'),
                ])->query(fn (Builder $q, array $data) => $q
                    ->when($data['from'] ?? null, fn ($q, $v) => $q->where('accepted_at', '>=', \Illuminate\Support\Carbon::parse($v)->startOfDay()))
                    ->when($data['to'] ?? null, fn ($q, $v) => $q->where('accepted_at', '<=', \Illuminate\Support\Carbon::parse($v)->endOfDay()))),
            ])
            ->actions([ViewAction::make()->label('Xem')])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTermsAcceptances::route('/'),
            'view'  => Pages\ViewTermsAcceptance::route('/{record}'),
        ];
    }
}
