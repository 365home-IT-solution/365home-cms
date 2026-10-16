<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources\PriceBoardResource\Pages;

use App\Models\User;
use App\Services\PriceBoardSyncService;
use App\Services\PriceBoards\PriceBoardV2Service;
use App\Services\RoomSlotRandomizer;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Book\App\Filament\Resources\PriceBoardResource;
use Modules\Book\App\Filament\Resources\PriceBoardResource\Forms\PriceBoardSheetsForm;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\PriceBoardPriceLog;

class EditPriceBoard extends EditRecord
{
    protected static string $resource = PriceBoardResource::class;

    protected function getHeaderActions(): array
    {
        // Chụp danh sách phòng TRƯỚC khi xoá (cascade mất item), tính lại giá SAU khi xoá.
        $affectedProducts = collect();

        return [
            Actions\Action::make('history')
                ->label('Lịch sử')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->modalHeading('Lịch sử thay đổi giá')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Đóng')
                ->modalContent(fn () => view('book::filament.resources.price-board-resource.history-modal', [
                    'logs' => PriceBoardPriceLog::where('price_board_id', $this->record->id)
                        ->with(['product', 'changedByUser'])
                        ->orderByDesc('created_at')
                        ->limit(200)
                        ->get(),
                ])),
            Actions\Action::make('apply_now')
                ->label('Áp dụng ngay')
                ->icon('heroicon-o-bolt')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Áp ngay giá của bảng này xuống các phòng, bất kể ngày hiệu lực. Job đêm sẽ tính lại theo ngày hiệu lực.')
                ->visible(fn () => ! $this->record->is_template)
                ->action(function () {
                    app(PriceBoardSyncService::class)->applyBoard($this->record);

                    Notification::make()->title('Đã áp dụng bảng giá')->success()->send();
                }),
            Actions\Action::make('duplicate')
                ->label('Nhân bản')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->visible(fn () => ! $this->record->is_template)
                ->action(function () {
                    $service = app(PriceBoardV2Service::class);
                    $clone   = $service->cloneBoard($this->record, (string) $this->record->partner_id, [
                        'name' => $this->record->name . ' (bản sao)', 'is_active' => false,
                    ]);

                    $copy = $service->persist($clone['spec'], null);

                    Notification::make()->title('Đã nhân bản bảng giá')->success()->send();

                    $this->redirect(PriceBoardResource::getUrl('edit', ['record' => $copy]));
                }),
            Actions\DeleteAction::make()
                ->before(function () use (&$affectedProducts) {
                    $affectedProducts = app(PriceBoardSyncService::class)->productsAffectedByBoard($this->record);
                })
                ->after(function () use (&$affectedProducts) {
                    app(PriceBoardSyncService::class)->resyncProductsAfterBoardDeleted($affectedProducts);
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PriceBoard $record */
        $record = $this->record;
        $spec   = app(PriceBoardV2Service::class)->specFromBoard($record);

        // Bảng dựng sẵn lưu khung giờ theo GIỜ → dò lại id khung hệ thống để hiện trong form.
        if ($record->is_template) {
            $slots = app(RoomSlotRandomizer::class);

            foreach ($record->sheets as $i => $sheet) {
                $spec['sheets'][$i]['time_slots'] = collect((array) $sheet->slot_times)->map(function (array $t) use ($slots) {
                    $found = $slots->findSlot(null, $t['start'], $t['end'], (bool) ($t['over_night'] ?? false));

                    return $found ? ['timeslot_id' => $found->id, 'price' => (int) $t['price'], 'over_night' => (bool) ($t['over_night'] ?? false)] : null;
                })->filter()->values()->all();
            }
        }

        return array_merge($data, PriceBoardSheetsForm::toFormData($spec, $record));
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $user */
        $user    = auth()->user();
        $service = app(PriceBoardV2Service::class);

        try {
            $spec = $service->specFromRequest(PriceBoardSheetsForm::toRequest($data), (string) ($record->partner_id ?? ''), $user, $record);
        } catch (ValidationException $e) {
            $this->fail((string) (collect($e->errors())->flatten()->first() ?? 'Dữ liệu không hợp lệ.'));
        }

        if ($record->is_template) {
            return $service->persistTemplate($spec, [
                'code'           => $data['code'] ?? $record->code,
                'description'    => $data['description'] ?? $record->description,
                'default_scope'  => $data['default_scope'] ?? $record->default_scope,
                'requires_dates' => (bool) ($data['requires_dates'] ?? $record->requires_dates),
                'tags'           => $record->tags,
            ], $record);
        }

        $eval = $service->evaluate($spec, $record);

        if ($eval['errors']) {
            $this->fail((string) collect($eval['errors'])->flatten()->first());
        }

        if ($eval['conflicts']) {
            $first = $eval['conflicts'][0];
            $this->fail('Có phòng đang nằm trong bảng "' . $first['board']['name'] . '" đang bật và trùng ngày — tắt bảng kia hoặc đổi khoảng ngày.');
        }

        return $service->persist($spec, $record);
    }

    private function fail(string $message): never
    {
        Notification::make()->title('Không lưu được bảng giá')->body($message)->danger()->send();

        throw new Halt();
    }
}
