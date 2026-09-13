<?php

use App\Http\Controllers\Api\KnowledgeArticleController;
use App\Http\Controllers\Api\KnowledgeBaseController;
use App\Http\Controllers\Api\KnowledgeCategoryController;
use App\Http\Controllers\Api\KnowledgeTagController;
use Illuminate\Support\Facades\Route;

Route::prefix('knowledge')->middleware(['auth:sanctum', 'tenant.context'])->group(function (): void {
    Route::get('articles', [KnowledgeArticleController::class, 'index']);
    Route::post('articles', [KnowledgeArticleController::class, 'store']);
    Route::get('articles/{article}/versions', [KnowledgeArticleController::class, 'versions'])->whereNumber('article');
    Route::get('articles/{article}/versions/{version}', [KnowledgeArticleController::class, 'version'])
        ->whereNumber('article')->whereNumber('version');
    Route::post('articles/{article}/versions/{version}/restore', [KnowledgeArticleController::class, 'restoreVersion'])
        ->whereNumber('article')->whereNumber('version');
    Route::post('articles/{article}/publish', [KnowledgeArticleController::class, 'publish'])->whereNumber('article');
    Route::post('articles/{article}/archive', [KnowledgeArticleController::class, 'archive'])->whereNumber('article');
    Route::post('articles/{article}/restore', [KnowledgeArticleController::class, 'restore'])->whereNumber('article');
    Route::get('articles/{article}', [KnowledgeArticleController::class, 'show'])->whereNumber('article');
    Route::patch('articles/{article}', [KnowledgeArticleController::class, 'update'])->whereNumber('article');
    Route::get('settings', [KnowledgeBaseController::class, 'show']);
    Route::put('settings', [KnowledgeBaseController::class, 'upsert']);
    Route::get('categories', [KnowledgeCategoryController::class, 'index']);
    Route::post('categories', [KnowledgeCategoryController::class, 'store']);
    Route::get('categories/{category}', [KnowledgeCategoryController::class, 'show'])->whereNumber('category');
    Route::patch('categories/{category}', [KnowledgeCategoryController::class, 'update'])->whereNumber('category');
    Route::delete('categories/{category}', [KnowledgeCategoryController::class, 'destroy'])->whereNumber('category');
    Route::get('tags', [KnowledgeTagController::class, 'index']);
    Route::post('tags', [KnowledgeTagController::class, 'store']);
    Route::get('tags/{tag}', [KnowledgeTagController::class, 'show'])->whereNumber('tag');
    Route::patch('tags/{tag}', [KnowledgeTagController::class, 'update'])->whereNumber('tag');
    Route::delete('tags/{tag}', [KnowledgeTagController::class, 'destroy'])->whereNumber('tag');
});
