<?php

declare(strict_types=1);

namespace Modules\Tag\App\Filament\Resources\TagResource\Forms;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TagForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('')->schema([
                    TextInput::make('name')
                        ->label(__('tag::tag.form.label.name'))
                        ->placeholder(__('tag::tag.form.placeholder.name'))
                        ->formatStateUsing(fn ($state) => \is_array($state) ? ($state[app()->getLocale()] ?? '') : $state)
                        ->rules(['max:255', function (Get $get) {
                            $postId = $get('id');
                            return $postId
                                ? Rule::unique('tags', 'name')->ignore($postId)
                                : Rule::unique('tags', 'name');
                        }])
                        ->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) {
                            if (($get('slug') ?? '') !== Str::slug($old)) {
                                return;
                            }
                            $set('slug', Str::slug($state));
                        })
                        ->columnSpan(6),

                    TextInput::make('slug')
                        ->label(__('tag::tag.form.label.slug'))
                        ->formatStateUsing(fn ($state) => \is_array($state) ? ($state[app()->getLocale()] ?? '') : $state)
                        ->placeholder(__('tag::tag.form.placeholder.slug'))
                        ->required()
                        ->rules([function (Get $get) {
                            $postId = $get('id');
                            return $postId
                                ? Rule::unique('tags', 'slug')->ignore($postId)
                                : Rule::unique('tags', 'slug');
                        }])
                        ->columnSpan(6),

                    FileUpload::make('image')
                        ->label(__('tag::tag.form.label.image'))
                        ->image()
                        ->imageEditor()
                        ->directory('Amenity')
                        ->imagePreviewHeight('100')
                        ->nullable()
                        ->hint('Tải lên hình ảnh tiện nghi')
                        ->columnSpanFull()
                        ->formatStateUsing(function ($state) {
                            // Handle legacy JSON {"vi":"path"} and plain string formats
                            if (\is_array($state)) {
                                $state = $state[app()->getLocale()] ?? collect($state)->first();
                            } elseif (is_string($state) && str_starts_with($state, '{')) {
                                $decoded = json_decode($state, true);
                                if (\is_array($decoded)) {
                                    $state = $decoded[app()->getLocale()] ?? collect($decoded)->first();
                                }
                            }
                            return $state ? [$state] : [];
                        })
                        ->dehydrateStateUsing(function ($state) {
                            // Save as plain string, not locale-wrapped array
                            return \is_array($state) ? (collect($state)->first() ?? null) : $state;
                        })
                        ->multiple(false)
                ])->columns(12)
            ]);
    }
}
