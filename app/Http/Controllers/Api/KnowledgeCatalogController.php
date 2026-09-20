<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

abstract class KnowledgeCatalogController extends Controller
{
    protected function catalogFilters(Request $request): array
    {
        return $request->validate([
            'q' => ['sometimes', 'string', 'max:120'],
            'active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
    }

    protected function applyCatalogFilters(Builder $query, array $filters): Builder
    {
        if (array_key_exists('active', $filters)) {
            $query->where('is_active', $filters['active']);
        }
        if (filled($filters['q'] ?? null)) {
            $search = str_replace(
                ['!', '%', '_'],
                ['!!', '!%', '!_'],
                mb_strtolower(trim((string) $filters['q'])),
            );
            $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", ['%'.$search.'%']);
        }

        return $query;
    }
}
