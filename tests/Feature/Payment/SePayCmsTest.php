<?php

namespace Tests\Feature\Payment;

use App\Models\Partner;
use App\Models\User;
use App\Services\Payment\PartnerPayOsChannelService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Payment\App\Filament\Resources\PartnerSePayAccountResource;
use Modules\Payment\App\Filament\Resources\PartnerSePayAccountResource\Pages\CreatePartnerSePayAccount;
use Modules\Payment\App\Filament\Resources\PartnerSePayAccountResource\Pages\EditPartnerSePayAccount;
use Modules\Payment\App\Filament\Resources\PartnerSePayAccountResource\Pages\ListPartnerSePayAccounts;
use Modules\Payment\App\Filament\Resources\SePayTransactionResource;
use Modules\Payment\App\Filament\Resources\SePayTransactionResource\Pages\ListSePayTransactions;
use Modules\Payment\Entities\PartnerPayOsAccount;
use Modules\Payment\Entities\PartnerSePayAccount;
use Modules\Payment\Entities\SePayTransaction;
use Tests\TestCase;

// CMS "SePay theo đối tác" + "Giao dịch SePay": chỉ super_admin, lưu đúng BIN/tên chủ tài khoản, chặn trùng và chặn dùng cùng lúc PayOS riêng.
class SePayCmsTest extends TestCase
{
    use DatabaseTransactions;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Settings\SePaySettings::fake(['enabled' => null, 'webhook_secret' => null, 'code_prefix' => null, 'api_token' => null, 'min_amount' => null]); // tách khỏi cấu hình CMS thật

        $this->partner = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->firstOrFail();
        PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->delete();
        PartnerSePayAccount::query()->where('account_number', '0011001234567')->delete();
        PartnerPayOsAccount::query()->where('partner_id', $this->partner->id)->delete();

        $this->actingAs(User::role('super_admin')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function formData(array $extra = []): array
    {
        return array_merge([
            'partner_id' => $this->partner->id, 'is_active' => true, 'bank_code' => 'VCB',
            'account_number' => '0011001234567', 'account_holder' => 'nguyen van a',
        ], $extra);
    }

    public function test_super_admin_creates_the_account_with_bin_and_uppercase_holder(): void
    {
        Livewire::test(ListPartnerSePayAccounts::class)->assertSuccessful();

        Livewire::test(CreatePartnerSePayAccount::class)->assertSuccessful()
            ->fillForm($this->formData())->call('create')->assertHasNoFormErrors();

        $account = PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->firstOrFail();
        $this->assertSame('970436', $account->bank_bin);
        $this->assertSame('NGUYEN VAN A', $account->account_holder);
        $this->assertTrue($account->is_active);
        $this->assertSame(auth()->id(), $account->updated_by);
    }

    public function test_duplicate_account_number_or_partner_is_rejected(): void
    {
        PartnerSePayAccount::query()->create(['partner_id' => $this->partner->id, 'is_active' => true, 'bank_code' => 'VCB', 'bank_bin' => '970436', 'account_number' => '0011001234567', 'account_holder' => 'X']);

        Livewire::test(CreatePartnerSePayAccount::class)
            ->fillForm($this->formData())->call('create')
            ->assertHasFormErrors(['partner_id' => 'unique', 'account_number' => 'unique']);
    }

    public function test_cannot_turn_on_sepay_while_the_partner_has_an_active_payos_channel(): void
    {
        PartnerPayOsAccount::query()->create(['partner_id' => $this->partner->id, 'is_active' => true, 'client_id' => 'c', 'api_key' => 'a', 'checksum_key' => 'k']);

        Livewire::test(CreatePartnerSePayAccount::class)
            ->fillForm($this->formData())->call('create')
            ->assertHasFormErrors(['is_active']);
        $this->assertSame(0, PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->count());

        // Lưu ở trạng thái tắt thì được
        Livewire::test(CreatePartnerSePayAccount::class)
            ->fillForm($this->formData(['is_active' => false]))->call('create')->assertHasNoFormErrors();
        $this->assertFalse(PartnerSePayAccount::query()->where('partner_id', $this->partner->id)->firstOrFail()->is_active);
    }

    public function test_enabling_payos_is_blocked_while_sepay_is_active(): void
    {
        PartnerSePayAccount::query()->create(['partner_id' => $this->partner->id, 'is_active' => true, 'bank_code' => 'VCB', 'bank_bin' => '970436', 'account_number' => '0011001234567', 'account_holder' => 'X']);

        $this->expectException(ValidationException::class);
        app(PartnerPayOsChannelService::class)->save($this->partner, ['client_id' => 'c', 'api_key' => 'a', 'checksum_key' => 'k', 'is_active' => true], auth()->user());
    }

    public function test_edit_changes_the_bank_and_keeps_the_partner(): void
    {
        $account = PartnerSePayAccount::query()->create(['partner_id' => $this->partner->id, 'is_active' => true, 'bank_code' => 'VCB', 'bank_bin' => '970436', 'account_number' => '0011001234567', 'account_holder' => 'X']);

        Livewire::test(EditPartnerSePayAccount::class, ['record' => $account->getKey()])->assertSuccessful()
            ->fillForm(['bank_code' => 'MB', 'account_holder' => 'tran thi b', 'is_active' => false])->call('save')->assertHasNoFormErrors();

        $account->refresh();
        $this->assertSame('MB', $account->bank_code);
        $this->assertSame('970422', $account->bank_bin);
        $this->assertSame('TRAN THI B', $account->account_holder);
        $this->assertFalse($account->is_active);
        $this->assertSame($this->partner->id, $account->partner_id);
    }

    public function test_transaction_log_lists_rejected_payments_for_reconciliation(): void
    {
        $tx = SePayTransaction::query()->create([
            'sepay_id' => 700001001, 'amount' => 123000, 'status' => 'rejected', 'failure_reason' => 'Số tiền không khớp; cần 1, nhận 123000.',
            'payment_code' => 'DH123', 'payload' => ['id' => 700001001, 'transferAmount' => 123000],
        ]);

        Livewire::test(ListSePayTransactions::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$tx])
            ->filterTable('status', 'rejected')->assertCanSeeTableRecords([$tx])
            ->filterTable('status', 'processed')->assertCanNotSeeTableRecords([$tx]);
    }

    public function test_only_super_admin_can_open_these_screens(): void
    {
        $this->assertTrue(PartnerSePayAccountResource::canViewAny());
        $this->assertTrue(SePayTransactionResource::canViewAny());

        $partnerUser = new User(['fullname' => 'Doi tac', 'email' => 'sepay-' . uniqid() . '@test.local', 'password' => 'secret-secret']);
        $partnerUser->forceFill(['partner_id' => $this->partner->id])->save();
        $this->actingAs($partnerUser);

        $this->assertFalse(PartnerSePayAccountResource::canViewAny());
        $this->assertFalse(SePayTransactionResource::canViewAny());
    }
}
