<?php

use App\Http\Controllers\Api\SupportAgentController;
use App\Http\Controllers\Api\SupportCategoryController;
use App\Http\Controllers\Api\SupportQueueController;
use Illuminate\Support\Facades\Route;

Route::prefix('support')->middleware(['auth:sanctum', 'tenant.context'])->group(function (): void {
    foreach ([
        ['agents', 'agent', SupportAgentController::class],
        ['categories', 'category', SupportCategoryController::class],
        ['queues', 'queue', SupportQueueController::class],
    ] as [$resource, $parameter, $controller]) {
        Route::get($resource, [$controller, 'index']);
        Route::post($resource, [$controller, 'store']);
        Route::get($resource.'/{'.$parameter.'}', [$controller, 'show'])->whereNumber($parameter);
        Route::patch($resource.'/{'.$parameter.'}', [$controller, 'update'])->whereNumber($parameter);
        Route::delete($resource.'/{'.$parameter.'}', [$controller, 'destroy'])->whereNumber($parameter);
    }
    Route::put('queues/{queue}/agents', [SupportQueueController::class, 'replaceAgents'])->whereNumber('queue');
});
