<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources\CategoryResource\Forms;

use CodeWithDennis\FilamentSelectTree\SelectTree;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
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
                self::descriptionInput(),
            ])
            ->columns(2);
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
            ->columnSpan(1);
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
            ->columnSpan(1);
    }

    private static function descriptionInput(): Textarea
    {
        return Textarea::make('description')
            ->label(__('category::category.form.label.description'))
            ->placeholder(__('category::category.form.placeholder.description'))
            ->rows(3)
            ->columnSpan(2);
    }

    private static function categoryDetailsSection(): Section
    {
        return Section::make()
            ->schema([
                self::parentCategoryInput(),
                self::categoryTypeInput(),
                self::statusToggle(),
            ])
            ->columnSpan(1);
    }

    private static function parentCategoryInput(): SelectTree
    {
        return SelectTree::make('parent_id')
            ->label(__('category::category.form.label.parent_id'))
            ->placeholder(__('category::category.form.placeholder.parent_id'))
            ->relationship('parent', 'name', 'parent_id', function ($query, $get) {
                $currentId    = $get('id');
                $categoryType = $get('category_type');

                if ($currentId) {
                    $query->where('id', '!=', $currentId)
                        ->where(function ($q) use ($currentId) {
                            $q->where('parent_id', '!=', $currentId)
                                ->orWhereNull('parent_id');
                        });
                }

                if ($categoryType) {
                    $query->where('category_type', $categoryType);
                }

                // Lọc theo quyền chi nhánh của user
                $user = auth()->user();
                if ($user && ! $user->isSuperAdmin()) {
                    if ($categoryType === 'product') {
                        $allowedIds = $user->allowedCategoryIds();
                        if (! empty($allowedIds)) {
                            $query->whereIn('id', $allowedIds);
                        } else {
                            $query->whereRaw('1 = 0');
                        }
                    } elseif ($categoryType === 'post') {
                        $allowedPostIds = $user->allowedPostCategoryIds();
                        if (! empty($allowedPostIds)) {
                            $query->where(function ($q) use ($allowedPostIds) {
                                $q->whereIn('id', $allowedPostIds)
                                  ->orWhereIn('parent_id', $allowedPostIds);
                            });
                        } else {
                            $query->whereRaw('1 = 0');
                        }
                    }
                }

                return $query;
            })
            ->live(onBlur:true)
            ->enableBranchNode()
            ->nullable()
            ->dehydrateStateUsing(function ($state, $get) {
                $currentId = $get('id');

                if ($state == $currentId) return null;

                return $state;
            });
    }

    private static function categoryTypeInput(): Select
    {
        return Select::make('category_type')
            ->label(__('category::category.form.label.category_type'))
            ->placeholder(__('category::category.form.placeholder.category_type'))
            ->required()
            ->live(onBlur:true)
            ->options([
                'product' => __('category::category.form.options.product'),
                'post' => __('category::category.form.options.post'),
            ]);
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
            ->imagePreviewHeight('100')
            ->nullable()
            ->hint('Tải lên hình ảnh địa điểm');
    }
}
