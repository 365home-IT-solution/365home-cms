<?php

namespace Modules\Minihouse\App\Filament\Pages;

use App\Filament\Pages\PartnerBankAccount as BasePartnerBankAccount;

// Panel MiniHouse: cùng trang "Tài khoản ngân hàng" của chủ đối tác (ghi partners.bank_*, đồng bộ với tab Tài chính của Super Admin).
class PartnerBankAccount extends BasePartnerBankAccount
{
    protected static ?int $navigationSort = 80;
}
