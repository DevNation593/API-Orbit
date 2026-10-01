<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'catalog.view', 'catalog.manage',
        'pricing.view', 'pricing.manage',
        'quotes.view', 'quotes.create', 'quotes.update', 'quotes.delete', 'quotes.send', 'quotes.approve', 'quotes.accept',
        'approvals.view', 'approvals.manage', 'approvals.decide',
        'erp_sync.view', 'erp_sync.manage',
    ];

    private const TENANT_TABLES = [
        'currencies', 'product_categories', 'products', 'product_variants', 'taxes', 'product_tax',
        'discounts', 'bundles', 'bundle_items', 'price_lists', 'price_list_items', 'document_sequences',
        'approval_processes', 'approval_steps', 'approval_delegations', 'pricing_rules', 'discount_rules',
        'bundle_rules', 'product_dependencies', 'approval_rules', 'quotes', 'quote_items', 'quote_taxes',
        'quote_discounts', 'approval_requests', 'approval_decisions', 'approval_escalations', 'quote_approvals',
        'quote_activities', 'erp_syncs', 'erp_sync_logs',
    ];

    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->char('code', 3);
            $table->string('name', 100);
            $table->string('symbol', 12)->nullable();
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->decimal('exchange_rate', 24, 10)->default(1);
            $table->boolean('is_base')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'currencies_tenant_code_unique');
            $table->index(['tenant_id', 'active', 'is_base'], 'currencies_tenant_active_index');
        });
        DB::statement('CREATE UNIQUE INDEX currencies_one_base_per_tenant ON currencies (tenant_id) WHERE is_base');

        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'parent_id', 'position'], 'product_categories_tree_index');
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20)->default('product');
            $table->string('sku', 100);
            $table->string('name', 190);
            $table->text('description')->nullable();
            $table->string('unit_of_measure', 40)->default('unit');
            $table->decimal('base_price', 20, 6)->default(0);
            $table->decimal('cost', 20, 6)->nullable();
            $table->boolean('taxable')->default(true);
            $table->boolean('active')->default(true);
            $table->string('erp_product_id', 190)->nullable();
            $table->json('custom_fields')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'category_id', 'type', 'active'], 'products_catalog_index');
            $table->index(['tenant_id', 'erp_product_id'], 'products_erp_index');
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 100);
            $table->string('name', 190);
            $table->json('attributes')->nullable();
            $table->decimal('price_adjustment', 20, 6)->default(0);
            $table->decimal('cost', 20, 6)->nullable();
            $table->boolean('active')->default(true);
            $table->string('erp_product_id', 190)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'product_id', 'active'], 'product_variants_product_index');
            $table->index(['tenant_id', 'erp_product_id'], 'product_variants_erp_index');
        });

        Schema::create('taxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->string('code', 60);
            $table->string('name', 120);
            $table->string('calculation', 20)->default('percentage');
            $table->decimal('rate', 12, 6)->default(0);
            $table->boolean('inclusive')->default(false);
            $table->boolean('compound')->default(false);
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'taxes_tenant_code_unique');
            $table->index(['tenant_id', 'active', 'priority'], 'taxes_active_index');
        });

        Schema::create('product_tax', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['product_id', 'tax_id']);
            $table->index(['tenant_id', 'tax_id'], 'product_tax_tenant_index');
        });

        Schema::create('discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name', 160);
            $table->string('type', 20);
            $table->decimal('value', 20, 6);
            $table->decimal('minimum_subtotal', 20, 6)->nullable();
            $table->decimal('maximum_discount', 20, 6)->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->json('conditions')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'code'], 'discounts_tenant_code_unique');
            $table->index(['tenant_id', 'active', 'starts_at', 'ends_at'], 'discounts_availability_index');
        });

        Schema::create('bundles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 190);
            $table->text('description')->nullable();
            $table->string('pricing_method', 30)->default('fixed');
            $table->decimal('fixed_price', 20, 6)->nullable();
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bundle_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bundle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 20, 6)->default(1);
            $table->decimal('price_override', 20, 6)->nullable();
            $table->boolean('required')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'bundle_id', 'position'], 'bundle_items_lookup_index');
        });

        Schema::create('price_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->json('conditions')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'active', 'is_default', 'priority'], 'price_lists_selection_index');
        });

        Schema::create('price_list_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('minimum_quantity', 20, 6)->default(1);
            $table->decimal('unit_price', 20, 6);
            $table->decimal('compare_at_price', 20, 6)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'price_list_id', 'product_id', 'product_variant_id'], 'price_list_items_lookup_index');
        });

        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->string('prefix', 20)->default('Q');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(6);
            $table->timestamps();
            $table->unique(['tenant_id', 'document_type'], 'document_sequences_tenant_type_unique');
        });

        Schema::create('approval_processes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->string('approvable_type', 40);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('priority')->default(100);
            $table->json('conditions')->nullable();
            $table->string('match_type', 10)->default('all');
            $table->boolean('active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'approvable_type', 'active', 'priority'], 'approval_processes_selection_index');
        });

        Schema::create('approval_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_process_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('name', 160);
            $table->string('approver_type', 30);
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->string('approver_permission', 120)->nullable();
            $table->unsignedSmallInteger('minimum_approvals')->default(1);
            $table->string('decision_mode', 10)->default('any');
            $table->unsignedInteger('due_hours')->nullable();
            $table->foreignId('escalation_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('escalation_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->json('conditions')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'approval_process_id', 'position'], 'approval_steps_position_unique');
        });

        Schema::create('approval_delegations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('approvable_type', 40)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('active')->default(true);
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'from_user_id', 'active', 'starts_at', 'ends_at'], 'approval_delegations_active_index');
        });

        Schema::create('pricing_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->string('name', 160);
            $table->unsignedInteger('priority')->default(100);
            $table->string('target_type', 30)->default('all');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('conditions')->nullable();
            $table->string('match_type', 10)->default('all');
            $table->string('action_type', 30);
            $table->decimal('value', 20, 6);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name'], 'pricing_rules_tenant_name_unique');
            $table->index(['tenant_id', 'active', 'priority'], 'pricing_rules_active_index');
        });

        Schema::create('discount_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discount_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->string('name', 160);
            $table->unsignedInteger('priority')->default(100);
            $table->json('conditions')->nullable();
            $table->string('match_type', 10)->default('all');
            $table->string('type', 20);
            $table->decimal('value', 20, 6);
            $table->decimal('maximum_discount', 20, 6)->nullable();
            $table->boolean('cumulative')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name'], 'discount_rules_tenant_name_unique');
            $table->index(['tenant_id', 'active', 'priority'], 'discount_rules_active_index');
        });

        Schema::create('bundle_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bundle_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->unsignedInteger('priority')->default(100);
            $table->json('conditions')->nullable();
            $table->string('match_type', 10)->default('all');
            $table->decimal('minimum_quantity', 20, 6)->nullable();
            $table->decimal('maximum_quantity', 20, 6)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name'], 'bundle_rules_tenant_name_unique');
        });

        Schema::create('product_dependencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_product_id')->constrained('products')->cascadeOnDelete();
            $table->string('relation', 20);
            $table->decimal('minimum_quantity', 20, 6)->default(1);
            $table->json('conditions')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'product_id', 'related_product_id', 'relation'], 'product_dependencies_unique');
        });

        Schema::create('approval_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_process_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->unsignedInteger('priority')->default(100);
            $table->json('conditions')->nullable();
            $table->string('match_type', 10)->default('all');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name'], 'approval_rules_tenant_name_unique');
        });

        Schema::create('quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('number', 80);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('revision_of_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pdf_file_id')->nullable()->constrained('file_records')->nullOnDelete();
            $table->string('status', 30)->default('draft');
            $table->string('title', 190)->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->decimal('subtotal', 20, 6)->default(0);
            $table->decimal('discount_total', 20, 6)->default(0);
            $table->decimal('tax_total', 20, 6)->default(0);
            $table->decimal('grand_total', 20, 6)->default(0);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->json('billing_address')->nullable();
            $table->json('shipping_address')->nullable();
            $table->text('acceptance_token')->nullable();
            $table->char('acceptance_token_hash', 64)->nullable()->unique();
            $table->char('acceptance_idempotency_key_hash', 64)->nullable();
            $table->string('accepted_by_name', 190)->nullable();
            $table->string('accepted_by_email', 190)->nullable();
            $table->ipAddress('accepted_from_ip')->nullable();
            $table->string('erp_document_id', 190)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status', 'created_at'], 'quotes_tenant_status_index');
            $table->index(['tenant_id', 'deal_id', 'created_at'], 'quotes_deal_index');
            $table->index(['tenant_id', 'contact_id', 'created_at'], 'quotes_contact_index');
            $table->index(['tenant_id', 'organization_id', 'created_at'], 'quotes_organization_index');
        });

        Schema::create('quote_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_item_id')->nullable()->constrained('quote_items')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('item_type', 20)->default('product');
            $table->string('sku', 100)->nullable();
            $table->string('name', 190);
            $table->text('description')->nullable();
            $table->string('unit_of_measure', 40)->default('unit');
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_price', 20, 6);
            $table->decimal('subtotal', 20, 6);
            $table->decimal('discount_total', 20, 6)->default(0);
            $table->decimal('tax_total', 20, 6)->default(0);
            $table->decimal('total', 20, 6);
            $table->boolean('taxable')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'quote_id', 'position'], 'quote_items_quote_index');
            $table->index(['tenant_id', 'product_id'], 'quote_items_product_index');
        });

        Schema::create('quote_taxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 60)->nullable();
            $table->string('name', 120);
            $table->string('calculation', 20)->default('percentage');
            $table->decimal('rate', 12, 6)->default(0);
            $table->decimal('taxable_amount', 20, 6);
            $table->decimal('amount', 20, 6);
            $table->boolean('inclusive')->default(false);
            $table->boolean('compound')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'quote_id', 'quote_item_id'], 'quote_taxes_quote_index');
        });

        Schema::create('quote_discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('discount_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('discount_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 80)->nullable();
            $table->string('name', 160);
            $table->string('type', 20);
            $table->decimal('value', 20, 6);
            $table->decimal('amount', 20, 6);
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'quote_id', 'quote_item_id'], 'quote_discounts_quote_index');
        });

        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_process_id')->constrained()->restrictOnDelete();
            $table->nullableMorphs('approvable');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('current_step_position')->default(1);
            $table->timestamp('requested_at');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->json('context')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'due_at'], 'approval_requests_due_index');
            $table->index(['tenant_id', 'approvable_type', 'approvable_id'], 'approval_requests_subject_index');
        });

        Schema::create('approval_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_step_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delegated_from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 20);
            $table->text('comment')->nullable();
            $table->timestamp('decided_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'approval_request_id', 'approval_step_id', 'user_id'], 'approval_decisions_unique');
        });

        Schema::create('approval_escalations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_step_id')->constrained()->restrictOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->string('reason', 190);
            $table->timestamp('escalated_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'approval_request_id', 'approval_step_id'], 'approval_escalations_unique');
        });

        Schema::create('quote_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('requested_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'quote_id', 'approval_request_id'], 'quote_approvals_unique');
        });

        Schema::create('quote_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 60);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['tenant_id', 'quote_id', 'occurred_at'], 'quote_activities_timeline_index');
        });

        Schema::create('erp_syncs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('operation', 30)->default('upsert');
            $table->string('idempotency_key', 190);
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('external_id', 190)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key'], 'erp_syncs_idempotency_unique');
            $table->index(['tenant_id', 'entity_type', 'entity_id'], 'erp_syncs_entity_index');
            $table->index(['tenant_id', 'status', 'created_at'], 'erp_syncs_status_index');
        });

        Schema::create('erp_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('erp_sync_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('status', 20);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('request_summary')->nullable();
            $table->json('response_summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'erp_sync_id', 'attempt'], 'erp_sync_logs_attempt_index');
        });

        $this->createSoftDeleteUniqueIndexes();
        $this->installPermissions();
        $this->hardenPostgresTables();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TENANT_TABLES as $table) {
                DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            }
        }

        foreach (array_reverse(self::TENANT_TABLES) as $table) {
            Schema::dropIfExists($table);
        }

        $permissionIds = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('key', self::PERMISSIONS)->delete();
    }

    private function installPermissions(): void
    {
        $now = now();
        DB::table('permissions')->upsert(array_map(fn (string $key): array => [
            'key' => $key,
            'description' => str_replace('.', ' ', $key),
            'created_at' => $now,
            'updated_at' => $now,
        ], self::PERMISSIONS), ['key'], ['description', 'updated_at']);
        $permissionIds = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
        $roleIds = DB::table('roles')->where('is_system', true)
            ->orWhereIn('id', function ($query): void {
                $query->select('permission_role.role_id')->from('permission_role')
                    ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                    ->where('permissions.key', 'settings.manage');
            })->pluck('id');
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }

    private function createSoftDeleteUniqueIndexes(): void
    {
        foreach ([
            'product_categories_tenant_name_unique' => ['product_categories', 'tenant_id, name'],
            'products_tenant_sku_unique' => ['products', 'tenant_id, sku'],
            'product_variants_tenant_sku_unique' => ['product_variants', 'tenant_id, sku'],
            'bundles_product_unique' => ['bundles', 'tenant_id, product_id'],
            'price_lists_tenant_name_unique' => ['price_lists', 'tenant_id, name'],
            'approval_processes_name_version_unique' => ['approval_processes', 'tenant_id, name, version'],
            'quotes_tenant_number_version_unique' => ['quotes', 'tenant_id, number, version'],
        ] as $index => [$table, $columns]) {
            DB::statement("CREATE UNIQUE INDEX {$index} ON {$table} ({$columns}) WHERE deleted_at IS NULL");
        }
    }

    private function hardenPostgresTables(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ([
            'products' => ['custom_fields', 'metadata'], 'product_variants' => ['attributes'], 'taxes' => ['settings'],
            'discounts' => ['conditions'], 'bundles' => ['settings'], 'price_lists' => ['conditions'],
            'approval_processes' => ['conditions', 'settings'], 'approval_steps' => ['conditions'],
            'pricing_rules' => ['conditions'], 'discount_rules' => ['conditions'], 'bundle_rules' => ['conditions'],
            'product_dependencies' => ['conditions'], 'approval_rules' => ['conditions'],
            'quotes' => ['billing_address', 'shipping_address', 'metadata'], 'quote_items' => ['metadata'],
            'approval_requests' => ['context', 'snapshot'], 'approval_decisions' => ['metadata'],
            'approval_escalations' => ['metadata'], 'quote_activities' => ['metadata'], 'erp_syncs' => ['metadata'],
            'erp_sync_logs' => ['request_summary', 'response_summary'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE jsonb USING {$column}::jsonb");
            }
        }
        if (! config('tenancy.rls_enabled')) {
            return;
        }
        foreach (self::TENANT_TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_tenant_isolation ON {$table} USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)");
        }
    }
};
