<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources\CategoryResource\Forms;

use CodeWithDennis\FilamentSelectTree\SelectTree;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Illuminate\Support\Str;
use Filament\Forms\Set;
use Illuminate\Validation\Rule;

class CategoryForm
{
    // Layout 2 cột: cột 1 (rộng hơn) chứa toàn bộ thông tin cơ bản, cột 2 chỉ chứa hình ảnh — thu
    // gọn lại so với layout 3 phần trước đây (ảnh nằm riêng 1 hàng bên dưới, tốn chiều cao).
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(['default' => 1, 'lg' => 3])
                    ->schema([
                        self::mainInfoSection()->columnSpan(['default' => 1, 'lg' => 2]),
                        self::imageSection()->columnSpan(['default' => 1, 'lg' => 1]),
                    ]),
            ]);
    }

    private static function mainInfoSection(): Section
    {
        return Section::make()
            ->schema([
                self::categoryTypeHidden(),
                self::nameInput(),
                self::slugInput(),
                self::parentCategoryInput(),
                self::partnerInput(),
                self::sortOrderInput(),
                self::statusToggle(),
                self::descriptionInput(),
            ])
            ->columns(2);
    }

    private static function imageSection(): Section
    {
        return Section::make()
            ->schema([
                self::Image(),
            ]);
    }

    private static function nameInput(): TextInput
    {
        return TextInput::make('name')
            ->label(__('category::category.form.label.name'))
            ->placeholder(__('category::category.form.placeholder.name'))
            ->required()
            ->rules(['max:255'])
            ->live(onBlur: true)
            ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) {
                if (($get('slug') ?? '') !== Str::slug($old)) {
                    return;
                }
                $set('slug', Str::slug($state));
            })
            ->columnSpan(2);
    }

    private static function slugInput(): TextInput
    {
        return TextInput::make('slug')
            ->label(__('category::category.form.label.slug'))
            ->placeholder(__('category::category.form.placeholder.slug'))
            ->required()
            ->rules([function (Get $get) {
                $categoryId = $get('id');
                return $categoryId
                    ? Rule::unique('categories', 'slug')->ignore($categoryId)
                    : Rule::unique('categories', 'slug');
            }])
            ->columnSpan(2);
    }

    private static function descriptionInput(): Textarea
    {
        return Textarea::make('description')
            ->label(__('category::category.form.label.description'))
            ->placeholder(__('category::category.form.placeholder.description'))
            ->rows(3)
            ->columnSpan(2);
    }

    // Resource này chỉ quản lý chi nhánh/khu vực — không còn cho chọn "Kiểu hiển thị" (danh mục
    // bài viết đã tách sang PostCategoryResource riêng), luôn cố định category_type = product.
    // Giữ dạng Hidden (thay vì bỏ hẳn) để $get('category_type') trong partnerInput() và
    // parentCategoryInput() bên dưới vẫn hoạt động đúng như cũ.
    private static function categoryTypeHidden(): Hidden
    {
        return Hidden::make('category_type')->default('product')->dehydrated();
    }

    // Thứ tự hiển thị giữa các chi nhánh/khu vực CÙNG CẤP (cùng parent_id) — số nhỏ hơn hiển thị
    // trước. Ảnh hưởng danh sách chi nhánh ở trang tìm kiếm (?view=branches), API /v1/branches,
    // /v1/search/branches và khối "Chi nhánh tại khu vực" trên trang chủ.
    private static function sortOrderInput(): TextInput
    {
        return TextInput::make('sort_order')
            ->label(__('category::category.form.label.sort_order'))
            ->placeholder(__('category::category.form.placeholder.sort_order'))
            ->numeric()
            ->integer()
            ->minValue(0)
            ->default(0)
            ->helperText('Số nhỏ hơn hiển thị trước. Chỉ so sánh giữa các mục cùng cấp (cùng "Thuộc chi nhánh").');
    }

    // Chỉ super_admin thấy field này (chọn chi nhánh thuộc đối tác nào) — tài khoản đối tác/nhân
    // viên tạo chi nhánh thì partner_id tự động lấy theo tài khoản đang đăng nhập (xem
    // CreateCategory::mutateFormDataBeforeCreate), không cần tự chọn.
    // Nếu KHÔNG gán partner_id, chi nhánh sẽ không hiện trong bất kỳ select "Chi nhánh" nào ở
    // các tài khoản đối tác — vì các select đó lọc theo partner_id của người đăng nhập.
    private static function partnerInput(): Select
    {
        return Select::make('partner_id')
            ->label('Đối tác sở hữu')
            // KHÔNG dùng ->relationship('partner', 'name') — Partner dùng Soft Delete, quan hệ
            // Eloquent mặc định LOẠI TRỪ đối tác đã xoá khỏi CẢ options() lẫn tra nhãn hiển thị, nên
            // nếu chi nhánh đang gán cho 1 đối tác đã bị xoá, field này sẽ hiện TRỐNG (như chưa gán
            // đối tác) dù partner_id thực tế vẫn đang trỏ đúng — dễ khiến admin tưởng nhầm là thiếu
            // dữ liệu. Tự liệt kê + tra nhãn bằng withTrashed() để LUÔN hiện đúng, đồng thời đánh
            // dấu rõ "(đã xoá)" cho đối tác không còn hoạt động.
            ->options(fn () => \App\Models\Partner::withTrashed()
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn ($partner) => [
                    $partner->id => $partner->name . ($partner->trashed() ? ' (đã xoá)' : ''),
                ])
                ->all())
            ->getOptionLabelUsing(fn ($value) => $value
                ? \App\Models\Partner::withTrashed()->find($value)?->name
                : null)
            ->dehydrated()
            ->searchable()
            ->preload()
            ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
            ->required()
            ->helperText('Bắt buộc chọn — nếu không, tài khoản đối tác sẽ không thấy chi nhánh này ở bất kỳ đâu.');
    }

    private static function parentCategoryInput(): SelectTree
    {
        return SelectTree::make('parent_id')
            ->label(__('category::category.form.label.parent_id'))
            ->placeholder(__('category::category.form.placeholder.parent_id'))
            ->relationship('parent', 'name', 'parent_id', function ($query, $get) {
                $currentId = $get('id');

                if ($currentId) {
                    $query->where('id', '!=', $currentId)
                        ->where(function ($q) use ($currentId) {
                            $q->where('parent_id', '!=', $currentId)
                                ->orWhereNull('parent_id');
                        });
                }

                $query->where('category_type', 'product');

                // Lọc theo quyền chi nhánh của user
                $user = auth()->user();
                if ($user && ! $user->isSuperAdmin()) {
                    $allowedIds = $user->allowedBranchIds();
                    if (! empty($allowedIds)) {
                        $query->where(function ($q) use ($allowedIds) {
                            $q->whereIn('id', $allowedIds)
                              ->orWhereIn('parent_id', $allowedIds);
                        });
                    } else {
                        // Chưa gán quyền chi nhánh cụ thể thì mặc định thấy chi nhánh của
                        // đối tác mình (partner_id), không chặn hết.
                        $query->where('partner_id', $user->partner_id);
                    }
                }

                return $query;
            })
            ->live(onBlur:true)
            ->enableBranchNode()
            ->nullable()
            ->helperText('Không bắt buộc. Chỉ chọn nếu đây là khu vực/chi nhánh con nằm trong 1 chi nhánh khác đã có — để trống nếu đây là chi nhánh độc lập.')
            ->dehydrateStateUsing(function ($state, $get) {
                $currentId = $get('id');

                if ($state == $currentId) return null;

                return $state;
            });
    }

    private static function statusToggle(): Toggle
    {
        return Toggle::make('status')
            ->label(__('category::category.form.label.status'))
            ->onIcon(__('category::category.form.icons.active'))
            ->offIcon(__('category::category.form.icons.inactive'))
            ->default(true)
            ->required();
    }

    private static function Image(): FileUpload
    {
        return FileUpload::make('image')
            ->label(__('category::category.form.label.image'))
            ->image()
            ->imageEditor()
            ->directory('categories')
            ->imagePreviewHeight('150')
            ->nullable()
            ->hint('Tải lên hình ảnh địa điểm');
    }
}
