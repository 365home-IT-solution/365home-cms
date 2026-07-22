<?php

declare(strict_types=1);

namespace Modules\User\App\Filament\Resources\UserResource\Forms;

use App\Models\Partner;
use App\Models\Province;
use App\Models\User;
use App\Models\Ward;
use Filament\Forms\Form;
use Illuminate\Support\Facades\Hash;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Employee\Entities\AllowanceType;
use Modules\Employee\Entities\DeductionType;
use Modules\Employee\Entities\Department;
use Modules\Employee\Entities\Position;
use Modules\Employee\Entities\SalaryTemplate;
use Modules\Employee\Entities\SalaryType;

class UserForm
{
    private const COLUMN_SPAN = [
        'sm' => 1,
        'lg' => 2
    ];

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                self::createAccountTypeSelector(),
                self::createLeftColumn(),
                self::createMainTabs()
            ])
            ->columns(3);
    }

    // Trang này dùng chung cho 2 việc: (1) tạo NHÂN VIÊN — tạo đồng thời 1 User (đăng nhập)
    // + 1 Employee (hồ sơ, lương, chi nhánh...) liên kết; (2) tạo CHỦ ĐỐI TÁC — chỉ tạo 1 User
    // (không có hồ sơ Employee). Chỉ super_admin được tạo "Chủ đối tác" — đối tác/nhân viên
    // đăng nhập chỉ được phép tạo NHÂN VIÊN cho chính mình (không được tạo đối tác khác), nên
    // với họ field này ẩn và luôn cố định 'employee'. Không cho đổi loại sau khi đã tạo (tránh
    // dữ liệu Employee mồ côi hoặc thiếu hồ sơ giữa chừng).
    private static function createAccountTypeSelector(): Forms\Components\Component
    {
        if (! (auth()->user()?->isSuperAdmin() ?? false)) {
            return Forms\Components\Hidden::make('account_type')->default('employee');
        }

        return Forms\Components\Radio::make('account_type')
            ->label('Loại tài khoản')
            ->options([
                'employee' => 'Nhân viên',
                'partner'  => 'Chủ đối tác',
            ])
            ->default('employee')
            ->inline()
            ->inlineLabel(false)
            ->live()
            ->disabled(fn (string $operation): bool => $operation === 'edit')
            ->helperText(fn (string $operation): string => $operation === 'edit'
                ? 'Không thể đổi loại tài khoản sau khi đã tạo.'
                : 'Nhân viên: tạo kèm hồ sơ nhân viên (lương, chi nhánh...). Chủ đối tác: chỉ tạo tài khoản đăng nhập.')
            ->columnSpanFull();
    }

    private static function createLeftColumn(): Forms\Components\Group
    {
        return Forms\Components\Group::make()
            ->schema([
                self::createAvatarUpload(),
                self::createPartnerSection(),
                self::createPasswordSection(),
                self::createTimestampsSection(),
            ])
            ->columnSpan(1);
    }

    private static function createAvatarUpload(): SpatieMediaLibraryFileUpload
    {
        return SpatieMediaLibraryFileUpload::make('media')
            ->hiddenLabel()
            ->avatar()
            ->collection('avatars')
            ->alignCenter()
            ->columnSpanFull();
    }

    // Chỉ super_admin thấy — chọn tài khoản này (nhân viên) thuộc đối tác nào. Với tài khoản
    // đối tác/nhân viên đang đăng nhập, partner_id tự động lấy theo tài khoản hiện tại (xem
    // CreateUser/EditUser), không cần tự chọn.
    private static function createPartnerSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make()
            ->schema([
                Select::make('partner_id')
                    ->label('Đối tác sở hữu')
                    // Chỉ cho gán tài khoản mới vào đối tác ĐÃ ĐƯỢC XÁC NHẬN (verification_status
                    // = 'approved') — đối tác đang chờ duyệt/bị từ chối chưa đủ điều kiện vận hành
                    // thật, tạo tài khoản/chi nhánh cho họ lúc này là vô nghĩa (và tài khoản đó
                    // cũng sẽ bị chặn đăng nhập ngay, xem User::canAccessPanel()).
                    ->relationship('partner', 'name', modifyQueryUsing: fn (Builder $query) => $query->where('verification_status', 'approved'))
                    ->searchable()
                    ->preload()
                    ->live()
                    ->required()
                    // Đổi đối tác thì vai trò đã chọn (thuộc đối tác cũ) không còn hợp lệ nữa —
                    // xoá để buộc chọn lại đúng vai trò của đối tác mới (xem createRolesTab()).
                    ->afterStateUpdated(fn (callable $set) => $set('roles', []))
                    ->helperText('Tài khoản sẽ thuộc về đối tác này. Bấm "+" để tạo đối tác mới ngay tại đây.')
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')
                            ->label('Tên đối tác')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('tax_code')
                            ->label('Mã số thuế')
                            ->maxLength(50),
                        Forms\Components\TextInput::make('phone')
                            ->label('Số điện thoại')
                            ->tel()
                            ->maxLength(20),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('address')
                            ->label('Địa chỉ')
                            ->maxLength(255),
                    ])
                    ->createOptionUsing(fn (array $data) => Partner::create([
                        ...$data,
                        'status'     => true,
                        'created_by' => auth()->id(),
                    ])->id),
            ])
            ->compact()
            ->visible(fn (): bool => auth()->user()?->isSuperAdmin() ?? false);
    }

    private static function createPasswordSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make()
            ->schema([
                self::createPasswordField(),
                self::createPasswordConfirmationField(),
            ])
            ->compact()
            ->hidden(fn(string $operation): bool => $operation === 'edit');
    }

    private static function createPasswordField(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('password')
            ->label(__('user::user.form.label.password'))
            ->placeholder(__('user::user.form.placeholder.password'))
            ->password()
            ->dehydrateStateUsing(fn(string $state): string => Hash::make($state))
            ->dehydrated(fn(?string $state): bool => filled($state))
            ->revealable()
            ->required()
            ->rules(['min:8']);
    }

    private static function createPasswordConfirmationField(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('passwordConfirmation')
            ->label(__('user::user.form.label.password_confirmation'))
            ->placeholder(__('user::user.form.placeholder.password_confirmation'))
            ->password()
            ->dehydrateStateUsing(fn(string $state): string => Hash::make($state))
            ->dehydrated(fn(?string $state): bool => filled($state))
            ->revealable()
            ->same('password')
            ->required();
    }

    private static function createTimestampsSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make()
            ->schema([
                self::createTimestampPlaceholder('created_at', 'created_at', fn(User $record) => $record->created_at?->diffForHumans()),
                self::createTimestampPlaceholder('updated_at', 'updated_at', fn(User $record) => $record->updated_at?->diffForHumans()),
            ])
            ->compact()
            ->hidden(fn(string $operation): bool => $operation === 'create');
    }

    private static function createTimestampPlaceholder(string $name, string $label, callable $contentCallback): Forms\Components\Placeholder
    {
        return Forms\Components\Placeholder::make($name)
            ->label(__("resource.general.$label"))
            ->content($contentCallback);
    }

    private static function createMainTabs(): Forms\Components\Tabs
    {
        return Forms\Components\Tabs::make()
            ->schema([
                self::createInfoTab(),
                self::createSalaryTab(),
                self::createRolesTab(),
            ])
            ->columnSpan(self::COLUMN_SPAN);
    }

    // ── TAB 1: Thông tin ─────────────────────────────────────────────────────
    // Gộp field đăng nhập (email/fullname, gốc UserForm) với đầy đủ field hồ sơ nhân viên
    // (bảng employees) — trang này giờ tạo/cập nhật ĐỒNG THỜI 1 User (đăng nhập) + 1 Employee
    // (hồ sơ) trong cùng 1 lần lưu, xem CreateUser/EditUser.
    private static function createInfoTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Thông tin')
            ->icon('heroicon-o-information-circle')
            ->schema([
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            self::createUniqueField('email')->email(),

                            Forms\Components\TextInput::make('fullname')
                                ->label(__('user::user.form.label.fullname'))
                                ->placeholder(__('user::user.form.placeholder.fullname'))
                                ->required()
                                ->maxLength(255),
                        ]),
                    ]),

                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('employee_code')
                                ->label('Mã nhân viên')
                                ->placeholder('Mã tự động')
                                ->disabled()
                                ->dehydrated(false),

                            Forms\Components\TextInput::make('phone')
                                ->label('Số điện thoại')
                                ->tel()
                                ->maxLength(20),

                            Forms\Components\Select::make('pay_branch_id')
                                ->label('Chi nhánh trả lương')
                                ->options(fn (Get $get) => self::branchOptions($get))
                                ->searchable()
                                ->live(),

                            Forms\Components\Toggle::make('status')
                                ->label('Đang làm việc')
                                ->default(true),
                        ]),

                        Forms\Components\Select::make('work_branch_ids')
                            ->label('Chi nhánh làm việc')
                            ->options(fn (Get $get) => self::branchOptions($get))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->disabled(fn (Get $get) => (bool) $get('works_all_branches'))
                            ->helperText('Bật "Làm việc tất cả chi nhánh" bên dưới nếu không giới hạn theo chi nhánh cụ thể.')
                            ->columnSpanFull(),

                        Forms\Components\Toggle::make('works_all_branches')
                            ->label('Làm việc tất cả chi nhánh')
                            ->live()
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (Get $get): bool => $get('account_type') !== 'partner'),

                Forms\Components\Section::make('Thông tin công việc')
                    ->collapsible()
                    ->visible(fn (Get $get): bool => $get('account_type') !== 'partner')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\DatePicker::make('hire_date')
                                ->label('Ngày bắt đầu làm việc')
                                ->native(false)
                                ->displayFormat('d/m/Y'),

                            Forms\Components\Select::make('department_id')
                                ->label('Phòng ban')
                                ->options(fn () => Department::pluck('name', 'id'))
                                ->searchable()
                                ->preload()
                                ->createOptionForm([
                                    Forms\Components\TextInput::make('name')
                                        ->label('Tên phòng ban')
                                        ->required()
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(fn (Get $get, callable $set) => $set('slug', Str::slug($get('name')))),
                                    Forms\Components\TextInput::make('slug')->label('Slug')->required(),
                                ])
                                ->createOptionUsing(fn (array $data) => Department::create($data)->id),

                            Forms\Components\Select::make('position_id')
                                ->label('Chức danh')
                                ->options(fn () => Position::pluck('name', 'id'))
                                ->searchable()
                                ->preload()
                                ->createOptionForm([
                                    Forms\Components\TextInput::make('name')
                                        ->label('Tên chức danh')
                                        ->required()
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(fn (Get $get, callable $set) => $set('slug', Str::slug($get('name')))),
                                    Forms\Components\TextInput::make('slug')->label('Slug')->required(),
                                ])
                                ->createOptionUsing(fn (array $data) => Position::create($data)->id),
                        ]),

                        Forms\Components\Textarea::make('note')
                            ->label('Ghi chú')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Thông tin cá nhân')
                    ->collapsible()
                    ->visible(fn (Get $get): bool => $get('account_type') !== 'partner')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('id_number')
                                ->label('Số CMND/CCCD')
                                ->maxLength(20),

                            Forms\Components\DatePicker::make('date_of_birth')
                                ->label('Ngày sinh')
                                ->native(false)
                                ->displayFormat('d/m/Y'),
                        ]),

                        Forms\Components\Radio::make('gender')
                            ->label('Giới tính')
                            ->options(['male' => 'Nam', 'female' => 'Nữ'])
                            ->inline()
                            ->inlineLabel(false),
                    ]),

                Forms\Components\Section::make('Thông tin liên hệ')
                    ->collapsible()
                    ->visible(fn (Get $get): bool => $get('account_type') !== 'partner')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('address')
                                ->label('Địa chỉ')
                                ->maxLength(255),

                            Forms\Components\Select::make('province_id')
                                ->label('Tỉnh/Thành')
                                ->options(fn () => Province::orderBy('name')->pluck('name', 'id'))
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(fn (callable $set) => $set('ward_code', null)),

                            Forms\Components\Select::make('ward_code')
                                ->label('Phường/Xã')
                                ->options(function (Get $get) {
                                    $province = Province::find($get('province_id'));
                                    if (! $province) {
                                        return [];
                                    }

                                    return Ward::where('province_code', $province->code)
                                        ->orderBy('name')
                                        ->pluck('name', 'code');
                                })
                                ->searchable()
                                ->disabled(fn (Get $get) => blank($get('province_id')))
                                ->helperText(fn (Get $get) => blank($get('province_id')) ? 'Chọn Tỉnh/Thành trước.' : null),

                            Forms\Components\TextInput::make('facebook_url')
                                ->label('Facebook')
                                ->url()
                                ->maxLength(255),
                        ]),
                    ]),
            ])
            ->columns(1);
    }

    // Nạp lại field hồ sơ nhân viên (bảng employees) liên kết với $record vào $data — dùng chung
    // cho cả trang Sửa (EditUser::mutateFormDataBeforeFill) lẫn popup Xem chi tiết (ViewAction
    // ::mutateRecordDataUsing), vì cả 2 đều fill form này nhưng KHÔNG tự động lấy field của
    // Employee (record gốc là User, employee_code/phone/lương/chi nhánh... nằm ở bảng khác).
    public static function fillEmployeeData(User $record, array $data): array
    {
        $employee = $record->employee;
        $data['account_type'] = $employee ? 'employee' : 'partner';

        if (! $employee) {
            return $data;
        }

        $data = array_merge($data, collect($employee->toArray())->only([
            'employee_code', 'phone', 'pay_branch_id', 'works_all_branches', 'hire_date',
            'department_id', 'position_id', 'note', 'id_number', 'date_of_birth', 'gender',
            'address', 'province_id', 'ward_code', 'facebook_url', 'salary_type_id',
            'salary_template_id', 'base_amount', 'has_allowances', 'has_deductions', 'status',
        ])->toArray());

        $data['work_branch_ids'] = $employee->workBranches->pluck('id')->toArray();
        $data['allowances']      = $employee->allowanceTypes->map(fn ($type) => [
            'id'     => $type->id,
            'amount' => $type->pivot->amount,
        ])->toArray();
        $data['deductions']      = $employee->deductionTypes->map(fn ($type) => [
            'id'     => $type->id,
            'amount' => $type->pivot->amount,
        ])->toArray();

        return $data;
    }

    private static function createUniqueField(string $fieldName): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make($fieldName)
            ->label(__('user::user.form.label.' . $fieldName))
            ->placeholder(__('user::user.form.placeholder.' . $fieldName))
            ->required()
            ->maxLength(255)
            ->live()
            ->rules(function ($record) use ($fieldName) {
                $userId = $record?->id;
                return $userId
                    ? ["unique:users,$fieldName," . $userId]
                    : ["unique:users,$fieldName"];
            });
    }

    // ── TAB 2: Thiết lập lương ────────────────────────────────────────────────
    // 'allowances'/'deductions' KHÔNG dùng ->relationship() (Employee không phải model gốc của
    // form này — model gốc vẫn là User) — đồng bộ thủ công ở CreateUser/EditUser.
    private static function createSalaryTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Thiết lập lương')
            ->icon('heroicon-o-banknotes')
            ->visible(fn (Get $get): bool => $get('account_type') !== 'partner')
            ->schema([
                Forms\Components\Section::make('Lương chính')
                    ->schema([
                        Forms\Components\Select::make('salary_type_id')
                            ->label('Loại lương')
                            ->options(fn (Get $get) => SalaryType::where('partner_id', self::resolvePartnerId($get))->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            // live() để form ẩn/hiện đúng "Mẫu lương"/"Lương cơ bản" ngay khi đổi
                            // loại lương — vị trí tính theo lượt (piece_rate, vd Lễ tân) không cần
                            // 2 field đó nữa (xem isPieceRateSalaryType()).
                            ->live()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label('Tên loại lương')
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, callable $set) => $set('slug', Str::slug($get('name')))),
                                Forms\Components\TextInput::make('slug')->label('Slug')->required(),
                                Forms\Components\Select::make('calc_type')
                                    ->label('Cách tính lương')
                                    ->options([
                                        'fixed'      => 'Lương cố định',
                                        'piece_rate' => 'Theo lượt công việc (vd Lễ tân)',
                                    ])
                                    ->default('fixed')
                                    ->live()
                                    ->required(),
                                Forms\Components\TextInput::make('room_cleaning_rate')
                                    ->label('Đơn giá / lượt dọn phòng')
                                    ->numeric()
                                    ->minValue(0)
                                    ->suffix('VNĐ')
                                    ->visible(fn (Get $get) => $get('calc_type') === 'piece_rate'),
                                Forms\Components\TextInput::make('consultation_rate')
                                    ->label('Đơn giá / lượt tư vấn khách hàng')
                                    ->numeric()
                                    ->minValue(0)
                                    ->suffix('VNĐ')
                                    ->visible(fn (Get $get) => $get('calc_type') === 'piece_rate'),
                            ])
                            ->createOptionUsing(fn (array $data, Get $get) => SalaryType::create([
                                ...$data,
                                'partner_id' => self::resolvePartnerId($get),
                            ])->id),

                        Forms\Components\Select::make('salary_template_id')
                            ->label('Mẫu lương')
                            ->hintIcon('heroicon-o-information-circle')
                            ->hintIconTooltip('Chọn mẫu lương có sẵn để tự điền lương cơ bản — vẫn có thể sửa lại tay.')
                            ->options(fn (Get $get) => SalaryTemplate::where('partner_id', self::resolvePartnerId($get))->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->visible(fn (Get $get) => ! self::isPieceRateSalaryType($get))
                            ->afterStateUpdated(function ($state, callable $set) {
                                $template = $state ? SalaryTemplate::find($state) : null;
                                if ($template) {
                                    $set('base_amount', $template->base_amount);
                                }
                            })
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label('Tên mẫu lương')
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Get $get, callable $set) => $set('slug', Str::slug($get('name')))),
                                Forms\Components\TextInput::make('slug')->label('Slug')->required(),
                                Forms\Components\Select::make('salary_type_id')
                                    ->label('Loại lương')
                                    ->options(fn (Get $get) => SalaryType::where('partner_id', self::resolvePartnerId($get))->pluck('name', 'id'))
                                    ->searchable(),
                                Forms\Components\TextInput::make('base_amount')
                                    ->label('Lương cơ bản')
                                    ->numeric()
                                    ->minValue(0)
                                    ->suffix('VNĐ'),
                            ])
                            ->createOptionUsing(fn (array $data, Get $get) => SalaryTemplate::create([
                                ...$data,
                                'partner_id' => self::resolvePartnerId($get),
                            ])->id),

                        Forms\Components\TextInput::make('base_amount')
                            ->label('Lương cơ bản')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('VNĐ')
                            ->visible(fn (Get $get) => ! self::isPieceRateSalaryType($get)),

                        Forms\Components\Placeholder::make('piece_rate_hint')
                            ->label('Lương cơ bản')
                            ->content('Vị trí này tính lương theo lượt công việc (dọn phòng/tư vấn khách hàng) — không cần nhập lương cố định. Đơn giá khai báo ở "Loại lương", xem chi tiết ở trang "Thống kê lương".')
                            ->visible(fn (Get $get) => self::isPieceRateSalaryType($get)),
                    ]),

                Forms\Components\Section::make('Phụ cấp')
                    ->description('Thiết lập khoản hỗ trợ làm việc như ăn trưa, đi lại, điện thoại, ...')
                    ->schema([
                        Forms\Components\Toggle::make('has_allowances')
                            ->label('Bật phụ cấp')
                            ->live(),

                        Forms\Components\Repeater::make('allowances')
                            ->label('')
                            ->schema([
                                Forms\Components\Select::make('id')
                                    ->label('Loại phụ cấp')
                                    ->options(fn (Get $get) => AllowanceType::where('partner_id', self::resolvePartnerId($get))->pluck('name', 'id'))
                                    ->required()
                                    ->distinct()
                                    ->searchable()
                                    ->live()
                                    // Loại phụ cấp đã có sẵn "Số tiền mặc định" từ trước — tự điền
                                    // luôn vào ô "Số tiền" bên cạnh, không bắt nhập tay lại. Nếu
                                    // loại đó tính "Theo % lương" thì phải quy đổi ra số tiền
                                    // thật (% × lương cơ bản) — không hiện thẳng con số % (vd "5").
                                    ->afterStateUpdated(function ($state, callable $set, Get $get) {
                                        $type = $state ? AllowanceType::find($state) : null;

                                        if (! $type || blank($type->default_amount)) {
                                            return;
                                        }

                                        if ($type->calc_type === 'percentage') {
                                            $baseAmount = (float) ($get('data.base_amount', isAbsolute: true) ?? 0);
                                            $set('amount', round($baseAmount * (float) $type->default_amount / 100));
                                        } else {
                                            $set('amount', $type->default_amount);
                                        }
                                    })
                                    ->createOptionForm([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Tên loại phụ cấp')
                                            ->required()
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn (Get $get, callable $set) => $set('slug', Str::slug($get('name')))),
                                        Forms\Components\TextInput::make('slug')->label('Slug')->required(),
                                        Forms\Components\Select::make('calc_type')
                                            ->label('Cách tính')
                                            ->options(['fixed' => 'Số tiền cố định', 'percentage' => 'Theo % lương'])
                                            ->default('fixed')
                                            ->required(),
                                        Forms\Components\TextInput::make('default_amount')
                                            ->label('Số tiền mặc định')
                                            ->numeric()
                                            ->minValue(0)
                                            ->suffix('VNĐ'),
                                    ])
                                    ->createOptionUsing(fn (array $data, Get $get) => AllowanceType::create([
                                        ...$data,
                                        'partner_id' => self::resolvePartnerId($get),
                                    ])->id),
                                Forms\Components\TextInput::make('amount')
                                    ->label('Số tiền')
                                    ->numeric()
                                    ->minValue(0)
                                    ->suffix('VNĐ'),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('+ Thêm phụ cấp')
                            ->visible(fn (Get $get) => (bool) $get('has_allowances')),
                    ]),

                Forms\Components\Section::make('Giảm trừ')
                    ->description('Thiết lập khoản giảm trừ như đi muộn, về sớm, vi phạm nội quy, ...')
                    ->schema([
                        Forms\Components\Toggle::make('has_deductions')
                            ->label('Bật giảm trừ')
                            ->live(),

                        Forms\Components\Repeater::make('deductions')
                            ->label('')
                            ->schema([
                                Forms\Components\Select::make('id')
                                    ->label('Loại giảm trừ')
                                    ->options(fn (Get $get) => DeductionType::where('partner_id', self::resolvePartnerId($get))->pluck('name', 'id'))
                                    ->required()
                                    ->distinct()
                                    ->searchable()
                                    ->live()
                                    // Giống phụ cấp: loại "Theo % lương" phải quy đổi ra số tiền
                                    // thật (% × lương cơ bản), không hiện thẳng con số % (vd "5").
                                    ->afterStateUpdated(function ($state, callable $set, Get $get) {
                                        $type = $state ? DeductionType::find($state) : null;

                                        if (! $type || blank($type->default_amount)) {
                                            return;
                                        }

                                        if ($type->calc_type === 'percentage') {
                                            $baseAmount = (float) ($get('data.base_amount', isAbsolute: true) ?? 0);
                                            $set('amount', round($baseAmount * (float) $type->default_amount / 100));
                                        } else {
                                            $set('amount', $type->default_amount);
                                        }
                                    })
                                    ->createOptionForm([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Tên loại giảm trừ')
                                            ->required()
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn (Get $get, callable $set) => $set('slug', Str::slug($get('name')))),
                                        Forms\Components\TextInput::make('slug')->label('Slug')->required(),
                                        Forms\Components\Select::make('calc_type')
                                            ->label('Cách tính')
                                            ->options(['fixed' => 'Số tiền cố định', 'percentage' => 'Theo % lương'])
                                            ->default('fixed')
                                            ->required(),
                                        Forms\Components\TextInput::make('default_amount')
                                            ->label('Số tiền mặc định')
                                            ->numeric()
                                            ->minValue(0)
                                            ->suffix('VNĐ'),
                                    ])
                                    ->createOptionUsing(fn (array $data, Get $get) => DeductionType::create([
                                        ...$data,
                                        'partner_id' => self::resolvePartnerId($get),
                                    ])->id),
                                Forms\Components\TextInput::make('amount')
                                    ->label('Số tiền')
                                    ->numeric()
                                    ->minValue(0)
                                    ->suffix('VNĐ'),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('+ Thêm giảm trừ')
                            ->visible(fn (Get $get) => (bool) $get('has_deductions')),
                    ]),
            ]);
    }

    private static function createRolesTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Phân quyền')
            ->icon('heroicon-o-shield-check')
            ->schema([
                Select::make('roles')
                    ->label(__('user::user.form.label.roles'))
                    ->hiddenLabel()
                    ->relationship('roles', 'name', function ($query, Get $get) {
                        $user = auth()->user();
                        $query->where('name', '!=', config('filament-shield.panel_user.name'));

                        // super_admin: thấy TOÀN BỘ vai trò (vai trò gốc như 'partner'/'employee'
                        // LẪN vai trò riêng do từng đối tác tự tạo) — không giới hạn theo đối tác
                        // đang chọn, vì super_admin cần toàn quyền tạo tài khoản cho bất kỳ ai
                        // (kể cả tạo tài khoản CHỦ đầu tiên của 1 đối tác mới, lúc đó chưa có
                        // "chủ đối tác" nào để lọc theo — trước đây lọc cứng theo created_by của
                        // chủ đối tác khiến dropdown TRỐNG HOÀN TOÀN với đối tác chưa có/không xác
                        // định được chủ, vd "365Home" tạo qua migration backfill, không qua đúng
                        // luồng tạo đối tác nên không có user nào mang role 'partner').
                        if ($user && $user->isSuperAdmin()) {
                            return;
                        }

                        // Đối tác/nhân viên tự tạo tài khoản: CHỈ thấy vai trò do CHÍNH họ tạo ra.
                        $query->where('created_by', $user?->id ?? '__none__');
                    })
                    ->getOptionLabelFromRecordUsing(fn(Model $record) => Str::headline($record->name))
                    ->multiple()
                    ->preload()
                    ->searchable()
                    // Trước đây giới hạn 5 — hợp lý khi mỗi lần chỉ hiện role của 1 đối tác cụ
                    // thể, nhưng giờ super_admin thấy TOÀN BỘ role (16+ role hiện tại, còn tăng
                    // theo số đối tác), giới hạn 5 khiến phần lớn role "biến mất" khỏi danh sách
                    // trừ khi gõ tìm kiếm đúng tên — nâng lên đủ rộng để không cần đoán tên mới
                    // tìm ra được.
                    ->optionsLimit(50)
                    ->columnSpanFull(),
            ]);
    }

    // Chi nhánh (category_type=product) theo đúng đối tác — super_admin cần chọn "Đối tác sở
    // hữu" trước (partner_id ở cột trái) thì mới thấy chi nhánh tương ứng.
    private static function branchOptions(Get $get): array
    {
        $user      = auth()->user();
        $partnerId = ($user && $user->isSuperAdmin()) ? $get('partner_id') : $user?->partner_id;

        if (! $partnerId) {
            return [];
        }

        return Category::where('category_type', 'product')
            ->where('partner_id', $partnerId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    // Loại lương/mẫu lương/phụ cấp/giảm trừ giờ PHỤ THUỘC ĐỐI TÁC (mỗi đối tác có cách tính
    // lương riêng, không dùng chung). Dùng $get(..., isAbsolute: true) thay vì $get('partner_id')
    // thường vì hàm này còn được gọi từ BÊN TRONG Repeater (phụ cấp/giảm trừ) — ở đó $get tương
    // đối chỉ nhìn thấy field cùng 1 dòng lặp, không thấy field 'partner_id' ở gốc form; đường
    // dẫn tuyệt đối 'data.partner_id' luôn trỏ đúng vào field gốc bất kể gọi từ đâu trong form.
    private static function resolvePartnerId(Get $get): ?string
    {
        $user = auth()->user();

        if ($user && ! $user->isSuperAdmin()) {
            return $user->partner_id;
        }

        return $get('data.partner_id', isAbsolute: true);
    }

    // Kiểm tra "Loại lương" đang được chọn có tính theo lượt công việc hay không (vd Lễ tân) —
    // dùng để ẩn "Mẫu lương"/"Lương cơ bản" vì 2 field đó chỉ áp dụng cho lương cố định.
    private static function isPieceRateSalaryType(Get $get): bool
    {
        $salaryTypeId = $get('salary_type_id');

        if (! $salaryTypeId) {
            return false;
        }

        return SalaryType::where('id', $salaryTypeId)->value('calc_type') === 'piece_rate';
    }
}
