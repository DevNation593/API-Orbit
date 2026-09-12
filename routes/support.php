<?php

use App\Http\Controllers\Api\SlaCalendarController;
use App\Http\Controllers\Api\SlaPolicyController;
use App\Http\Controllers\Api\SupportAgentController;
use App\Http\Controllers\Api\SupportCategoryController;
use App\Http\Controllers\Api\SupportQueueController;
use App\Http\Controllers\Api\TicketController;
use Illuminate\Support\Facades\Route;

Route::prefix('support')->middleware(['auth:sanctum', 'tenant.context'])->group(function (): void {
    foreach ([
        ['agents', 'agent', SupportAgentController::class],
        ['categories', 'category', SupportCategoryController::class],
        ['queues', 'queue', SupportQueueController::class],
        ['sla-calendars', 'calendar', SlaCalendarController::class],
        ['sla-policies', 'policy', SlaPolicyController::class],
    ] as [$resource, $parameter, $controller]) {
        Route::get($resource, [$controller, 'index']);
        Route::post($resource, [$controller, 'store']);
        Route::get($resource.'/{'.$parameter.'}', [$controller, 'show'])->whereNumber($parameter);
        Route::patch($resource.'/{'.$parameter.'}', [$controller, 'update'])->whereNumber($parameter);
        Route::delete($resource.'/{'.$parameter.'}', [$controller, 'destroy'])->whereNumber($parameter);
    }
    Route::put('queues/{queue}/agents', [SupportQueueController::class, 'replaceAgents'])->whereNumber('queue');
});

Route::prefix('tickets')->middleware(['auth:sanctum', 'tenant.context'])->group(function (): void {
    Route::get('/', [TicketController::class, 'index']);
    Route::post('/', [TicketController::class, 'store']);
    Route::get('{ticket}', [TicketController::class, 'show'])->whereNumber('ticket');
    Route::patch('{ticket}', [TicketController::class, 'update'])->whereNumber('ticket');
    Route::post('{ticket}/assign', [TicketController::class, 'assign'])->whereNumber('ticket');
    Route::post('{ticket}/status', [TicketController::class, 'changeStatus'])->whereNumber('ticket');
    Route::get('{ticket}/comments', [TicketController::class, 'comments'])->whereNumber('ticket');
    Route::post('{ticket}/comments', [TicketController::class, 'storeComment'])->whereNumber('ticket');
    Route::get('{ticket}/sla', [TicketController::class, 'sla'])->whereNumber('ticket');
});
