<?php

use App\Jobs\SyncErpEntityJob;
use App\Models\ApprovalDecision;
use App\Models\ApprovalEscalation;
use App\Models\ApprovalRequest;
use App\Models\ErpSync;
use App\Models\Integration;
use App\Models\Quote;
use App\Models\QuoteActivity;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\ApprovalEngine;
use App\Services\Erp\ErpProviderManager;
use App\Services\QuotePdfService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('builds the catalog and calculates quote totals with decimal precision', function (): void {
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);

    $currency = $api->postJson('/api/v1/currencies', [
        'code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2,
        'exchange_rate' => '1.000000', 'active' => true,
    ])->assertCreated()->assertJsonPath('data.is_base', true)->json('data');
    $tax = $api->postJson('/api/v1/taxes', [
        'code' => 'IVA12', 'name' => 'IVA 12%', 'calculation' => 'percentage',
        'rate' => '12.000000', 'inclusive' => false, 'active' => true,
    ])->assertCreated()->json('data');
    $product = $api->postJson('/api/v1/products', [
        'currency_id' => $currency['id'], 'type' => 'product', 'sku' => 'LAPTOP-BASE',
        'name' => 'Laptop', 'base_price' => '100.000000', 'taxable' => true,
        'tax_ids' => [$tax['id']],
    ])->assertCreated()->assertJsonMissingPath('data.slug')->json('data');
    $variant = $api->postJson('/api/v1/products/'.$product['id'].'/variants', [
        'sku' => 'LAPTOP-16GB', 'name' => 'Laptop 16 GB',
        'attributes' => ['ram' => '16 GB'], 'price_adjustment' => '10.000000',
    ])->assertCreated()->json('data');
    $lineDiscount = $api->postJson('/api/v1/discounts', [
        'code' => 'LINE10', 'name' => 'Line discount', 'type' => 'percentage',
        'value' => '10.000000', 'active' => true,
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/discounts', [
        'code' => 'QUOTE5', 'name' => 'Quote discount', 'type' => 'percentage',
        'value' => '5.000000', 'active' => true,
    ])->assertCreated();
    $priceList = $api->postJson('/api/v1/price-lists', [
        'currency_id' => $currency['id'], 'name' => 'Volume pricing', 'active' => true,
        'items' => [[
            'product_id' => $product['id'], 'product_variant_id' => $variant['id'],
            'minimum_quantity' => '2.000000', 'unit_price' => '90.000000', 'active' => true,
        ]],
    ])->assertCreated()->json('data');

    $quote = $api->postJson('/api/v1/quotes', [
        'currency_id' => $currency['id'], 'price_list_id' => $priceList['id'],
        'title' => 'Precise quote', 'discount_codes' => ['quote5'],
        'items' => [[
            'product_id' => $product['id'], 'product_variant_id' => $variant['id'],
            'quantity' => '2.000000', 'discount_ids' => [$lineDiscount['id']],
        ]],
    ])->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.subtotal', '180.000000')
        ->assertJsonPath('data.discount_total', '26.100000')
        ->assertJsonPath('data.tax_total', '18.468000')
        ->assertJsonPath('data.grand_total', '172.368000')
        ->assertJsonPath('data.items.0.unit_price', '90.000000')
        ->assertJsonPath('data.items.0.metadata.price_source', 'price_list')
        ->assertJsonMissingPath('data.slug')
        ->json('data');

    expect(Str::isUuid($quote['public_id']))->toBeTrue();
    $api->patchJson('/api/v1/quotes/'.$quote['id'], ['notes' => 'Updated safely'])
        ->assertOk()->assertJsonPath('data.grand_total', '172.368000');
    $api->postJson('/api/v1/quotes/'.$quote['id'].'/submit')
        ->assertOk()->assertJsonPath('data.status', 'approved');
    $revision = $api->postJson('/api/v1/quotes/'.$quote['id'].'/revise')
        ->assertCreated()->assertJsonPath('data.version', 2)->json('data');
    expect($revision['number'])->toBe($quote['number'])
        ->and($revision['public_id'])->not->toBe($quote['public_id']);
    $duplicate = $api->postJson('/api/v1/quotes/'.$quote['id'].'/duplicate')
        ->assertCreated()->assertJsonPath('data.version', 1)->json('data');
    expect($duplicate['number'])->not->toBe($quote['number']);
    $api->deleteJson('/api/v1/quotes/'.$duplicate['id'])->assertOk();
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'quote_deleted', 'entity_type' => 'quotes', 'entity_id' => (string) $duplicate['id'],
    ]);
});

