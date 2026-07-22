<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\PartnerTableHelpers;
use App\Models\Partner;
use Filament\Actions\Action as HeaderAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Modules\Employee\Entities\AllowanceType;
use Modules\Employee\Entities\DeductionType;
use Modules\Employee\Entities\Employee;
use Modules\Employee\Entities\SalaryTemplate;
use Modules\Employee\Entities\SalaryType;

// Bảng thống kê lương từng nhân viên — đối tác xem để biết cuối tháng phải trả bao nhiêu cho
// từng nhân viên (lương cơ bản + phụ cấp - giảm trừ). super_admin xem được của mọi đối tác (lọc
// theo đối tác), chủ đối tác chỉ thấy nhân viên của mình (Employee đã tự lọc qua BelongsToPartner).
class SalaryReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Thống kê lương';

    protected static ?string $title = 'Thống kê lương nhân viên';

    protected static string $view = 'filament.pages.salary-report';

    // Trước đây hardcode "super_admin HOẶC chủ đối tác" — bỏ qua hoàn toàn hệ thống phân quyền
    // (Roles & Permissions): dù admin có tick/bỏ tick quyền "Thống kê lương" cho 1 role thế nào,
    // MỌI tài khoản chủ đối tác vẫn luôn vào được trang này, không role nào chặn được. Filament
    // Shield vốn CHƯA từng sinh permission cho trang này (đã chạy `shield:generate --page` để tạo
    // 'page_SalaryReport') — giờ đọc đúng permission đó, để việc tick/bỏ tick ở Roles & Permissions
    // thực sự có tác dụng. super_admin vẫn luôn qua được nhờ Gate::before của Filament Shield.
    public static function canAccess(): bool
    {
        return auth()->user()?->can('page_SalaryReport') ?? false;
    }

    // 4 nút tạo nhanh danh mục lương ngay trên trang thống kê — không cần qua form nhân viên mới
    // tạo được "Loại lương"/"Mẫu lương"/"Loại phụ cấp"/"Loại giảm trừ". super_admin phải chọn
    // đối tác sở hữu danh mục; chủ đối tác thì tự động gán đúng đối tác của mình.
    protected function getHeaderActions(): array
    {
        return [
            HeaderAction::make('createSalaryType')
                ->label('+ Loại lương')
                ->color('gray')
                ->modalHeading('Thêm loại lương')
                ->form([
                    ...self::partnerField(),
                    TextInput::make('name')
                        ->label('Tên loại lương')
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, callable $set) => $set('slug', Str::slug($get('name')))),
                    TextInput::make('slug')->label('Slug')->required(),
                    Select::make('calc_type')
                        ->label('Cách tính lương')
                        ->options([
                            'fixed'      => 'Lương cố định',
                            'piece_rate' => 'Theo lượt công việc (vd Lễ tân)',
                        ])
                        ->default('fixed')
                        ->live()
                        ->required(),
                    TextInput::make('room_cleaning_rate')
                        ->label('Đơn giá / lượt dọn phòng')
                        ->numeric()
                        ->minValue(0)
                        ->suffix('VNĐ')
                        ->visible(fn (Get $get) => $get('calc_type') === 'piece_rate'),
                    TextInput::make('consultation_rate')
                        ->label('Đơn giá / lượt tư vấn khách hàng')
                        ->numeric()
                        ->minValue(0)
                        ->suffix('VNĐ')
                        ->visible(fn (Get $get) => $get('calc_type') === 'piece_rate'),
                ])
                ->action(function (array $data): void {
                    SalaryType::create([
                        'name'               => $data['name'],
                        'slug'               => $data['slug'],
                        'calc_type'          => $data['calc_type'],
                        'room_cleaning_rate' => $data['room_cleaning_rate'] ?? null,
                        'consultation_rate'  => $data['consultation_rate'] ?? null,
                        'partner_id'         => self::resolvePartnerIdFromData($data),
                    ]);

                    self::notifyCreated('Đã tạo loại lương mới.');
                }),

            HeaderAction::make('createSalaryTemplate')
                ->label('+ Mẫu lương')
                ->color('gray')
                ->modalHeading('Thêm mẫu lương')
                ->form([
                    ...self::partnerField(),
                    TextInput::make('name')
                        ->label('Tên mẫu lương')
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, callable $set) => $set('slug', Str::slug($get('name')))),
                    TextInput::make('slug')->label('Slug')->required(),
                    Select::make('salary_type_id')
                        ->label('Loại lương')
                        ->options(fn (Get $get) => SalaryType::where('partner_id', self::resolvePartnerIdFromForm($get))->pluck('name', 'id'))
                        ->searchable(),
                    TextInput::make('base_amount')
                        ->label('Lương cơ bản')
                        ->numeric()
                        ->minValue(0)
                        ->suffix('VNĐ'),
                ])
                ->action(function (array $data): void {
                    SalaryTemplate::create([
                        'name'          => $data['name'],
                        'slug'          => $data['slug'],
                        'salary_type_id' => $data['salary_type_id'] ?? null,
                        'base_amount'   => $data['base_amount'] ?? null,
                        'partner_id'    => self::resolvePartnerIdFromData($data),
                    ]);

                    self::notifyCreated('Đã tạo mẫu lương mới.');
                }),

            HeaderAction::make('createAllowanceType')
                ->label('+ Loại phụ cấp')
                ->color('gray')
                ->modalHeading('Thêm loại phụ cấp')
                ->form(self::allowanceOrDeductionFields('phụ cấp'))
                ->action(function (array $data): void {
                    AllowanceType::create([
                        'name'           => $data['name'],
                        'slug'           => $data['slug'],
                        'calc_type'      => $data['calc_type'],
                        'default_amount' => $data['default_amount'] ?? null,
                        'partner_id'     => self::resolvePartnerIdFromData($data),
                    ]);

                    self::notifyCreated('Đã tạo loại phụ cấp mới.');
                }),

            HeaderAction::make('createDeductionType')
                ->label('+ Loại giảm trừ')
                ->color('gray')
                ->modalHeading('Thêm loại giảm trừ')
                ->form(self::allowanceOrDeductionFields('giảm trừ'))
                ->action(function (array $data): void {
                    DeductionType::create([
                        'name'           => $data['name'],
                        'slug'           => $data['slug'],
                        'calc_type'      => $data['calc_type'],
                        'default_amount' => $data['default_amount'] ?? null,
                        'partner_id'     => self::resolvePartnerIdFromData($data),
                    ]);

                    self::notifyCreated('Đã tạo loại giảm trừ mới.');
                }),
        ];
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    private static function allowanceOrDeductionFields(string $label): array
    {
        return [
            ...self::partnerField(),
            TextInput::make('name')
                ->label("Tên loại {$label}")
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, callable $set) => $set('slug', Str::slug($get('name')))),
            TextInput::make('slug')->label('Slug')->required(),
            Select::make('calc_type')
                ->label('Cách tính')
                ->options(['fixed' => 'Số tiền cố định', 'percentage' => 'Theo % lương'])
                ->default('fixed')
                ->required(),
            TextInput::make('default_amount')
                ->label('Số tiền mặc định')
                ->numeric()
                ->minValue(0)
                ->suffix('VNĐ'),
        ];
    }

    // super_admin phải tự chọn đối tác sở hữu danh mục lương; chủ đối tác thì ẩn field này đi
    // (tự gán đúng partner của mình khi submit, xem resolvePartnerIdFromData()).
    private static function partnerField(): array
    {
        if (! auth()->user()?->isSuperAdmin()) {
            return [];
        }

        return [
            Select::make('partner_id')
                ->label('Đối tác sở hữu')
                ->options(fn () => Partner::pluck('name', 'id'))
                ->searchable()
                ->required(),
        ];
    }

    private static function resolvePartnerIdFromData(array $data): ?string
    {
        $user = auth()->user();

        if ($user && ! $user->isSuperAdmin()) {
            return $user->partner_id;
        }

        return $data['partner_id'] ?? null;
    }

    private static function resolvePartnerIdFromForm(Get $get): ?string
    {
        $user = auth()->user();

        if ($user && ! $user->isSuperAdmin()) {
            return $user->partner_id;
        }

        return $get('partner_id');
    }

    private static function notifyCreated(string $message): void
    {
        \Filament\Notifications\Notification::make()
            ->title($message)
            ->success()
            ->send();
    }

    // Tính TRÊN ĐÚNG tập bản ghi đang hiển thị (đã áp dụng bộ lọc "Đối tác"/tìm kiếm hiện tại)
    // — không phải tổng toàn bộ nhân viên mọi đối tác. Gọi trực tiếp trong Blade nên luôn tính
    // lại mới mỗi khi bộ lọc đổi (không cache), 'final_salary' là accessor PHP nên không thể
    // dùng summarizer SQL có sẵn của Filament (xem ghi chú cũ đã gỡ bên dưới).
    public function getTotalPayroll(): string
    {
        $total = $this->getFilteredTableQuery()
            ->get()
            ->sum(fn (Employee $employee) => $employee->final_salary);

        return number_format((float) $total, 0, ',', '.') . 'đ';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Employee::query()->with(['department', 'position', 'salaryType', 'allowanceTypes', 'deductionTypes'])
            )
            ->columns([
                TextColumn::make('employee_code')
                    ->label('Mã NV')
                    ->searchable(),

                TextColumn::make('name')
                    ->label('Nhân viên')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('department.name')
                    ->label('Phòng ban')
                    ->placeholder('—'),

                TextColumn::make('position.name')
                    ->label('Chức danh')
                    ->placeholder('—'),

                TextColumn::make('computed_base_amount')
                    ->label('Lương cơ bản')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.') . 'đ')
                    ->alignEnd(),

                TextColumn::make('total_allowances')
                    ->label('Tổng phụ cấp')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.') . 'đ')
                    ->color('success')
                    ->alignEnd(),

                TextColumn::make('total_deductions')
                    ->label('Tổng giảm trừ')
                    ->formatStateUsing(fn ($state) => '-' . number_format((float) $state, 0, ',', '.') . 'đ')
                    ->color('danger')
                    ->alignEnd(),

                TextColumn::make('final_salary')
                    ->label('Thực nhận')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', '.') . 'đ')
                    ->weight('bold')
                    ->alignEnd(),

                PartnerTableHelpers::column(),
            ])
            ->filters([
                PartnerTableHelpers::filter(),
            ])
            ->actions([
                Action::make('viewBreakdown')
                    ->label('Xem chi tiết')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (Employee $record) => "Chi tiết lương — {$record->name}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
                    ->modalContent(fn (Employee $record) => new HtmlString(self::renderBreakdown($record))),
            ])
            ->defaultSort('name');
    }

    private static function renderBreakdown(Employee $employee): string
    {
        $fmt = fn ($n) => number_format((float) $n, 0, ',', '.') . 'đ';

        $baseSection = $employee->is_piece_rate
            ? self::renderPieceRateSection($employee, $fmt)
            : self::renderFixedBaseSection($employee, $fmt);

        $allowanceRows = $employee->allowanceTypes->map(fn ($type) => <<<HTML
            <tr>
                <td style="padding:6px 8px;">{$type->name}</td>
                <td style="padding:6px 8px;text-align:right;color:#10b981;">+{$fmt($type->pivot->amount)}</td>
            </tr>
        HTML)->implode('');

        $deductionRows = $employee->deductionTypes->map(fn ($type) => <<<HTML
            <tr>
                <td style="padding:6px 8px;">{$type->name}</td>
                <td style="padding:6px 8px;text-align:right;color:#ef4444;">-{$fmt($type->pivot->amount)}</td>
            </tr>
        HTML)->implode('');

        $allowanceCount = $employee->allowanceTypes->count();
        $deductionCount = $employee->deductionTypes->count();
        $totalAllowances = $fmt($employee->total_allowances);
        $totalDeductions = $fmt($employee->total_deductions);
        $finalSalary = $fmt($employee->final_salary);

        $allowanceSection = $allowanceRows !== ''
            ? $allowanceRows
            : '<tr><td colspan="2" style="padding:6px 8px;color:#9ca3af;">Không có phụ cấp</td></tr>';

        $deductionSection = $deductionRows !== ''
            ? $deductionRows
            : '<tr><td colspan="2" style="padding:6px 8px;color:#9ca3af;">Không có giảm trừ</td></tr>';

        // Phụ cấp/giảm trừ cũng bọc trong <details> (thu gọn sẵn) — hiện luôn tổng SỐ KHOẢN +
        // TỔNG TIỀN để đối chiếu nhanh, danh sách nhiều khoản chỉ mở ra khi cần xem chi tiết.
        return <<<HTML
            <div style="font-size:0.9rem;">
                {$baseSection}

                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;">
                    <span style="font-weight:600;color:#374151;font-size:0.85rem;">Phụ cấp</span>
                    <span style="font-weight:700;color:#111827;font-size:0.85rem;">Tổng: {$allowanceCount} khoản</span>
                </div>
                <details>
                    <summary style="cursor:pointer;color:#6b7280;font-size:0.8rem;padding:2px 0;">Xem chi tiết từng khoản</summary>
                    <table style="width:100%;border-collapse:collapse;margin-top:4px;">{$allowanceSection}</table>
                </details>
                <div style="text-align:right;padding:4px 8px;font-weight:600;color:#10b981;border-top:1px dashed #e5e7eb;">Tổng phụ cấp: +{$totalAllowances}</div>

                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;">
                    <span style="font-weight:600;color:#374151;font-size:0.85rem;">Giảm trừ</span>
                    <span style="font-weight:700;color:#111827;font-size:0.85rem;">Tổng: {$deductionCount} khoản</span>
                </div>
                <details>
                    <summary style="cursor:pointer;color:#6b7280;font-size:0.8rem;padding:2px 0;">Xem chi tiết từng khoản</summary>
                    <table style="width:100%;border-collapse:collapse;margin-top:4px;">{$deductionSection}</table>
                </details>
                <div style="text-align:right;padding:4px 8px;font-weight:600;color:#ef4444;border-top:1px dashed #e5e7eb;">Tổng giảm trừ: -{$totalDeductions}</div>

                <div style="margin-top:16px;padding-top:12px;border-top:2px solid #111827;display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-weight:700;font-size:1rem;">Thực nhận</span>
                    <span style="font-weight:800;font-size:1.2rem;color:#111827;">{$finalSalary}</span>
                </div>
            </div>
        HTML;
    }

    private static function renderFixedBaseSection(Employee $employee, callable $fmt): string
    {
        $baseAmount = $fmt($employee->base_amount);

        return <<<HTML
            <table style="width:100%;border-collapse:collapse;">
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:6px 8px;font-weight:600;">Lương cơ bản</td>
                    <td style="padding:6px 8px;text-align:right;font-weight:600;">{$baseAmount}</td>
                </tr>
            </table>
        HTML;
    }

    // Vị trí tính theo lượt (vd Lễ tân) — liệt kê TỪNG lượt dọn phòng (kèm tên phòng + giờ xác
    // nhận, lấy từ room_cleaning_logs đã tự động ghi khi xác nhận dọn xong) và TỪNG lượt tư vấn
    // khách hàng (kèm tên khách + giờ, do nhân viên tự ghi ở ConsultationLogResource) trong THÁNG
    // HIỆN TẠI, để đối tác thấy rõ vì sao ra đúng số tiền đó — không chỉ hiện mỗi tổng cộng.
    private static function renderPieceRateSection(Employee $employee, callable $fmt): string
    {
        $cleaningRate = (float) ($employee->salaryType->room_cleaning_rate ?? 0);
        $consultationRate = (float) ($employee->salaryType->consultation_rate ?? 0);

        $cleaningRows = $employee->monthly_cleaning_logs->map(function ($log) use ($fmt, $cleaningRate) {
            $roomName = $log->product?->name ?? 'Phòng đã xóa';
            $time = $log->cleaned_at->format('d/m H:i');

            return <<<HTML
                <tr>
                    <td style="padding:6px 8px;">"{$roomName}" — {$time}</td>
                    <td style="padding:6px 8px;text-align:right;color:#10b981;">+{$fmt($cleaningRate)}</td>
                </tr>
            HTML;
        })->implode('');

        $consultationRows = $employee->monthly_consultation_logs->map(function ($log) use ($fmt, $consultationRate) {
            $time = $log->consulted_at->format('d/m H:i');

            return <<<HTML
                <tr>
                    <td style="padding:6px 8px;">"{$log->customer_name}" — {$time}</td>
                    <td style="padding:6px 8px;text-align:right;color:#10b981;">+{$fmt($consultationRate)}</td>
                </tr>
            HTML;
        })->implode('');

        $cleaningRows = $cleaningRows !== ''
            ? $cleaningRows
            : '<tr><td colspan="2" style="padding:6px 8px;color:#9ca3af;">Chưa có lượt dọn phòng nào trong tháng</td></tr>';

        $consultationRows = $consultationRows !== ''
            ? $consultationRows
            : '<tr><td colspan="2" style="padding:6px 8px;color:#9ca3af;">Chưa có lượt tư vấn nào trong tháng</td></tr>';

        $cleaningCount = $employee->monthly_cleaning_logs->count();
        $consultationCount = $employee->monthly_consultation_logs->count();
        $pieceTotal = $fmt($employee->piece_rate_amount);
        $cleaningRateLabel = $fmt($cleaningRate);
        $consultationRateLabel = $fmt($consultationRate);

        // Bọc danh sách từng lượt trong <details> (thu gọn sẵn) — nhân viên dọn nhiều/tư vấn
        // nhiều trong tháng thì danh sách rất dài, chỉ cần thấy TỔNG SỐ LƯỢT ngay để đối chiếu,
        // bấm mở ra mới cần xem chi tiết từng dòng.
        return <<<HTML
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                <span style="font-weight:600;color:#374151;font-size:0.85rem;">Dọn phòng — đơn giá {$cleaningRateLabel}/lượt</span>
                <span style="font-weight:700;color:#111827;font-size:0.85rem;">Tổng: {$cleaningCount} lượt</span>
            </div>
            <details>
                <summary style="cursor:pointer;color:#6b7280;font-size:0.8rem;padding:2px 0;">Xem chi tiết từng lượt</summary>
                <table style="width:100%;border-collapse:collapse;margin-top:4px;">{$cleaningRows}</table>
            </details>

            <div style="display:flex;justify-content:space-between;align-items:center;margin:12px 0 4px;">
                <span style="font-weight:600;color:#374151;font-size:0.85rem;">Tư vấn khách hàng — đơn giá {$consultationRateLabel}/lượt</span>
                <span style="font-weight:700;color:#111827;font-size:0.85rem;">Tổng: {$consultationCount} lượt</span>
            </div>
            <details>
                <summary style="cursor:pointer;color:#6b7280;font-size:0.8rem;padding:2px 0;">Xem chi tiết từng lượt</summary>
                <table style="width:100%;border-collapse:collapse;margin-top:4px;">{$consultationRows}</table>
            </details>

            <div style="text-align:right;padding:6px 8px;font-weight:700;border-top:1px solid #e5e7eb;margin-top:6px;">Lương theo lượt tháng này: {$pieceTotal}</div>
        HTML;
    }
}
