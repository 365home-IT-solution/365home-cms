<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources\PostCategoryResource\Forms;

use CodeWithDennis\FilamentSelectTree\SelectTree;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Illuminate\Support\Str;
use Filament\Forms\Set;
use Illuminate\Validation\Rule;

class PostCategoryForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(4)
                    ->schema([
                        self::basicInfoSection()->columnSpan(3),
                        self::categoryDetailsSection()->columnSpan(1),
                        self::Image()->columnSpan(3),
                    ]),
            ]);
    }

    private static function basicInfoSection(): Section
    {
        return Section::make()
            ->schema([
                self::nameInput(),
                self::slugInput(),
            ])
            ->columns(2);
    }

    private static function nameInput(): TextInput
    {
        return TextInput::make('name')
            ->label('Tên danh mục')
            ->placeholder('Nhập tên danh mục bài viết...')
            ->required()
            ->rules(['max:255'])
            ->live(onBlur: true)
            ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) {
                if (($get('slug') ?? '') !== Str::slug($old)) {
                    return;
                }
                $set('slug', Str::slug($state));
            })
            ->columnSpan(1);
    }

    private static function slugInput(): TextInput
    {
        return TextInput::make('slug')
            ->label('Đường dẫn')
            ->placeholder('Tự động tạo từ tên danh mục...')
            ->required()
            ->rules([function (Get $get) {
                $categoryId = $get('id');
                return $categoryId
                    ? Rule::unique('categories', 'slug')->ignore($categoryId)
                    : Rule::unique('categories', 'slug');
            }])
            ->columnSpan(1);
    }

    private static function categoryDetailsSection(): Section
    {
        return Section::make()
            ->schema([
                self::categoryTypeHidden(),
                self::parentCategoryInput(),
                self::sortOrderInput(),
                self::statusToggle(),
            ])
            ->columnSpan(1);
    }

    // Resource này chỉ quản lý danh mục bài viết — không cần cho chọn "Kiểu hiển thị" như
    // CategoryResource (chi nhánh), luôn cố định category_type = post.
    private static function categoryTypeHidden(): Hidden
    {
        return Hidden::make('category_type')->default('post')->dehydrated();
    }

    // Thứ tự hiển thị giữa các danh mục CÙNG CẤP (cùng "Danh mục cha/con") — số nhỏ hơn hiển thị
    // trước.
    private static function sortOrderInput(): TextInput
    {
        return TextInput::make('sort_order')
            ->label('Thứ tự hiển thị')
            ->placeholder('0')
            ->numeric()
            ->integer()
            ->minValue(0)
            ->default(0)
            ->helperText('Số nhỏ hơn hiển thị trước. Chỉ so sánh giữa các mục cùng cấp (cùng "Danh mục cha/con").');
    }

    private static function parentCategoryInput(): SelectTree
    {
        return SelectTree::make('parent_id')
            ->label('Danh mục cha/con')
            ->placeholder('Chọn danh mục cha...')
            ->relationship('parent', 'name', 'parent_id', function ($query, $get) {
                $currentId = $get('id');

                if ($currentId) {
                    $query->where('id', '!=', $currentId)
                        ->where(function ($q) use ($currentId) {
                            $q->where('parent_id', '!=', $currentId)
                                ->orWhereNull('parent_id');
                        });
                }

                $query->where('category_type', 'post');

                // Lọc theo quyền danh mục bài viết của user
                $user = auth()->user();
                if ($user && ! $user->isSuperAdmin()) {
                    $allowedPostIds = $user->allowedDirectPostRootIds();
                    if (! empty($allowedPostIds)) {
                        $query->where(function ($q) use ($allowedPostIds) {
                            $q->whereIn('id', $allowedPostIds)
                              ->orWhereIn('parent_id', $allowedPostIds);
                        });
                    } else {
                        $query->whereRaw('1 = 0');
                    }
                }

                return $query;
            })
            ->live(onBlur: true)
            ->enableBranchNode()
            ->nullable()
            ->helperText('Nếu để trống thì đây là danh mục cha.')
            ->dehydrateStateUsing(function ($state, $get) {
                $currentId = $get('id');

                if ($state == $currentId) return null;

                return $state;
            });
    }

    private static function statusToggle(): Toggle
    {
        return Toggle::make('status')
            ->label('Trạng thái')
            ->onIcon('heroicon-o-eye')
            ->offIcon('heroicon-o-eye-slash')
            ->default(true)
            ->required();
    }

    private static function Image(): FileUpload
    {
        return FileUpload::make('image')
            ->label('Hình ảnh')
            ->image()
            ->imageEditor()
            ->directory('categories')
            ->imagePreviewHeight('100')
            ->nullable()
            ->hint('Tải lên hình ảnh danh mục bài viết');
    }
}
