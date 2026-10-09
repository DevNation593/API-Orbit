<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class DuplicateDetectionService
{
    public function __construct(private readonly DuplicateNormalizer $normalizer) {}

    /** @param array<string, mixed> $input
     * @return Collection<int, array<string, mixed>>
     */
    public function contacts(array $input): Collection
    {
        $name = $input['name'] ?? trim(($input['first_name'] ?? '').' '.($input['last_name'] ?? ''));
        $criteria = array_filter([
            'email' => $this->normalizer->email($input['email'] ?? null),
            'phone' => $this->normalizer->phone($input['phone'] ?? null),
            'identification' => $this->normalizer->identifier($input['identification'] ?? null),
            'tax_id' => $this->normalizer->identifier($input['tax_id'] ?? null),
            'name' => $this->normalizer->text($name),
            'company' => $this->normalizer->text($input['company'] ?? null),
        ], fn (?string $value): bool => $value !== null);
        if ($criteria === []) {
            return collect();
        }

        $query = Contact::query()->with(['organizations:id,name,name_normalized']);
        $this->applyContactCandidates($query, $criteria);
        if (isset($input['exclude_id'])) {
            $query->whereKeyNot((int) $input['exclude_id']);
        }

        $limit = max(1, min((int) ($input['limit'] ?? 10), 50));
        $threshold = max(1, min((int) ($input['minimum_score'] ?? 25), 100));

        return $query->limit(250)->get()
            ->map(fn (Contact $contact): array => $this->scoreContact($contact, $criteria))
            ->filter(fn (array $match): bool => $match['score'] >= $threshold)
            ->sortBy([['score', 'desc'], ['id', 'asc']])
            ->take($limit)
            ->values();
    }

    /** @param array<string, mixed> $input
     * @return Collection<int, array<string, mixed>>
     */
    public function organizations(array $input): Collection
    {
        $criteria = array_filter([
            'email' => $this->normalizer->email($input['email'] ?? null),
            'phone' => $this->normalizer->phone($input['phone'] ?? null),
            'identification' => $this->normalizer->identifier($input['identification'] ?? null),
            'tax_id' => $this->normalizer->identifier($input['tax_id'] ?? null),
            'website' => $this->normalizer->website($input['website'] ?? null),
            'name' => $this->normalizer->text($input['name'] ?? $input['company'] ?? null),
        ], fn (?string $value): bool => $value !== null);
        if ($criteria === []) {
            return collect();
        }

        $query = Organization::query();
        $this->applyOrganizationCandidates($query, $criteria);
        if (isset($input['exclude_id'])) {
            $query->whereKeyNot((int) $input['exclude_id']);
        }

        $limit = max(1, min((int) ($input['limit'] ?? 10), 50));
        $threshold = max(1, min((int) ($input['minimum_score'] ?? 25), 100));

        return $query->limit(250)->get()
            ->map(fn (Organization $organization): array => $this->scoreOrganization($organization, $criteria))
            ->filter(fn (array $match): bool => $match['score'] >= $threshold)
            ->sortBy([['score', 'desc'], ['id', 'asc']])
            ->take($limit)
            ->values();
    }

    /** @param array<string, string> $criteria */
    private function applyContactCandidates(Builder $query, array $criteria): void
    {
        $columns = [
            'email' => 'email_normalized',
            'phone' => 'phone_normalized',
            'identification' => 'identification_normalized',
            'tax_id' => 'tax_id_normalized',
            'name' => 'name_normalized',
        ];

        $query->where(function (Builder $candidates) use ($criteria, $columns): void {
            foreach ($columns as $key => $column) {
                if (isset($criteria[$key])) {
                    $candidates->orWhere($column, $criteria[$key]);
                }
            }
            if (isset($criteria['company'])) {
                $candidates->orWhereHas('organizations', fn (Builder $organizations) => $organizations
                    ->where('name_normalized', $criteria['company']));
            }
        });
    }

    /** @param array<string, string> $criteria */
    private function applyOrganizationCandidates(Builder $query, array $criteria): void
    {
        $columns = [
            'email' => 'email_normalized',
            'phone' => 'phone_normalized',
            'identification' => 'identification_normalized',
            'tax_id' => 'tax_id_normalized',
            'website' => 'website_normalized',
            'name' => 'name_normalized',
        ];

        $query->where(function (Builder $candidates) use ($criteria, $columns): void {
            foreach ($columns as $key => $column) {
                if (isset($criteria[$key])) {
                    $candidates->orWhere($column, $criteria[$key]);
                }
            }
        });
    }

    /** @param array<string, string> $criteria
     * @return array<string, mixed>
     */
    private function scoreContact(Contact $contact, array $criteria): array
    {
        [$score, $matches] = $this->score($contact, $criteria, [
            'email' => ['email_normalized', 60],
            'phone' => ['phone_normalized', 45],
            'identification' => ['identification_normalized', 80],
            'tax_id' => ['tax_id_normalized', 80],
            'name' => ['name_normalized', 30],
        ]);

        if (isset($criteria['company']) && $contact->organizations->contains(
            fn (Organization $organization): bool => $organization->name_normalized === $criteria['company'],
        )) {
            $matches[] = 'company';
            $score += 20;
        }

        $score = min(100, $score);

        return [
            'id' => (int) $contact->id,
            'type' => 'contact',
            'display_name' => trim($contact->first_name.' '.$contact->last_name) ?: '#'.$contact->id,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'score' => $score,
            'confidence' => $this->confidence($score),
            'matched_fields' => $matches,
            'updated_at' => $contact->updated_at,
        ];
    }

    /** @param array<string, string> $criteria
     * @return array<string, mixed>
     */
    private function scoreOrganization(Organization $organization, array $criteria): array
    {
        [$score, $matches] = $this->score($organization, $criteria, [
            'email' => ['email_normalized', 50],
            'phone' => ['phone_normalized', 35],
            'identification' => ['identification_normalized', 80],
            'tax_id' => ['tax_id_normalized', 80],
            'website' => ['website_normalized', 55],
            'name' => ['name_normalized', 40],
        ]);
        $score = min(100, $score);

        return [
            'id' => (int) $organization->id,
            'type' => 'company',
            'display_name' => $organization->name,
            'email' => $organization->email,
            'phone' => $organization->phone,
            'website' => $organization->website,
            'score' => $score,
            'confidence' => $this->confidence($score),
            'matched_fields' => $matches,
            'updated_at' => $organization->updated_at,
        ];
    }

    /** @param array<string, string> $criteria
     * @param  array<string, array{0: string, 1: int}>  $weights
     * @return array{0: int, 1: array<int, string>}
     */
    private function score(Contact|Organization $model, array $criteria, array $weights): array
    {
        $score = 0;
        $matches = [];
        foreach ($weights as $field => [$attribute, $weight]) {
            if (isset($criteria[$field]) && hash_equals($criteria[$field], (string) $model->getAttribute($attribute))) {
                $matches[] = $field;
                $score += $weight;
            }
        }

        return [$score, $matches];
    }

    private function confidence(int $score): string
    {
        return match (true) {
            $score >= 70 => 'high',
            $score >= 40 => 'medium',
            default => 'low',
        };
    }
}
