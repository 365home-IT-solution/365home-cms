<?php

namespace Tests\Feature\Minihouse;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

class TenantRememberCookieTest extends TestCase
{
    use DatabaseTransactions;

    public function test_tenant_stays_logged_in_via_remember_cookie_without_otp_again(): void
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a', 'electric_unit_price' => 3500, 'water_unit_price' => 15000]);
        $room = Room::create(['building_id' => $building->id, 'code' => 'P1', 'price' => 3000000, 'status' => 'dang_thue']);
        $tenant = Tenant::create(['fullname' => 'T', 'phone' => '0911111111', 'room_id' => $room->id]);
        Contract::create(['room_id' => $room->id, 'tenant_id' => $tenant->id, 'start_date' => now()->subMonth(), 'monthly_price' => 3000000, 'deposit_amount' => 3000000, 'status' => 'active']);

        \Illuminate\Support\Facades\Cache::put('minihouse_tenant_otp:' . $tenant->phone, ['code' => '123456', 'attempts' => 0], now()->addMinutes(5));
        session(['minihouse_portal_login_phone' => $tenant->phone]);

        $verify = $this->post(route('minihouse.portal.login.verify.submit'), ['code' => '123456']);
        $verify->assertRedirect(route('minihouse.portal.dashboard'));

        // Extract the "remember" cookie Laravel just queued (long-lived, ~5 years), simulating that
        // the browser stored it.
        $rememberCookie = null;
        foreach ($verify->headers->getCookies() as $cookie) {
            if (str_starts_with($cookie->getName(), 'remember_tenant_')) {
                $rememberCookie = $cookie;
            }
        }
        $this->assertNotNull($rememberCookie, 'Expected a remember_tenant_* cookie to be queued on login.');
        echo "Remember cookie expiry: " . date('Y-m-d', $rememberCookie->getExpiresTime()) . " (issued today: " . date('Y-m-d') . ")\n";

        // Fresh test client call, WITHOUT the session cookie at all (simulating session expiry /
        // browser restart clearing the session cookie), only carrying the long-lived remember
        // cookie -> should auto re-login with NO new OTP needed.
        $dashboard = $this
            ->withUnencryptedCookie($rememberCookie->getName(), $rememberCookie->getValue())
            ->get(route('minihouse.portal.dashboard'));

        $dashboard->assertOk();
        $dashboard->assertSee('T');
    }
}
