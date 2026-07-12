<?php

namespace Tests\Unit;

use App\Enums\BackupStatus;
use PHPUnit\Framework\TestCase;

/** Rusted -> My Mate backup-status mapping. */
class BackupStatusTest extends TestCase
{
    public function test_maps_rusted_success_variants_to_ok(): void
    {
        $this->assertSame(BackupStatus::Ok, BackupStatus::fromRusted('success'));
        $this->assertSame(BackupStatus::Ok, BackupStatus::fromRusted('ok'));
        $this->assertSame(BackupStatus::Ok, BackupStatus::fromRusted('changed'));
    }

    public function test_maps_unchanged_and_pending(): void
    {
        $this->assertSame(BackupStatus::Unchanged, BackupStatus::fromRusted('unchanged'));
        $this->assertSame(BackupStatus::Pending, BackupStatus::fromRusted('pending'));
    }

    public function test_unknown_or_null_status_is_failed_never_silently_ok(): void
    {
        $this->assertSame(BackupStatus::Failed, BackupStatus::fromRusted('failed'));
        $this->assertSame(BackupStatus::Failed, BackupStatus::fromRusted('weird'));
        $this->assertSame(BackupStatus::Failed, BackupStatus::fromRusted(null));
    }

    public function test_only_pending_is_in_progress(): void
    {
        $this->assertTrue(BackupStatus::Pending->inProgress());
        $this->assertFalse(BackupStatus::Ok->inProgress());
        $this->assertFalse(BackupStatus::Failed->inProgress());
    }
}
