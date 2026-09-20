<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\EntityDefinitionController;
use App\Http\Controllers\Api\EntityRecordController;
use App\Http\Controllers\Api\EntityRelationController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\FieldDefinitionController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\IncomingWebhookController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PipelineController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebhookEndpointController;
use Illuminate\Support\Facades\Route;

foreach ([
    'contact', 'organization', 'lead', 'deal', 'pipeline', 'task', 'activity', 'field_definition',
    'entity_definition', 'automation', 'integration', 'endpoint', 'file', 'importBatch', 'exportBatch', 'role',
    'user', 'relation', 'entityDefinition',
] as $parameter) {
    Route::pattern($parameter, '[0-9]+');
}

Route::prefix('v1')->group(function (): void {
    require __DIR__.'/knowledge.php';
    require __DIR__.'/support.php';
    Route::prefix('auth')->middleware('throttle:auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    Route::post('webhooks/incoming/{endpointId}/{token}', [IncomingWebhookController::class, 'receive'])
        ->middleware('throttle:webhooks')
        ->whereNumber('endpointId')
        ->where('token', '[A-Fa-f0-9]{64}');

    Route::middleware(['auth:sanctum', 'tenant.context'])->group(function (): void {
        Route::get('openapi.yaml', fn () => response()->file(base_path('docs/openapi.yaml'), ['Content-Type' => 'application/yaml']));

        Route::prefix('auth')->group(function (): void {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::put('password', [AuthController::class, 'updatePassword']);
        });

        Route::get('tenants', [TenantController::class, 'index']);
        Route::get('tenant', [TenantController::class, 'current']);
        Route::post('tenants', [TenantController::class, 'store'])->middleware('permission:settings.manage');

        Route::apiResource('contacts', ContactController::class)->except(['create', 'edit']);
        Route::apiResource('organizations', OrganizationController::class)->except(['create', 'edit']);
        Route::apiResource('leads', LeadController::class)->except(['create', 'edit']);
        Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->whereNumber('lead');
        Route::apiResource('deals', DealController::class)->except(['create', 'edit']);
        Route::apiResource('pipelines', PipelineController::class)->except(['create', 'edit']);
        Route::apiResource('tasks', TaskController::class)->except(['create', 'edit']);
        Route::apiResource('activities', ActivityController::class)->only(['index', 'store']);

        Route::apiResource('field-definitions', FieldDefinitionController::class)->except(['create', 'edit']);
        Route::apiResource('entity-definitions', EntityDefinitionController::class)->except(['create', 'edit']);
        Route::get('entities/{entityDefinition}/records', [EntityRecordController::class, 'index'])->whereNumber('entityDefinition');
        Route::post('entities/{entityDefinition}/records', [EntityRecordController::class, 'store'])->whereNumber('entityDefinition');
        Route::get('entities/{entityDefinition}/records/{id}', [EntityRecordController::class, 'show'])->whereNumber('id');
        Route::patch('entities/{entityDefinition}/records/{id}', [EntityRecordController::class, 'update'])->whereNumber('id');
        Route::delete('entities/{entityDefinition}/records/{id}', [EntityRecordController::class, 'destroy'])->whereNumber('id');
        Route::apiResource('relations', EntityRelationController::class)->only(['index', 'store', 'destroy']);

        Route::apiResource('automations', AutomationController::class)->except(['create', 'edit']);
        Route::apiResource('webhooks/endpoints', WebhookEndpointController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('files', FileController::class)->only(['index', 'store', 'destroy']);
        Route::get('files/{file}/download', [FileController::class, 'download'])->whereNumber('file');
        Route::post('imports', [ImportController::class, 'store']);
        Route::get('imports/{importBatch}', [ImportController::class, 'show'])->whereNumber('importBatch');
        Route::post('exports', [ExportController::class, 'store'])->middleware('throttle:exports');
        Route::get('exports/{exportBatch}', [ExportController::class, 'show'])->whereNumber('exportBatch');
        Route::apiResource('integrations', IntegrationController::class)->except(['create', 'edit']);

        Route::get('roles', [RoleController::class, 'index']);
        Route::post('roles', [RoleController::class, 'store']);
        Route::patch('roles/{role}', [RoleController::class, 'update'])->whereNumber('role');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role');
        Route::get('users', [UserController::class, 'index']);
        Route::patch('users/{user}/role', [UserController::class, 'updateRole'])->whereNumber('user');
        Route::get('audit-logs', [AuditController::class, 'index']);
    });
});
