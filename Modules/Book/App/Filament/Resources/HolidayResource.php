<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources;

use App\Models\Holiday;
use App\Models\User;
use App\Support\HolidayPrices;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Book\App\Filament\Resources\HolidayResource\Pages;

/**
 * DANH MỤC NGÀY LỄ, TẾT (ngoài bảng giá): khai báo 1 lần, rồi chọn từng ngày lễ vào bảng giá / bộ giá có áp dụng
 * (mục "Giá ngày lễ, Tết"). Cùng nghiệp vụ API /api/admin/holidays. Ngày lễ dùng chung (365Home) đối tác chỉ xem + dùng.
 * Ngày lưu theo App\Support\HolidayPrices: lặp hàng năm "MM-DD", đúng 1 năm "YYYY-MM-DD" — ô chọn ngày dùng năm nhuận 2024 chỉ để hiển thị dd/mm.
 */
class HolidayResource extends Resource
{
    protected static ?string $model = Holiday::class;
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?int $navigationSort = -1;

    public static function getNavigationLabel(): string
    {
        return 'Ngày lễ, Tết';
    }

    public static function getModelLabel(): string
    {
        return 'Ngày lễ';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Ngày lễ, Tết';
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return $user instanceof User ? parent::getEloquentQuery()->visibleTo($user) : parent::getEloquentQuery()->whereNull('partner_id');
    }

    // Cùng mức quyền với "Bảng giá" (super_admin hoặc quyền Shield của bảng giá): ngày lễ chỉ để đưa vào bảng giá.
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('view_any_price::board') ?? false);
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('create_price::board') ?? false);
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        return $user instanceof User && $record instanceof Holiday && $record->editableBy($user)
            && ($user->isSuperAdmin() || $user->can('update_price::board'));
    }

    public static function canDelete($record): bool
    {
        $user = auth()->user();

        return $user instanceof User && $record instanceof Holiday && $record->editableBy($user)
            && ($user->isSuperAdmin() || $user->can('delete_price::board'));
    }

    public static function form(Form $form): Form
    {
        $format = fn (Get $get) => $get('recurring') ? 'd/m' : 'd/m/Y';

        return $form->schema([
            TextInput::make('name')->label('Tên ngày lễ')->required()->maxLength(100)->placeholder('Tết Âm lịch 2027, 30/4 - 1/5...')->columnSpanFull(),
            Toggle::make('recurring')->label('Lặp hàng năm')->default(true)->live()->inline(false)
                ->helperText('Bật cho lễ dương lịch cố định (30/4, 24/12...). Tết Âm lịch đổi ngày mỗi năm: tắt và nhập đúng ngày của từng năm.')
                ->columnSpanFull(),
            DatePicker::make('from_day')->label('Từ ngày')->native(false)->required()->displayFormat($format),
            DatePicker::make('to_day')->label('Đến ngày')->native(false)->required()->displayFormat($format)
                ->rules([fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                    $recurring = (bool) $get('recurring');
                    $errors    = HolidayPrices::rangeErrors(
                        $recurring,
                        (string) static::dayToStorage($get('from_day'), $recurring),
                        (string) static::dayToStorage($value, $recurring),
                    );

                    if ($errors) {
                        $fail((string) collect($errors)->flatten()->first());
                    }
                }]),
            Toggle::make('is_active')->label('Đang dùng')->default(true)->inline(false)
                ->helperText('Tắt thì không hiện khi chọn ngày lễ cho bảng giá (bảng giá đã thêm không đổi).'),
            TextInput::make('sort_order')->label('Thứ tự')->numeric()->integer()->minValue(0)->default(0),
            Textarea::make('note')->label('Ghi chú')->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Ngày lễ')->searchable()->sortable(),
                TextColumn::make('recurring')->label('Kiểu')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Hàng năm' : 'Một năm')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
                TextColumn::make('from_day')->label('Khoảng ngày')
                    ->formatStateUsing(fn (string $state, Holiday $record) => static::dayLabel($record->from_day, $record->recurring) . ' → ' . static::dayLabel($record->to_day, $record->recurring)),
                TextColumn::make('partner.name')->label('Của')->placeholder('Dùng chung (365Home)')->toggleable(),
                ToggleColumn::make('is_active')->label('Đang dùng')->disabled(fn (Holiday $record) => ! static::canEdit($record)),
            ])
            ->defaultSort('sort_order')
            ->actions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data) => static::toFormData($data))
                    ->mutateFormDataUsing(fn (array $data) => static::toStorageData($data)),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageHolidays::route('/'),
        ];
    }

    /** "MM-DD" / "YYYY-MM-DD" đã lưu → "dd/mm" hoặc "dd/mm/yyyy" để đọc. */
    public static function dayLabel(string $day, bool $recurring): string
    {
        return $recurring
            ? Carbon::createFromFormat('!Y-m-d', '2024-' . $day)->format('d/m')
            : Carbon::createFromFormat('!Y-m-d', $day)->format('d/m/Y');
    }

    /** Ngày từ ô chọn ngày → định dạng lưu. */
    public static function dayToStorage(mixed $value, bool $recurring): ?string
    {
        return filled($value) ? Carbon::parse($value)->format($recurring ? 'm-d' : 'Y-m-d') : null;
    }

    /** Bản ghi → dữ liệu điền form (lặp hàng năm đưa về năm nhuận 2024 để ô chọn ngày hiển thị dd/mm). */
    public static function toFormData(array $data): array
    {
        if (! empty($data['recurring'])) {
            $data['from_day'] = '2024-' . $data['from_day'];
            $data['to_day']   = '2024-' . $data['to_day'];
        }

        return $data;
    }

    /** Dữ liệu form → cột lưu. */
    public static function toStorageData(array $data): array
    {
        $recurring        = ! empty($data['recurring']);
        $data['from_day'] = static::dayToStorage($data['from_day'] ?? null, $recurring);
        $data['to_day']   = static::dayToStorage($data['to_day'] ?? null, $recurring);

        return $data;
    }
}
