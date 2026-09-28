<?php
namespace Tests\Feature\Minihouse;
use App\Models\User;
use Tests\TestCase;
class TmpSortTest extends TestCase {
    public function test_sort() {
        $this->withoutExceptionHandling();
        auth()->shouldUse('web');
        $this->actingAs(User::role('super_admin')->first(), 'web');
        $this->get('/minihouse/admin/rooms?tableSortColumn=code&tableSortDirection=asc')->assertOk();
    }
}
