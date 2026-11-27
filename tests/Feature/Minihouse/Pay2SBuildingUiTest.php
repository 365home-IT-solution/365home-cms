<?php

namespace Tests\Feature\Tmp;

use App\Models\User;
use App\Settings\Pay2SSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

class Pay2SBuildingUiTest extends TestCase
{
    use DatabaseTransactions;

    private function building(): Building
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);

        return Building::create([
            'zone_id' => $zone->id, 'name' => 'B' . uniqid(), 'address' => 'a', 'electric_unit_price' => 3500, 'water_unit_price' => 15000,
            'payment_method' => 'pay2s', 'owner_bank_bin' => '970415', 'owner_bank_name' => 'VietinBank', 'owner_bank_account_number' => '7700445566',
            'owner_bank_account_holder' => 'NGUYEN VAN CHU', 'pay2s_webhook_token' => 'tok-abcdef-123456',
        ]);
    }

    public function test_building_edit_form_shows_pay2s_section_and_saves(): void
    {
        Pay2SSettings::fake(['enabled' => null, 'api_token' => null, 'default_gateway' => null, 'platform_bank_code' => null, 'platform_bank_bin' => null, 'platform_account_number' => null, 'platform_account_holder' => null, 'platform_webhook_token' => null]);
        $this->actingAs(User::role('super_admin')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('minihouse-admin'));
        $b = $this->building();

        Livewire::test(\Modules\Minihouse\App\Filament\Resources\BuildingResource\Pages\EditBuilding::class, ['record' => $b->getKey()])
            ->assertSuccessful()
            ->assertFormSet(['payment_method' => 'pay2s'])
            ->assertSee('Token webhook Pay2S')
            ->fillForm(['pay2s_webhook_token' => 'tok-new-654321'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('tok-new-654321', $b->fresh()->pay2s_webhook_token);
    }

    public function test_invoice_qr_modal_renders_for_a_pay2s_building(): void
    {
        $this->actingAs(User::role('super_admin')->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('minihouse-admin'));
        $b = $this->building();
        $room = Room::create(['building_id' => $b->id, 'code' => 'P-' . random_int(100, 999), 'price' => 3000000, 'status' => Room::STATUS_RENTED]);
        $tenant = Tenant::create(['fullname' => 'T', 'phone' => '0911112222', 'room_id' => $room->id]);
        $contract = Contract::create(['room_id' => $room->id, 'tenant_id' => $tenant->id, 'start_date' => now()->subMonth(), 'monthly_price' => 3000000, 'deposit_amount' => 3000000, 'status' => Contract::STATUS_ACTIVE]);
        $invoice = Invoice::create(['contract_id' => $contract->id, 'month' => now()->startOfMonth(), 'room_price' => 3000000, 'total_amount' => 3000000, 'amount_paid' => 0, 'status' => Invoice::STATUS_UNPAID]);

        Livewire::test(\Modules\Minihouse\App\Filament\Resources\InvoiceResource\Pages\EditInvoice::class, ['record' => $invoice->getKey()])
            ->assertSuccessful()
            ->mountAction('generatePayOsQr')
            ->assertSee('Mở trang thanh toán')
            ->assertSee('thanh-toan-chuyen-khoan/minihouse/MH9');
    }
}
