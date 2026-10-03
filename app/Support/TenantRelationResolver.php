<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\EntityRecord;
use App\Models\FileRecord;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Task;
use Illuminate\Validation\ValidationException;

final class TenantRelationResolver
{
    /** @var array<string, class-string> */
    private const STANDARD_TYPES = [
        'contact' => Contact::class,
        'contacts' => Contact::class,
        'conversation' => Conversation::class,
        'conversations' => Conversation::class,
        'organization' => Organization::class,
        'organizations' => Organization::class,
        'company' => Organization::class,
        'companies' => Organization::class,
        'lead' => Lead::class,
        'leads' => Lead::class,
        'deal' => Deal::class,
        'deals' => Deal::class,
        'opportunity' => Deal::class,
        'opportunities' => Deal::class,
        'task' => Task::class,
        'tasks' => Task::class,
        'activity' => Activity::class,
        'activities' => Activity::class,
        'file' => FileRecord::class,
        'files' => FileRecord::class,
        'document' => FileRecord::class,
        'documents' => FileRecord::class,
        'product' => Product::class,
        'products' => Product::class,
        'quote' => Quote::class,
        'quotes' => Quote::class,
    ];

    public function canonicalMorphType(?string $type, mixed $id): ?string
    {
        if ($type === null && $id === null) {
            return null;
        }
        if ($type === null || $id === null) {
            throw ValidationException::withMessages(['relation' => 'A relation type and id must be provided together.']);
        }

        $canonical = $this->canonicalEntityType($type, $id);

        return isset(self::STANDARD_TYPES[$canonical]) ? $canonical : 'entity_record';
    }

    public function canonicalEntityType(string $type, string|int $id): string
    {
        $normalized = strtolower(trim($type));
        if (isset(self::STANDARD_TYPES[$normalized])) {
            $model = self::STANDARD_TYPES[$normalized];
            if (! $model::query()->whereKey($id)->exists()) {
                $this->invalidRelation();
            }

            return match ($model) {
                Contact::class => 'contact',
                Conversation::class => 'conversation',
                Organization::class => 'organization',
                Lead::class => 'lead',
                Deal::class => 'deal',
                Task::class => 'task',
                Activity::class => 'activity',
                FileRecord::class => 'file',
                Product::class => 'product',
                Quote::class => 'quote',
            };
        }

        if ($normalized === 'entity_record' && EntityRecord::query()->whereKey($id)->exists()) {
            return 'entity_record';
        }

        $this->invalidRelation();
    }

    private function invalidRelation(): never
    {
        throw ValidationException::withMessages([
            'relation' => 'The related record is invalid or belongs to another tenant.',
        ]);
    }
}
