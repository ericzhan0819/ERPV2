<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Models\Vehicle;

trait UsesCommissionAttributionFixtures
{
    protected User $defaultCommissionAgent;

    protected function setUpCommissionAttributionFixtures(): void
    {
        $this->defaultCommissionAgent = User::factory()->manager()->create();
    }

    /** @param array<string, mixed> $data */
    protected function addCommissionAttributionFixtures(string $uri, array $data): array
    {
        if ($uri === '/api/vehicles') {
            $data['purchase_agent_id'] ??= $this->defaultCommissionAgent->id;
        }

        if (preg_match('#^/api/vehicles/(\d+)/reserve$#', $uri)) {
            $actor = auth()->user();
            if ($actor instanceof User) {
                if (! $actor->isSales()) {
                    $data['sales_agent_id'] ??= $this->defaultCommissionAgent->id;
                }
            }
        }

        if (preg_match('#^/api/vehicles/(\d+)/close-sale$#', $uri, $matches)) {
            Vehicle::query()->whereKey((int) $matches[1])->whereNull('sales_agent_id')->update([
                'sales_agent_id' => $this->defaultCommissionAgent->id,
            ]);
        }

        return $data;
    }
}
