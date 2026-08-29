<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ConsultationLogResource\Pages;
use App\Filament\Support\PartnerTableHelpers;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Employee\Entities\ConsultationLog;

// Nơi nhân viên (thường là lễ tân) tự ghi nhận từng lượt tư vấn khách hàng — dùng để tính lương
// theo lượt cho vị trí piece_rate (xem SalaryType::calc_type, Employee::getFinalSalaryAttribute()).
// Lượt dọn phòng KHÔNG cần ghi ở đây — đã tự động ghi nhận sẵn ở màn "Kiểm tra dọn phòng".
class ConsultationLogResource extends Resource
{
    protected static ?string $model = ConsultationLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Tư vấn khách hàng';

    protected static ?string $modelLabel = 'lượt tư vấn';

    protected static ?string $pluralModelLabel = 'Tư vấn khách hàng';

    // Ẩn khỏi menu theo yêu cầu — giữ nguyên route/data/API (lương piece_rate vẫn tính được nếu có
    // dữ liệu cũ). Bật lại bằng cách xoá method này.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        $currentEmployee = auth()->user()?->employee;

        return $form->schema([
            Select::make('employee_id')
                ->label('Nhân viên tư vấn')
                ->relationship('employee', 'name')
                ->required()
                ->searchable()
                ->preload()
                // Nhân viên thường chỉ ghi nhận cho chính mình — ẩn field, tự gán ở
                // CreateConsultationLog::mutateFormDataBeforeCreate(). Chủ đối tác/super_admin
                // ghi hộ thì được chọn bất kỳ nhân viên nào trong đối tác.
                ->visible(fn () => ! $currentEmployee)
                ->default(fn () => $currentEmployee?->id),

            TextInput::make('customer_name')
                ->label('Tư vấn cho khách hàng')
                ->placeholder('Tên khách hàng')
                ->required(),

            TextInput::make('customer_phone')
                ->label('Số điện thoại khách (nếu có)'),

            DateTimePicker::make('consulted_at')
                ->label('Thời gian tư vấn')
                ->default(now())
                ->seconds(false)
                ->required(),

            Textarea::make('note')
                ->label('Ghi chú')
                ->rows(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.name')
                    ->label('Nhân viên')
                    ->searchable(),

                TextColumn::make('customer_name')
                    ->label('Khách hàng')
                    ->searchable(),

                TextColumn::make('customer_phone')
                    ->label('SĐT')
                    ->placeholder('—'),

                TextColumn::make('consulted_at')
                    ->label('Thời gian tư vấn')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                PartnerTableHelpers::column(),
            ])
            ->filters([
                PartnerTableHelpers::filter(),
            ])
            ->defaultSort('consulted_at', 'desc');
    }

    // Nhân viên thường (không phải chủ đối tác/super_admin) chỉ thấy lượt tư vấn của CHÍNH MÌNH
    // — BelongsToPartner đã giới hạn theo đối tác, đây giới hạn thêm 1 lớp theo từng cá nhân.
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if ($user && ! $user->isSuperAdmin() && ! $user->isPartnerOwner()) {
            $query->where('employee_id', $user->employee?->id ?? 0);
        }

        return $query;
    }

    // Trước đây hardcode "super_admin HOẶC chủ đối tác HOẶC có hồ sơ nhân viên" — bỏ qua hoàn
    // toàn permission system. Permission 'view_any_consultation::log' trước đây CHƯA TỪNG được
    // Filament Shield sinh ra (đã chạy shield:generate để tạo), giờ đọc đúng permission đó.
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_consultation::log') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListConsultationLogs::route('/'),
            'create' => Pages\CreateConsultationLog::route('/create'),
        ];
    }
}
