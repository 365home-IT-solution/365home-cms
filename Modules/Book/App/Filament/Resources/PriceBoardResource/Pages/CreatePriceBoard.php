<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources\PriceBoardResource\Pages;

use App\Models\User;
use App\Services\PriceBoards\PriceBoardV2Service;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Modules\Book\App\Filament\Resources\PriceBoardResource;
use Modules\Book\App\Filament\Resources\PriceBoardResource\Forms\PriceBoardSheetsForm;

/**
 * Tạo bảng giá v2 — cùng nghiệp vụ API POST /api/admin/price-boards (PriceBoardV2Service): kiểm tra
 * ràng buộc bộ giá + trùng ngày, lưu, sinh item cho từng phòng rồi ghi giá bằng động cơ có sẵn.
 */
class CreatePriceBoard extends CreateRecord
{
    protected static string $resource = PriceBoardResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user    = auth()->user();
        $service = app(PriceBoardV2Service::class);

        $partnerId = $user->seesAllPartners() ? ($data['partner_id'] ?? null) : $user->partner_id;
        $template  = $user->isSuperAdmin() && ! empty($data['is_template']);

        if (! $partnerId && ! $template) {
            $this->fail('Chọn đối tác sở hữu bảng giá.');
        }

        try {
            $spec = $service->specFromRequest(PriceBoardSheetsForm::toRequest($data), (string) $partnerId, $user);
        } catch (ValidationException $e) {
            $this->fail((string) (collect($e->errors())->flatten()->first() ?? 'Dữ liệu không hợp lệ.'));
        }

        if ($template) {
            return $service->persistTemplate($spec, [
                'code'           => $data['code'] ?? null,
                'description'    => $data['description'] ?? null,
                'default_scope'  => $data['default_scope'] ?? 'partner',
                'requires_dates' => (bool) ($data['requires_dates'] ?? false),
            ]);
        }

        $eval = $service->evaluate($spec);

        if ($eval['errors']) {
            $this->fail((string) collect($eval['errors'])->flatten()->first());
        }

        if ($eval['conflicts']) {
            $first = $eval['conflicts'][0];
            $this->fail('Có phòng đang nằm trong bảng "' . $first['board']['name'] . '" đang bật và trùng ngày — tắt bảng kia hoặc đổi khoảng ngày.');
        }

        return $service->persist($spec, null);
    }

    private function fail(string $message): never
    {
        Notification::make()->title('Không lưu được bảng giá')->body($message)->danger()->send();

        throw new Halt();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
