<?php

namespace Tests\Unit;

use Modules\TTLock\App\Filament\Pages\LockDetail;
use PHPUnit\Framework\TestCase;

class TTLockLockDetailTest extends TestCase
{
    public function test_title_is_safe_before_livewire_mount(): void
    {
        $page = new LockDetail();

        $this->assertSame('Khóa: #0', $page->getTitle());
    }
}
