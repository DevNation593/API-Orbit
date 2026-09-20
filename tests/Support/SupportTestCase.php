<?php

namespace Tests\Support;

use App\Models\SlaPolicy;
use App\Models\SupportAgent;
use App\Models\SupportQueue;
use App\Support\PermissionCatalog;
use App\Support\TenantContext;
use Tests\TestCase;

abstract class SupportTestCase extends TestCase
{
    protected function supportFixture(bool $withSla = true, array $permissions = PermissionCatalog::ALL): array
    {
        app(TenantContext::class)->clear();
        $client = $this->createTenantUser($permissions);
        app(TenantContext::class)->set((int) $client['tenant']->id);
        $agent = SupportAgent::create(['user_id' => $client['user']->id, 'is_active' => true]);
        $policy = $withSla ? SlaPolicy::create([
            'name' => 'Base', 'is_active' => true, 'pause_on_waiting_customer' => true,
        ]) : null;
        if ($policy !== null) {
            foreach (['LOW', 'MEDIUM', 'HIGH', 'URGENT'] as $priority) {
                $policy->rules()->create([
                    'priority' => $priority, 'first_response_minutes' => 60, 'resolution_minutes' => 240,
                ]);
            }
        }
        $queue = SupportQueue::create(['name' => 'Soporte', 'is_active' => true, 'sla_policy_id' => $policy?->id]);
        $queue->agents()->attach($agent->id, ['tenant_id' => $client['tenant']->id]);

        return $client + ['agent' => $agent, 'queue' => $queue, 'policy' => $policy];
    }
}
