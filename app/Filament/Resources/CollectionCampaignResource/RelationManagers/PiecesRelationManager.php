<?php

declare(strict_types=1);

namespace App\Filament\Resources\CollectionCampaignResource\RelationManagers;

use App\Models\CollectionPiece;
use App\Services\CollectionService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Modules\Category\Entities\Category;

// Chi nhánh tham gia chiến dịch = mỗi chi nhánh một mảnh (kèm ảnh mảnh). Chiến dịch đã bắt đầu và có người nhận mảnh thì khoá
// việc thêm / xoá / đổi chi nhánh (đổi ảnh vẫn được) — giống CollectionAdminController::syncPieces.
class PiecesRelationManager extends RelationManager
{
    protected static string $relationship = 'pieces';

    protected static ?string $title = 'Mảnh ghép (chi nhánh tham gia)';

    protected static ?string $modelLabel = 'mảnh';

    private function locked(): bool
    {
        return $this->getOwnerRecord()->isLocked();
    }

    // Chi nhánh = danh mục kiểu product ở bất kỳ cấp nào; không chọn đồng thời cha và con của nhau (đơn ở con luôn tính cho mảnh con).
    private function guardBranch(int $branchId, ?CollectionPiece $ignore = null): void
    {
        $others = $this->getOwnerRecord()->pieces()->when($ignore, fn ($q) => $q->where('id', '!=', $ignore->id))->pluck('branch_id')->map(fn ($v) => (int) $v)->all();

        if (in_array($branchId, $others, true)) {
            throw ValidationException::withMessages(['mountedTableActionsData.0.branch_id' => 'Chi nhánh này đã tham gia chiến dịch.']);
        }

        $chain = CollectionService::categoryChain($branchId);
        $ancestors = array_slice($chain, 1);
        $descendantClash = collect($others)->contains(fn ($o) => in_array($branchId, array_slice(CollectionService::categoryChain($o), 1), true));

        if (array_intersect($ancestors, $others) || $descendantClash) {
            throw ValidationException::withMessages(['mountedTableActionsData.0.branch_id' => 'Không chọn đồng thời một chi nhánh và chi nhánh con của nó.']);
        }
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('branch_id')
                ->label('Chi nhánh')
                ->options(fn () => Category::withoutGlobalScopes()->where('category_type', 'product')->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required()
                ->disabled(fn (?CollectionPiece $record) => $record !== null && $this->locked())
                ->dehydrated(),

            FileUpload::make('image')
                ->label('Ảnh mảnh')
                ->image()
                ->disk('public')
                ->directory('collections/pieces')
                ->maxSize(5120),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('#'),
                ImageColumn::make('image')->label('Ảnh mảnh')->disk('public')->height(64),
                TextColumn::make('branch.name')->label('Chi nhánh'),
                TextColumn::make('collected')->label('Đã nhận')
                    ->state(fn (CollectionPiece $r) => $this->getOwnerRecord()->customerPieces()->where('branch_id', $r->branch_id)->count()),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([
                CreateAction::make()->label('Thêm chi nhánh')
                    ->visible(fn () => ! $this->locked())
                    ->using(function (array $data): CollectionPiece {
                        $this->guardBranch((int) $data['branch_id']);

                        return $this->getOwnerRecord()->pieces()->create($data + ['sort_order' => (int) $this->getOwnerRecord()->pieces()->max('sort_order') + 1]);
                    }),
            ])
            ->actions([
                EditAction::make()->label('Sửa')
                    ->using(function (CollectionPiece $record, array $data): CollectionPiece {
                        if (! $this->locked() && (int) $data['branch_id'] !== (int) $record->branch_id) {
                            $this->guardBranch((int) $data['branch_id'], $record);
                        }
                        $record->update($data);

                        return $record;
                    }),

                DeleteAction::make()->label('Xoá')
                    ->visible(fn () => ! $this->locked())
                    ->before(function (CollectionPiece $record, DeleteAction $action): void {
                        if ($this->getOwnerRecord()->pieces()->count() - 1 < $this->getOwnerRecord()->pieces_required) {
                            Notification::make()->title('Số chi nhánh tham gia phải ≥ số mảnh cần đủ (' . $this->getOwnerRecord()->pieces_required . ').')->danger()->send();
                            $action->cancel();
                        }
                    }),
            ])
            ->bulkActions([]);
    }
}
