<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_ingress_routes', function (Blueprint $table): void {
            $table->foreignId('endpoint_id')->primary()->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->index('tenant_id');
        });

        $rlsEnabled = config('tenancy.rls_enabled') && DB::getDriverName() === 'pgsql';
        if ($rlsEnabled) {
            DB::statement('ALTER TABLE webhook_endpoints NO FORCE ROW LEVEL SECURITY');
        }

        try {
            DB::statement('INSERT INTO webhook_ingress_routes (endpoint_id, tenant_id, created_at, updated_at) SELECT id, tenant_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM webhook_endpoints');
        } finally {
            if ($rlsEnabled) {
                DB::statement('ALTER TABLE webhook_endpoints FORCE ROW LEVEL SECURITY');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_ingress_routes');
    }
};