it('enforces CPQ pricing, automatic discounts, dependencies and bundle rules', function (): void {
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $currency = $api->postJson('/api/v1/currencies', [
        'code' => 'USD', 'name' => 'US Dollar', 'exchange_rate' => '1',
    ])->assertCreated()->json('data');
    $main = $api->postJson('/api/v1/products', [
        'currency_id' => $currency['id'], 'type' => 'product', 'sku' => 'MAIN',
        'name' => 'Main product', 'base_price' => '100',
    ])->assertCreated()->json('data');
    $required = $api->postJson('/api/v1/products', [
        'currency_id' => $currency['id'], 'type' => 'service', 'sku' => 'SETUP',
        'name' => 'Required setup', 'base_price' => '20',
    ])->assertCreated()->json('data');
    $bundleProduct = $api->postJson('/api/v1/products', [
        'currency_id' => $currency['id'], 'type' => 'bundle', 'sku' => 'PACK',
        'name' => 'Commercial pack', 'base_price' => '0',
    ])->assertCreated()->json('data');

    $api->postJson('/api/v1/cpq/rules/pricing', [
        'name' => 'Volume uplift', 'target_type' => 'product', 'target_id' => $main['id'],
        'action_type' => 'percentage_adjustment', 'value' => '10',
        'conditions' => [['field' => 'quantity', 'operator' => 'gte', 'value' => '2']],
        'active' => true,
    ])->assertCreated();
    $api->postJson('/api/v1/cpq/rules/discount', [
        'name' => 'Automatic volume discount', 'type' => 'percentage', 'value' => '10',
        'conditions' => [['field' => 'subtotal', 'operator' => 'gte', 'value' => '200']],
        'cumulative' => false, 'active' => true,
    ])->assertCreated();
    $api->postJson('/api/v1/product-dependencies', [
        'product_id' => $main['id'], 'related_product_id' => $required['id'],
        'relation' => 'requires', 'minimum_quantity' => '1', 'active' => true,
    ])->assertCreated();

    $api->postJson('/api/v1/quotes', [
        'currency_id' => $currency['id'],
        'items' => [['product_id' => $main['id'], 'quantity' => '2']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['items']);

    $quote = $api->postJson('/api/v1/quotes', [
        'currency_id' => $currency['id'],
        'items' => [
            ['product_id' => $main['id'], 'quantity' => '2'],
            ['product_id' => $required['id'], 'quantity' => '1'],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.subtotal', '240.000000')
        ->assertJsonPath('data.discount_total', '24.000000')
        ->assertJsonPath('data.grand_total', '216.000000')
        ->assertJsonPath('data.items.0.unit_price', '110.000000')
        ->json('data');
    expect($quote['items'][0]['metadata']['pricing_rules'])->toHaveCount(1);

    $bundle = $api->postJson('/api/v1/bundles', [
        'product_id' => $bundleProduct['id'], 'name' => 'Commercial pack',
        'pricing_method' => 'sum_components',
        'items' => [
            ['product_id' => $main['id'], 'quantity' => '1', 'required' => true],
            ['product_id' => $required['id'], 'quantity' => '2', 'required' => true],
        ],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/cpq/rules/bundle', [
        'name' => 'Pack quantity limit', 'bundle_id' => $bundle['id'],
        'minimum_quantity' => '1', 'maximum_quantity' => '3', 'active' => true,
    ])->assertCreated();
    $api->postJson('/api/v1/quotes', [
        'currency_id' => $currency['id'],
        'items' => [['product_id' => $bundleProduct['id'], 'quantity' => '4']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['items']);
    $api->postJson('/api/v1/quotes', [
        'currency_id' => $currency['id'],
        'items' => [['product_id' => $bundleProduct['id'], 'quantity' => '1']],
    ])->assertCreated()
        ->assertJsonPath('data.items.0.unit_price', '140.000000')
        ->assertJsonPath('data.grand_total', '140.000000');
});

it('runs multi-step approvals with escalation, delegation and replay protection', function (): void {
    $client = $this->createTenantUser();
    $membership = TenantUser::query()->where('tenant_id', $client['tenant']->id)
        ->where('user_id', $client['user']->id)->firstOrFail();
    $approver = User::factory()->create();
    TenantUser::create([
        'tenant_id' => $client['tenant']->id, 'user_id' => $approver->id,
        'role_id' => $membership->role_id, 'status' => 'active', 'joined_at' => now(),
    ]);
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $currency = $api->postJson('/api/v1/currencies', [
        'code' => 'USD', 'name' => 'US Dollar', 'exchange_rate' => '1',
    ])->assertCreated()->json('data');
    $product = $api->postJson('/api/v1/products', [
        'currency_id' => $currency['id'], 'type' => 'service', 'sku' => 'CONSULTING',
        'name' => 'Consulting', 'base_price' => '500',
    ])->assertCreated()->json('data');
    $process = $api->postJson('/api/v1/approval-processes', [
        'name' => 'Quote approval', 'approvable_type' => 'quote', 'active' => true,
        'steps' => [
            [
                'name' => 'Commercial approval', 'position' => 1, 'approver_type' => 'user',
                'approver_user_id' => $approver->id, 'due_hours' => 1,
                'escalation_user_id' => $client['user']->id,
            ],
            [
                'name' => 'Final approval', 'position' => 2, 'approver_type' => 'user',
                'approver_user_id' => $approver->id,
            ],
        ],
    ])->assertCreated()->json('data');
    expect($process['steps'])->toHaveCount(2);
    $quote = $api->postJson('/api/v1/quotes', [
        'currency_id' => $currency['id'],
        'items' => [['product_id' => $product['id'], 'quantity' => '1']],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/quotes/'.$quote['id'].'/submit')
        ->assertOk()->assertJsonPath('data.status', 'pending_approval');
    $approval = ApprovalRequest::query()->sole();
    $approval->update(['due_at' => now()->subMinute()]);

    $context = app(TenantContext::class);
    $context->set((int) $client['tenant']->id);
    expect(app(ApprovalEngine::class)->escalateDue())->toBe(1);
    $context->clear();
    expect(ApprovalEscalation::query()->where('approval_request_id', $approval->id)->count())->toBe(1);

    $api->postJson('/api/v1/approval-requests/'.$approval->id.'/decide', [
        'decision' => 'approve', 'comment' => 'Approved after escalation',
    ])->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.current_step_position', 2);
    $delegation = $api->postJson('/api/v1/approval-delegations', [
        'from_user_id' => $approver->id, 'to_user_id' => $client['user']->id,
        'approvable_type' => 'quote', 'starts_at' => now()->subMinute()->toIso8601String(),
        'ends_at' => now()->addDay()->toIso8601String(), 'reason' => 'Coverage',
    ])->assertCreated()->json('data');
    expect($delegation['active'])->toBeTrue();
    $api->postJson('/api/v1/approval-requests/'.$approval->id.'/decide', [
        'decision' => 'approve', 'comment' => 'Approved as delegate',
    ])->assertOk()->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('meta.replayed', false);
    $api->postJson('/api/v1/approval-requests/'.$approval->id.'/decide', [
        'decision' => 'approve', 'comment' => 'Approved as delegate',
    ])->assertOk()->assertJsonPath('meta.replayed', true);

    expect(Quote::query()->findOrFail($quote['id'])->status)->toBe('approved')
        ->and(ApprovalDecision::query()->where('delegated_from_user_id', $approver->id)->count())->toBe(1);

    $cancelledQuote = $api->postJson('/api/v1/quotes', [
        'currency_id' => $currency['id'],
        'items' => [['product_id' => $product['id'], 'quantity' => '1']],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/quotes/'.$cancelledQuote['id'].'/submit')
        ->assertOk()->assertJsonPath('data.status', 'pending_approval');
    $cancelledApproval = ApprovalRequest::query()->where('approvable_id', $cancelledQuote['id'])
        ->where('status', 'pending')->sole();
    $api->postJson('/api/v1/quotes/'.$cancelledQuote['id'].'/cancel', ['reason' => 'Customer withdrew'])
        ->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->assertDatabaseHas('approval_requests', ['id' => $cancelledApproval->id, 'status' => 'cancelled']);
    $this->assertDatabaseHas('quote_approvals', ['approval_request_id' => $cancelledApproval->id, 'status' => 'cancelled']);
});

it('keeps permission-based approvers inside the active tenant', function (): void {
    $client = $this->createTenantUser();
    $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $currency = $api->postJson('/api/v1/currencies', [
        'code' => 'USD', 'name' => 'US Dollar', 'exchange_rate' => '1',
    ])->assertCreated()->json('data');
    $product = $api->postJson('/api/v1/products', [
        'currency_id' => $currency['id'], 'type' => 'service', 'sku' => 'TENANT-APPROVAL',
        'name' => 'Tenant approval', 'base_price' => '50',
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/approval-processes', [
        'name' => 'Permission approval', 'approvable_type' => 'quote', 'active' => true,
        'steps' => [[
            'name' => 'Tenant approvers', 'position' => 1, 'approver_type' => 'permission',
            'approver_permission' => 'approvals.decide', 'decision_mode' => 'all',
        ]],
    ])->assertCreated();
    $quote = $api->postJson('/api/v1/quotes', [
        'currency_id' => $currency['id'],
        'items' => [['product_id' => $product['id'], 'quantity' => '1']],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/quotes/'.$quote['id'].'/submit')
        ->assertOk()->assertJsonPath('data.status', 'pending_approval');
    $approval = ApprovalRequest::query()->sole();
    $api->postJson('/api/v1/approval-requests/'.$approval->id.'/decide', [
        'decision' => 'approve',
    ])->assertOk()->assertJsonPath('data.status', 'approved');
});

it('generates a private PDF and accepts a public quote idempotently without slugs', function (): void {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $currency = $api->postJson('/api/v1/currencies', [
        'code' => 'USD', 'name' => 'US Dollar', 'exchange_rate' => '1',
    ])->assertCreated()->json('data');
    $product = $api->postJson('/api/v1/products', [
        'currency_id' => $currency['id'], 'type' => 'service', 'sku' => 'PDF-SERVICE',
        'name' => 'PDF service', 'base_price' => '80',
    ])->assertCreated()->json('data');
    $quoteData = $api->postJson('/api/v1/quotes', [
        'currency_id' => $currency['id'], 'title' => 'Public quote',
        'items' => [['product_id' => $product['id'], 'quantity' => '1']],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/quotes/'.$quoteData['id'].'/submit')
        ->assertOk()->assertJsonPath('data.status', 'approved');

    $context = app(TenantContext::class);
    $context->set((int) $client['tenant']->id);
    $this->actingAs($client['user']);
    $quote = Quote::query()->findOrFail($quoteData['id']);
    $file = app(QuotePdfService::class)->generate($quote);
    Storage::disk('local')->assertExists($file->path);
    expect(Storage::disk('local')->get($file->path))->toStartWith('%PDF-');
    $token = str_repeat('a', 64);
    $quote->update([
        'status' => 'sent', 'sent_at' => now(), 'acceptance_token' => $token,
        'acceptance_token_hash' => hash('sha256', $token),
    ]);
    $context->clear();

    $this->getJson('/api/v1/public/quotes/'.$quote->public_id.'?token='.$token)
        ->assertOk()->assertJsonPath('data.status', 'viewed')
        ->assertJsonPath('data.pdf_available', true)
        ->assertJsonMissingPath('data.slug')
        ->assertJsonMissingPath('data.acceptance_token');
    $decision = [
        'token' => $token, 'name' => 'Ada Customer', 'email' => 'ada@example.com',
        'comment' => 'Accepted',
    ];
    $this->postJson('/api/v1/public/quotes/'.$quote->public_id.'/accept', $decision)
        ->assertUnprocessable()->assertJsonValidationErrors(['idempotency_key']);
    $this->withHeader('Idempotency-Key', 'public-quote-decision-0001')
        ->postJson('/api/v1/public/quotes/'.$quote->public_id.'/accept', $decision)
        ->assertOk()->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('meta.replayed', false);
    $this->postJson('/api/v1/public/quotes/'.$quote->public_id.'/accept', $decision)
        ->assertOk()->assertJsonPath('meta.replayed', true);
    $this->withHeader('Idempotency-Key', 'public-quote-decision-0002')
        ->postJson('/api/v1/public/quotes/'.$quote->public_id.'/accept', $decision)
        ->assertUnprocessable()->assertJsonValidationErrors(['status']);

    expect(QuoteActivity::query()->where('quote_id', $quote->id)->where('type', 'quote.accepted')->count())->toBe(1);
});

it('queues ERP syncs idempotently and sends a versioned HTTP contract', function (): void {
    Queue::fake();
    Http::fake(fn (HttpRequest $request) => Http::response([
        'data' => ['id' => 'ERP-PRODUCT-100', 'status' => 'synchronized'],
    ], 200));
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $currency = $api->postJson('/api/v1/currencies', [
        'code' => 'USD', 'name' => 'US Dollar', 'exchange_rate' => '1',
    ])->assertCreated()->json('data');
    $product = $api->postJson('/api/v1/products', [
        'currency_id' => $currency['id'], 'type' => 'product', 'sku' => 'ERP-100',
        'name' => 'ERP product', 'base_price' => '125.75',
    ])->assertCreated()->json('data');
    $integration = $api->postJson('/api/v1/integrations', [
        'provider' => 'vantex_erp', 'name' => 'Vantex ERP',
        'credentials' => ['api_token' => str_repeat('x', 32)],
        'settings' => ['base_url' => 'https://8.8.8.8'],
    ])->assertCreated()->json('data');
    Integration::query()->findOrFail($integration['id'])->update(['status' => 'active']);

    $sync = $api->postJson('/api/v1/products/'.$product['id'].'/sync-erp')
        ->assertStatus(202)->assertJsonPath('data.status', 'queued')->json('data');
    $api->postJson('/api/v1/products/'.$product['id'].'/sync-erp')
        ->assertStatus(202)->assertJsonPath('data.id', $sync['id']);
    expect(ErpSync::query()->count())->toBe(1);
    Queue::assertPushed(SyncErpEntityJob::class, 1);

    (new SyncErpEntityJob((int) $client['tenant']->id, (int) $sync['id']))
        ->handle(app(ErpProviderManager::class));
    $completed = ErpSync::query()->findOrFail($sync['id']);
    expect($completed->status)->toBe('completed')
        ->and($completed->external_id)->toBe('ERP-PRODUCT-100')
        ->and($completed->logs()->count())->toBe(1);
    Http::assertSent(function (HttpRequest $request) use ($completed, $product): bool {
        return $request->url() === 'https://8.8.8.8/api/v1/crm/products/upsert'
            && $request->hasHeader('Idempotency-Key', $completed->idempotency_key)
            && $request['crm_id'] === $product['id']
            && $request['base_price'] === '125.750000';
    });
});

it('isolates API-4 resources by tenant and enforces permissions', function (): void {
    $owner = $this->createTenantUser();
    $foreign = $this->createTenantUser();
    $ownerApi = $this->withToken($owner['token'])->withHeader('X-Tenant-ID', (string) $owner['tenant']->id);
    $currency = $ownerApi->postJson('/api/v1/currencies', [
        'code' => 'USD', 'name' => 'US Dollar', 'exchange_rate' => '1',
    ])->assertCreated()->json('data');
    $product = $ownerApi->postJson('/api/v1/products', [
        'currency_id' => $currency['id'], 'type' => 'product', 'sku' => 'PRIVATE',
        'name' => 'Private product', 'base_price' => '10',
    ])->assertCreated()->json('data');

    $this->app['auth']->forgetGuards();
    $foreignApi = $this->withToken($foreign['token'])->withHeader('X-Tenant-ID', (string) $foreign['tenant']->id);
    $foreignApi->getJson('/api/v1/products/'.$product['id'])->assertNotFound();
    $foreignApi->postJson('/api/v1/quotes', [
        'currency_id' => $currency['id'],
        'items' => [['product_id' => $product['id'], 'quantity' => '1']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['currency_id', 'items.0.product_id']);

    $restricted = $this->createTenantUser(['catalog.view']);
    $this->app['auth']->forgetGuards();
    $this->withToken($restricted['token'])->withHeader('X-Tenant-ID', (string) $restricted['tenant']->id)
        ->postJson('/api/v1/currencies', ['code' => 'EUR', 'name' => 'Euro'])
        ->assertForbidden();

    $this->app['auth']->forgetGuards();
    $ownerApi = $this->withToken($owner['token'])->withHeader('X-Tenant-ID', (string) $owner['tenant']->id);
    $ownerApi->deleteJson('/api/v1/products/'.$product['id'])->assertOk();
    $ownerApi->postJson('/api/v1/products', [
        'currency_id' => $currency['id'], 'type' => 'product', 'sku' => 'PRIVATE',
        'name' => 'Recreated product', 'base_price' => '10',
    ])->assertCreated();

    expect(Schema::hasColumn('products', 'slug'))->toBeFalse()
        ->and(Schema::hasColumn('quotes', 'slug'))->toBeFalse()
        ->and(Schema::hasColumn('quotes', 'public_id'))->toBeTrue();
});

it('enforces partial-update invariants and persists quote expiration', function (): void {
    $client = $this->createTenantUser();
    $api = $this->withToken($client['token'])->withHeader('X-Tenant-ID', (string) $client['tenant']->id);
    $usd = $api->postJson('/api/v1/currencies', [
        'code' => 'USD', 'name' => 'US Dollar', 'exchange_rate' => '1',
    ])->assertCreated()->json('data');
    $eur = $api->postJson('/api/v1/currencies', [
        'code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => '0.90',
    ])->assertCreated()->json('data');
    $api->patchJson('/api/v1/currencies/'.$usd['id'], ['exchange_rate' => '1.10'])
        ->assertUnprocessable()->assertJsonValidationErrors(['exchange_rate']);
    $api->patchJson('/api/v1/currencies/'.$usd['id'], ['is_base' => false])
        ->assertUnprocessable()->assertJsonValidationErrors(['is_base']);

    $tax = $api->postJson('/api/v1/taxes', [
        'currency_id' => $eur['id'], 'code' => 'FIXED-FEE', 'name' => 'Fixed fee',
        'calculation' => 'percentage', 'rate' => '5',
    ])->assertCreated()->json('data');
    $api->patchJson('/api/v1/taxes/'.$tax['id'], ['calculation' => 'fixed'])
        ->assertOk()->assertJsonPath('data.currency_id', $eur['id']);
    $api->deleteJson('/api/v1/currencies/'.$eur['id'])
        ->assertUnprocessable()->assertJsonValidationErrors(['currency']);

    $discount = $api->postJson('/api/v1/discounts', [
        'code' => 'PATCH10', 'name' => 'Patch discount', 'type' => 'percentage', 'value' => '10',
    ])->assertCreated()->json('data');
    $api->patchJson('/api/v1/discounts/'.$discount['id'], ['value' => '101'])
        ->assertUnprocessable()->assertJsonValidationErrors(['value']);

    $main = $api->postJson('/api/v1/products', [
        'currency_id' => $usd['id'], 'type' => 'service', 'sku' => 'PATCH-MAIN',
        'name' => 'Patch main', 'base_price' => '25',
    ])->assertCreated()->json('data');
    $related = $api->postJson('/api/v1/products', [
        'currency_id' => $usd['id'], 'type' => 'service', 'sku' => 'PATCH-RELATED',
        'name' => 'Patch related', 'base_price' => '5',
    ])->assertCreated()->json('data');
    $rule = $api->postJson('/api/v1/cpq/rules/pricing', [
        'name' => 'Patchable pricing', 'target_type' => 'product', 'target_id' => $main['id'],
        'action_type' => 'set_price', 'value' => '20',
    ])->assertCreated()->json('data');
    $api->patchJson('/api/v1/cpq/rules/pricing/'.$rule['id'], ['name' => 'Patched pricing'])
        ->assertOk()->assertJsonPath('data.name', 'Patched pricing');
    $api->patchJson('/api/v1/cpq/rules/pricing/'.$rule['id'], ['target_id' => 999999])
        ->assertUnprocessable()->assertJsonValidationErrors(['target_id']);

    $dependency = $api->postJson('/api/v1/product-dependencies', [
        'product_id' => $main['id'], 'related_product_id' => $related['id'], 'relation' => 'requires',
    ])->assertCreated()->json('data');
    $api->patchJson('/api/v1/product-dependencies/'.$dependency['id'], ['related_product_id' => $main['id']])
        ->assertUnprocessable()->assertJsonValidationErrors(['related_product_id']);
    $api->deleteJson('/api/v1/products/'.$related['id'])
        ->assertUnprocessable()->assertJsonValidationErrors(['product']);

    $bundleProduct = $api->postJson('/api/v1/products', [
        'currency_id' => $usd['id'], 'type' => 'bundle', 'sku' => 'PATCH-BUNDLE',
        'name' => 'Patch bundle', 'base_price' => '0',
    ])->assertCreated()->json('data');
    $bundle = $api->postJson('/api/v1/bundles', [
        'product_id' => $bundleProduct['id'], 'name' => 'Patch bundle', 'pricing_method' => 'sum_components',
        'items' => [['product_id' => $main['id'], 'quantity' => '1']],
    ])->assertCreated()->json('data');
    $api->patchJson('/api/v1/bundles/'.$bundle['id'], ['pricing_method' => 'fixed'])
        ->assertUnprocessable()->assertJsonValidationErrors(['fixed_price']);

    $approver = User::factory()->create();
    $membership = TenantUser::query()->where('tenant_id', $client['tenant']->id)
        ->where('user_id', $client['user']->id)->firstOrFail();
    TenantUser::create([
        'tenant_id' => $client['tenant']->id, 'user_id' => $approver->id,
        'role_id' => $membership->role_id, 'status' => 'active', 'joined_at' => now(),
    ]);
    $delegation = $api->postJson('/api/v1/approval-delegations', [
        'from_user_id' => $client['user']->id, 'to_user_id' => $approver->id,
        'starts_at' => now()->subMinute()->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String(),
    ])->assertCreated()->json('data');
    $api->patchJson('/api/v1/approval-delegations/'.$delegation['id'], ['to_user_id' => $client['user']->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['to_user_id']);

    $today = $api->postJson('/api/v1/quotes', [
        'currency_id' => $usd['id'], 'valid_until' => today()->format('Y-m-d'),
        'items' => [
            ['product_id' => $main['id'], 'quantity' => '1'],
            ['product_id' => $related['id'], 'quantity' => '1'],
        ],
    ])->assertCreated()->json('data');
    $api->postJson('/api/v1/quotes/'.$today['id'].'/submit')
        ->assertOk()->assertJsonPath('data.status', 'approved');

    $expired = $api->postJson('/api/v1/quotes', [
        'currency_id' => $usd['id'],
        'items' => [
            ['product_id' => $main['id'], 'quantity' => '1'],
            ['product_id' => $related['id'], 'quantity' => '1'],
        ],
    ])->assertCreated()->json('data');
    Quote::query()->whereKey($expired['id'])->update(['valid_until' => today()->subDay()]);
    $api->postJson('/api/v1/quotes/'.$expired['id'].'/submit')
        ->assertUnprocessable()->assertJsonValidationErrors(['valid_until']);
    $this->assertDatabaseHas('quotes', ['id' => $expired['id'], 'status' => 'expired']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'quote_expired', 'entity_id' => (string) $expired['id']]);
});
