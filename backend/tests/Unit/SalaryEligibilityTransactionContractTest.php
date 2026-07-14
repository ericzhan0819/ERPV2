<?php

namespace Tests\Unit;

use App\Services\SalaryEligibilityService;
use LogicException;
use Tests\TestCase;

class SalaryEligibilityTransactionContractTest extends TestCase
{
    public function test_confirmation_entry_rejects_calls_outside_database_transaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('transaction');

        app(SalaryEligibilityService::class)->assertPeriodEligible('2026-06');
    }
}
