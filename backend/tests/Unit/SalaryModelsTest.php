<?php

namespace Tests\Unit;

use App\Models\CommissionPlan;
use App\Models\CommissionPlanTier;
use App\Models\SalaryPeriod;
use App\Models\SalaryProfile;
use App\Models\SalarySettlement;
use App\Models\SalarySettlementItem;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Tests\TestCase;

class SalaryModelsTest extends TestCase
{
    public function test_salary_status_and_item_type_constants_are_centralized(): void
    {
        $this->assertSame(['draft', 'confirmed', 'paid'], SalaryPeriod::STATUSES);
        $this->assertCount(8, SalarySettlementItem::TYPES);
        $this->assertContains('purchase_bonus', SalarySettlementItem::AUTOMATIC_TYPES);
        $this->assertContains('manual_deduction', SalarySettlementItem::MANUAL_TYPES);
    }

    public function test_required_relationships_are_declared(): void
    {
        $this->assertInstanceOf(HasOne::class, (new User)->salaryProfile());
        $this->assertInstanceOf(HasMany::class, (new User)->salarySettlements());
        $this->assertInstanceOf(HasMany::class, (new User)->purchaseAgentVehicles());
        $this->assertInstanceOf(HasMany::class, (new User)->salesAgentVehicles());
        $this->assertInstanceOf(BelongsTo::class, (new Vehicle)->purchaseAgent());
        $this->assertInstanceOf(BelongsTo::class, (new Vehicle)->salesAgent());
        $this->assertInstanceOf(HasMany::class, (new Vehicle)->salarySettlementItems());
        $this->assertInstanceOf(BelongsTo::class, (new SalaryProfile)->user());
        $this->assertInstanceOf(HasMany::class, (new CommissionPlan)->tiers());
        $this->assertInstanceOf(HasMany::class, (new CommissionPlan)->salaryPeriods());
        $this->assertInstanceOf(BelongsTo::class, (new CommissionPlanTier)->commissionPlan());
        $this->assertInstanceOf(BelongsTo::class, (new SalaryPeriod)->plan());
        $this->assertInstanceOf(HasMany::class, (new SalaryPeriod)->settlements());
        $this->assertInstanceOf(BelongsTo::class, (new SalarySettlement)->period());
        $this->assertInstanceOf(HasMany::class, (new SalarySettlement)->items());
        $this->assertInstanceOf(BelongsTo::class, (new SalarySettlementItem)->settlement());
    }
}
