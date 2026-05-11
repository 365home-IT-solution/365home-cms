<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Tables;

use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Forms\SectionConfigForm;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Services\ConfigValueHandler;

class ThemeSectionRelationManager extends RelationManager
{
    protected static string $relationship = 'sections';

    protected static ?string $title = 'Sections';

    protected static ?string $navigationLabel = 'Sections';

    protected function getValueHandler(): ConfigValueHandler
    {
        return app(ConfigValueHandler::class);
    }

    protected function handleRecordUpdate($record, array $data): void
    {
        DB::beginTransaction();

        try {
            foreach ($data['config'] as $key => $value) {
                $config = $record->sectionCfgs()
                    ->where('key', $key)
                    ->first();

                if ($config) {
                    $value = $this->getValueHandler()->formatValueForStorage($value, $config);
                    $config->update(['value' => $value]);
                }
            }

            DB::commit();

            Notification::make()
                ->title('Đã lưu thay đổi')
                ->success()
                ->send();
        } catch (\Exception $e) {
            DB::rollBack();

            Notification::make()
                ->title('Có lỗi xảy ra')
                ->danger()
                ->body($e->getMessage())
                ->send();
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Giao diện & Hiển thị')
            ->description('Tùy chỉnh cách hiển thị và các thành phần giao diện của website')
            ->groups([
                Group::make('parent_id')
                    ->label('Cấu hình theo nhóm')
                    ->titlePrefixedWithLabel(false)
                    ->getDescriptionFromRecordUsing(fn($record) => Str::limit($record->parent?->description, 50))
                    ->getTitleFromRecordUsing(fn($record) =>
                    $record->parent
                        ? "Nhóm cấu hình: {$record->parent->label}"
                        : 'Cấu hình chung'
                    )
                    ->collapsible()
            ])
            ->groupingSettingsHidden(false)
            ->modifyQueryUsing(fn(Builder $query) => $query->whereNotNull('parent_id'))
            ->columns([
                TextColumn::make('label')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->label("Tên cấu hình")
                    ->icon(fn($record) => $record->icon ?? 'heroicon-o-cog')
                    ->iconPosition('before')
                    ->tooltip('Click để chỉnh sửa cấu hình này')
                    ->description(fn ($record) => $record->description)
                    ->wrap()
            ])
            ->defaultGroup('parent_id')
            ->groupRecordsTriggerAction(
                fn(Action $action) => $action
                    ->button()
                    ->label('Nhóm cấu hình')
                    ->icon('heroicon-m-squares-2x2')
            )
            ->recordAction('config')
            ->actions([
                Action::make('config')
                    ->label('Cấu hình')
                    ->button()
                    ->slideOver()
                    ->size('lg')
                    ->icon('heroicon-o-pencil-square')
                    ->modalHeading(fn($record) => "Cấu hình ".$record->label)
                    ->modalDescription(fn($record) => $record->description)
                    ->modalIcon(fn($record) => $record->icon)
                    ->modalSubmitActionLabel('Lưu thay đổi')
                    ->modalWidth('5xl')
                    ->form(fn($record) => SectionConfigForm::getFormSchema($record))
                    ->mountUsing(fn(Form $form, $record) => $form->fill([
                        'config' => $this->getValueHandler()->fillForm($record->sectionCfgs)
                    ]))
                    ->action(function (array $data, $record): void {
                        $this->handleRecordUpdate($record, $data);
                    })
            ])
            ->poll('30s')
            ->recordClasses('cursor-pointer hover:bg-gray-50');
    }
}
