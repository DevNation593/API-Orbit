<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\EntityRecord;
use App\Models\FileRecord;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class GlobalSearchService
{
    private const PERMISSIONS = [
        'contacts' => 'contacts.view',
        'companies' => 'organizations.view',
        'leads' => 'leads.view',
        'opportunities' => 'deals.view',
        'documents' => 'files.view',
        'custom_objects' => 'custom_entities.view',
    ];

    /** @param array<int, string> $types */
    public function search(User $user, string $term, array $types, int $perPage, int $page): LengthAwarePaginator
    {
        $types = collect($types === [] ? array_keys(self::PERMISSIONS) : $types)
            ->map(fn (string $type): string => match ($type) {
                'organizations' => 'companies',
                'deals' => 'opportunities',
                'files' => 'documents',
                default => $type,
            })->unique()->filter(fn (string $type): bool => $user->hasPermission(self::PERMISSIONS[$type]))->values();

        abort_if($types->isEmpty(), 403, 'You do not have permission to search the requested resource types.');
        $term = Str::lower(trim($term));
        $fetch = $page * $perPage;
        $results = collect();
        $total = 0;

        foreach ($types as $type) {
            [$query, $present] = $this->queryFor($type, $term);
            $total += (clone $query)->count();
            $results->push(...$query->latest('updated_at')->limit($fetch)->get()->map($present));
        }

        $items = $results->sort(function (array $left, array $right): int {
            return [$right['relevance'], $right['updated_at'], $left['id']]
                <=> [$left['relevance'], $left['updated_at'], $right['id']];
        })->values()->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }

    /** @return array{0: Builder, 1: callable(mixed): array<string, mixed>} */
    private function queryFor(string $type, string $term): array
    {
        return match ($type) {
            'contacts' => [
                $this->containing(Contact::query(), ['first_name', 'last_name', 'email', 'phone', 'custom_fields'], $term),
                fn (Contact $record): array => $this->result(
                    'contact', $record->id, trim($record->first_name.' '.$record->last_name) ?: '#'.$record->id,
                    $record->email ?: $record->phone, $record->updated_at?->toISOString(), $term,
                ),
            ],
            'companies' => [
                $this->containing(Organization::query(), ['name', 'legal_name', 'email', 'phone', 'website', 'custom_fields'], $term),
                fn (Organization $record): array => $this->result(
                    'company', $record->id, $record->name, $record->email ?: $record->website,
                    $record->updated_at?->toISOString(), $term,
                ),
            ],
            'leads' => [
                $this->containing(Lead::query(), ['first_name', 'last_name', 'email', 'phone', 'source', 'custom_fields'], $term),
                fn (Lead $record): array => $this->result(
                    'lead', $record->id, trim($record->first_name.' '.$record->last_name) ?: '#'.$record->id,
                    $record->email ?: $record->source, $record->updated_at?->toISOString(), $term,
                ),
            ],
            'opportunities' => [
                $this->containing(Deal::query(), ['name', 'status', 'currency', 'custom_fields'], $term),
                fn (Deal $record): array => $this->result(
                    'opportunity', $record->id, $record->name,
                    trim($record->currency.' '.(string) $record->value), $record->updated_at?->toISOString(), $term,
                ),
            ],
            'documents' => [
                $this->containing(FileRecord::query(), ['filename', 'mime_type', 'metadata'], $term),
                fn (FileRecord $record): array => $this->result(
                    'document', $record->id, $record->filename, $record->mime_type,
                    $record->updated_at?->toISOString(), $term,
                ),
            ],
            'custom_objects' => [
                $this->containing(EntityRecord::query()->with('definition:id,name,label'), ['data'], $term),
                fn (EntityRecord $record): array => $this->result(
                    'custom_object', $record->id, $this->customObjectTitle($record),
                    $record->definition?->label, $record->updated_at?->toISOString(), $term,
                    ['entity_definition_id' => (int) $record->entity_definition_id],
                ),
            ],
        };
    }

    /** @param array<int, string> $columns */
    private function containing(Builder $query, array $columns, string $term): Builder
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

        return $query->where(function (Builder $search) use ($columns, $escaped): void {
            foreach ($columns as $column) {
                $search->orWhereRaw("LOWER(CAST({$column} AS TEXT)) LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
            }
        });
    }

    /** @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function result(
        string $type,
        int $id,
        string $title,
        ?string $subtitle,
        ?string $updatedAt,
        string $term,
        array $extra = [],
    ): array {
        $titleLower = Str::lower($title);
        $subtitleLower = Str::lower((string) $subtitle);
        $relevance = match (true) {
            $titleLower === $term => 100,
            str_starts_with($titleLower, $term) => 80,
            str_contains($titleLower, $term) => 60,
            str_contains($subtitleLower, $term) => 30,
            default => 10,
        };

        return array_merge([
            'type' => $type,
            'id' => $id,
            'title' => $title,
            'subtitle' => $subtitle,
            'relevance' => $relevance,
            'updated_at' => $updatedAt,
        ], $extra);
    }

    private function customObjectTitle(EntityRecord $record): string
    {
        foreach (['name', 'title', 'label', 'email'] as $field) {
            if (is_scalar($record->data[$field] ?? null) && trim((string) $record->data[$field]) !== '') {
                return (string) $record->data[$field];
            }
        }

        return ($record->definition?->label ?? 'Custom object').' #'.$record->id;
    }
}
