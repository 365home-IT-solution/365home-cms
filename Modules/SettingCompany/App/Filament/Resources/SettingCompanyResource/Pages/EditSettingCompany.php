<?php

declare(strict_types=1);

namespace Modules\SettingCompany\App\Filament\Resources\SettingCompanyResource\Pages;

use Modules\SettingCompany\App\Filament\Resources\SettingCompanyResource;
use Filament\Resources\Pages\EditRecord;
use Modules\SettingCompany\Entities\Business;

class EditSettingCompany extends EditRecord
{
    protected static string $resource = SettingCompanyResource::class;

    // Override phương thức mount với tham số $record
    public function mount($record = null): void
    {
        // Kiểm tra xem bản ghi đã tồn tại dựa trên slug hay chưa
        $this->record = Business::firstOrCreate(
            ['slug' => 'cau-hinh-doanh-nghiep'], // Điều kiện kiểm tra duy nhất
            [
                'name' => 'Tên doanh nghiệp',
                'logo' => null,
                'address' => 'Địa chỉ',
                'phone' => '0xxxxxxxxx',
                'email' => 'default@example.com',
                'website' => 'đường link website',
                'tax_code' => 'Mã số thuế',
                'description' => 'Mô tả doanh nghiệp',
            ]
        );

        // Gọi phương thức cha với ID của bản ghi
        parent::mount($this->record->getKey());
    }
}
