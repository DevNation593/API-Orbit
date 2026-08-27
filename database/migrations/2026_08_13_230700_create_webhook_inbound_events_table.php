<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_inbound_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->string('idempotency_key', 190);
            $table->string('event', 120);
            $table->json('payload');
            $table->string('status', 30)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'endpoint_id', 'idempotency_key'], 'webhook_inbound_events_unique');
            $table->index(['tenant_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE webhook_inbound_events ALTER COLUMN payload TYPE jsonb USING payload::jsonb');
        }

        if (DB::getDriverName() === 'pgsql' && config('tenancy.rls_enabled')) {
            DB::statement('ALTER TABLE webhook_inbound_events ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE webhook_inbound_events FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS webhook_inbound_events_tenant_isolation ON webhook_inbound_events');
            DB::statement("CREATE POLICY webhook_inbound_events_tenant_isolation ON webhook_inbound_events USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS webhook_inbound_events_tenant_isolation ON webhook_inbound_events');
            DB::statement('ALTER TABLE webhook_inbound_events DISABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE webhook_inbound_events NO FORCE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE webhook_inbound_events ALTER COLUMN payload TYPE json USING payload::json');
        }

        Schema::dropIfExists('webhook_inbound_events');
    }
};
