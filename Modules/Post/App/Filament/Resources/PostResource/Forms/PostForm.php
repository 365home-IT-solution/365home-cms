<?php

declare(strict_types=1);

namespace Modules\Post\App\Filament\Resources\PostResource\Forms;

use AmidEsfahani\FilamentTinyEditor\TinyEditor;
use App\Models\User;
use CodeWithDennis\FilamentSelectTree\SelectTree;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieTagsInput;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Modules\Post\Entities\Post;
use TomatoPHP\FilamentMediaManager\Form\MediaManagerInput;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Livewire;
use Modules\Post\Livewire\SEO;

class PostForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(['default' => 1, 'xl' => 6])
                    ->schema([
                        Grid::make()
                            ->schema([
                                Section::make()->schema(self::mainSectionFields()),
                            ])
                            ->columnSpan(['xl' => 4]),
                        Grid::make()
                            ->schema([
                                Section::make()->schema(self::seoSectionFields()),
                            ])
                            ->columnSpan(['xl' => 2]),
                    ]),
            ]);
    }

    private static function mainSectionFields(): array
    {
        return [
            self::titleField(),
            self::slugField(),
            self::summaryField(),
            self::contentField(),
        ];
    }

    private static function seoSectionFields(): array
    {
        return [
            self::statusField(),
            self::publishedAtField(),
            self::postImageField(),
            self::categoriesField(),
            self::tagsField(),
            self::authorField(),
            self::seoTitleField(),
            self::seoDescriptionField(),
            self::seoKeywordsField(),
            self::btnCheckSEO(),
            self::SEOSuggetions(),
        ];
    }

    private static function btnCheckSEO(): Actions
    {
        return Actions::make([
            Action::make('analyzeSEO')
                ->label('Phân tích SEO')
                ->action(function ($livewire) {
                    $data = $livewire->data ?? [];
                    $livewire->dispatch('seon', [
                        'content'      => $data['content'] ?? null,
                        'title'        => $data['title'] ?? null,
                        'seo_title'    => $data['seo_title'] ?? null,
                        'description'  => $data['seo_description'] ?? null,
                        'focusKeyword' => $data['seo_keywords'] ?? null,
                        'url'          => $data['slug'] ?? null,
                        'id'           => $data['id'] ?? null,
                    ]);
                }),
        ])->visibleOn(['create', 'edit']);
    }

    private static function SEOSuggetions(): Livewire
    {
        return Livewire::make(SEO::class)->visibleOn(['create', 'edit']);
    }

    private static function titleField(): TextInput
    {
        return TextInput::make('title')
            ->label(__('post::post.form.label.title'))
            ->placeholder(__('post::post.form.placeholder.title'))
            ->rules(['required', 'max:255'])
            ->required()
            ->live(onBlur: true)
            ->afterStateUpdated(function ($state, Set $set, $livewire) {
                $set('slug', str()->slug($state));
                // Only auto-fill seo_title if it's empty (don't override manual edits)
                $set('seo_title', $state);

                $data = $livewire->data ?? [];
                $livewire->dispatch('seon', [
                    'content'      => $data['content'] ?? null,
                    'title'        => $state,
                    'seo_title'    => $state,
                    'description'  => $data['seo_description'] ?? null,
                    'focusKeyword' => $data['seo_keywords'] ?? null,
                    'url'          => $data['slug'] ?? null,
                    'id'           => $data['id'] ?? null,
                ]);
            })
            ->columnSpan(2);
    }

    private static function slugField(): TextInput
    {
        return TextInput::make('slug')
            ->label(__('post::post.form.label.slug'))
            ->placeholder(__('post::post.form.placeholder.slug'))
            ->required()
            ->unique(ignoreRecord: true)
            ->live(debounce: 1000)
            ->afterStateUpdated(function ($state, $livewire) {
                $data = $livewire->data ?? [];
                $livewire->dispatch('seon', [
                    'content'      => $data['content'] ?? null,
                    'title'        => $data['title'] ?? null,
                    'seo_title'    => $data['seo_title'] ?? null,
                    'description'  => $data['seo_description'] ?? null,
                    'focusKeyword' => $data['seo_keywords'] ?? null,
                    'url'          => $state,
                    'id'           => $data['id'] ?? null,
                ]);
            })
            ->columnSpan(2);
    }

    private static function summaryField(): Textarea
    {
        return Textarea::make('summary')
            ->label(__('post::post.form.label.summary'))
            ->maxLength(65535)
            ->rows(3)
            ->placeholder(__('post::post.form.placeholder.summary'))
            ->live(onBlur: true)
            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                // Auto-fill seo_description from summary if seo_description is empty
                if (empty($get('seo_description')) && !empty($state)) {
                    $set('seo_description', mb_substr(strip_tags($state), 0, 160));
                }
            })
            ->columnSpan(4);
    }

    private static function contentField(): TinyEditor
    {
        return TinyEditor::make('content')
            ->label(__('post::post.form.label.content'))
            ->profile('custom-full')
            ->required()
            ->columnSpan(4)
            ->live(debounce: 1000)
            ->reactive()
            ->afterStateUpdated(function ($state, $livewire) {
                $data = $livewire->data ?? [];
                $livewire->dispatch('seon', [
                    'content'      => $state,
                    'title'        => $data['title'] ?? null,
                    'seo_title'    => $data['seo_title'] ?? null,
                    'description'  => $data['seo_description'] ?? null,
                    'focusKeyword' => $data['seo_keywords'] ?? null,
                    'url'          => $data['slug'] ?? null,
                    'id'           => $data['id'] ?? null,
                ]);
            });
    }

    private static function authorField(): Select
    {
        return Select::make('author_id')
            ->label('Tác giả')
            ->relationship(
                'user',
                'fullname',
                function ($query) {
                    $actor = auth()->user();
                    if (! $actor || $actor->isSuperAdmin()) {
                        return $query;
                    }

                    $allowedBranchIds = $actor->allowedBranchIds();

                    if (! empty($allowedBranchIds)) {
                        $query->where(function ($q) use ($actor, $allowedBranchIds) {
                            $q->where('id', $actor->id)
                              ->orWhereHas('branchPermissions', fn ($bq) =>
                                  $bq->whereIn('category_id', $allowedBranchIds)
                              );
                        });
                    } else {
                        $query->where('id', $actor->id);
                    }

                    return $query;
                }
            )
            ->getOptionLabelFromRecordUsing(fn ($record) => $record->fullname ?? $record->email ?? 'ID: ' . $record->id)
            ->searchable()
            ->preload()
            ->default(fn () => auth()->id())
            ->required();
    }

    private static function tagsField(): SpatieTagsInput
    {
        return SpatieTagsInput::make('tags')
            ->label(__('post::post.form.label.tags'))
            ->type('post');
    }

    private static function categoriesField(): SelectTree
    {
        return SelectTree::make('categories')
            ->label(__('post::post.form.label.categories'))
            ->relationship(
                relationship: 'categories',
                titleAttribute: 'name',
                parentAttribute: 'parent_id',
                modifyQueryUsing: function ($query) {
                    $query->where('category_type', 'post');

                    $user = auth()->user();
                    if ($user && ! $user->isSuperAdmin()) {
                        $allowedIds = $user->allowedPostCategoryIds();
                        if (! empty($allowedIds)) {
                            $query->where(function ($q) use ($allowedIds) {
                                $q->whereIn('id', $allowedIds)
                                  ->orWhereIn('parent_id', $allowedIds);
                            });
                        } else {
                            $query->whereRaw('1 = 0');
                        }
                    }

                    return $query;
                }
            )
            ->required()
            ->placeholder(__('post::post.form.placeholder.categories'))
            ->enableBranchNode();
    }

    private static function postImageField(): mixed
    {
        return MediaManagerInput::make('Ảnh chính')
            ->label(__('post::post.form.label.post_image'))
            ->schema([])
            ->defaultItems(1)
            ->minItems(1)
            ->addable(false);
    }

    private static function seoTitleField(): TextInput
    {
        return TextInput::make('seo_title')
            ->label(__('post::post.form.label.seo_title'))
            ->placeholder(__('post::post.form.placeholder.seo_title'))
            ->helperText('Tối ưu: 50–60 ký tự')
            ->maxLength(60)
            ->live(onBlur: true)
            ->afterStateUpdated(function ($state, $livewire) {
                $data = $livewire->data ?? [];
                $livewire->dispatch('seon', [
                    'content'      => $data['content'] ?? null,
                    'title'        => $data['title'] ?? null,
                    'seo_title'    => $state,
                    'description'  => $data['seo_description'] ?? null,
                    'focusKeyword' => $data['seo_keywords'] ?? null,
                    'url'          => $data['slug'] ?? null,
                    'id'           => $data['id'] ?? null,
                ]);
            });
    }

    private static function seoDescriptionField(): Textarea
    {
        return Textarea::make('seo_description')
            ->label(__('post::post.form.label.seo_description'))
            ->placeholder(__('post::post.form.placeholder.seo_description'))
            ->helperText('Tối ưu: 150–160 ký tự')
            ->maxLength(160)
            ->rows(3)
            ->live(debounce: 1000)
            ->afterStateUpdated(function ($state, $livewire) {
                $data = $livewire->data ?? [];
                $livewire->dispatch('seon', [
                    'content'      => $data['content'] ?? null,
                    'title'        => $data['title'] ?? null,
                    'seo_title'    => $data['seo_title'] ?? null,
                    'description'  => $state,
                    'focusKeyword' => $data['seo_keywords'] ?? null,
                    'url'          => $data['slug'] ?? null,
                    'id'           => $data['id'] ?? null,
                ]);
            });
    }

    private static function seoKeywordsField(): TagsInput
    {
        // Build suggestions from existing keywords (handle both JSON array and comma-string formats)
        $suggestions = Post::query()
            ->whereNotNull('seo_keywords')
            ->where('seo_keywords', '!=', '')
            ->pluck('seo_keywords')
            ->flatMap(function ($value) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
                return array_map('trim', explode(',', $value));
            })
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        return TagsInput::make('seo_keywords')
            ->label(__('post::post.form.label.seo_keywords'))
            ->color('success')
            ->placeholder(__('post::post.form.placeholder.seo_keywords'))
            ->suggestions($suggestions)
            ->separator(',')
            ->live(debounce: 1000)
            ->afterStateUpdated(function ($state, $livewire) {
                $data = $livewire->data ?? [];
                $livewire->dispatch('seon', [
                    'content'      => $data['content'] ?? null,
                    'title'        => $data['title'] ?? null,
                    'seo_title'    => $data['seo_title'] ?? null,
                    'description'  => $data['seo_description'] ?? null,
                    'focusKeyword' => is_array($state) ? implode(', ', $state) : $state,
                    'url'          => $data['slug'] ?? null,
                    'id'           => $data['id'] ?? null,
                ]);
            });
    }

    private static function publishedAtField(): DateTimePicker
    {
        return DateTimePicker::make('published_at')
            ->label(__('post::post.form.label.published_at'))
            ->native(false)
            ->seconds(false)
            ->timezone('Asia/Ho_Chi_Minh')
            ->displayFormat('d/m/Y H:i')
            ->rules(['date'])
            ->hidden(fn (Get $get): bool => $get('status') !== 'published')
            ->live();
    }

    private static function statusField(): ToggleButtons
    {
        return ToggleButtons::make('status')
            ->label(__('post::post.form.label.status'))
            ->default('published')
            ->required()
            ->live()
            ->inline()
            ->options([
                'published' => __('post::post.form.options.status.published'),
                'draft'     => __('post::post.form.options.status.draft'),
                'archived'  => __('post::post.form.options.status.archived'),
            ])
            ->icons([
                'published' => __('post::post.form.icons.status.published'),
                'draft'     => __('post::post.form.icons.status.draft'),
                'archived'  => __('post::post.form.icons.status.archived'),
            ])
            ->colors([
                'published' => __('post::post.form.colors.status.published'),
                'draft'     => __('post::post.form.colors.status.draft'),
                'archived'  => __('post::post.form.colors.status.archived'),
            ])
            ->columnSpanFull();
    }
}
