<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReminderPageRegressionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_reminders_list_page_renders_even_with_a_staff_user_missing_fullname(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin, 'Need at least 1 super_admin user seeded to run this check.');

        // Reproduce the real production data condition that crashed the page: a staff user in
        // scope for the "Giao cho nhân viên" picker with a NULL fullname.
        $staffWithNoName = User::whereNull('fullname')->first();
        $this->assertNotNull($staffWithNoName, 'Expected the known cc490099-... user with NULL fullname to exist.');

        $this->actingAs($admin)
            ->get('/minihouse-admin/reminders')
            ->assertOk();
    }
}
