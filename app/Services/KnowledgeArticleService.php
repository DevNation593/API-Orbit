<?php

namespace App\Services;

use App\Models\KnowledgeArticle;
use App\Models\KnowledgeArticleVersion;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeCategory;
use App\Models\KnowledgeTag;
use App\Models\User;
use App\Support\AuditService;
use App\Support\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KnowledgeArticleService
{
    private const VERSION_FIELDS = [
        'category_id',
        'title',
        'summary',
        'body_html',
        'visibility',
        'seo_title',
        'seo_description',
        'change_summary',
    ];

    public function __construct(
        private readonly ConnectionInterface $database,
        private readonly HtmlSanitizer $sanitizer,
        private readonly AuditService $audit,
    ) {}

    public function create(array $data, User $actor): KnowledgeArticle
    {
        return $this->database->transaction(function () use ($data, $actor): KnowledgeArticle {
            if (KnowledgeBase::query()->first() === null) {
                abort(409, 'Configure the knowledge base before creating articles.');
            }
            $prepared = $this->prepareVersionData($data);
            $article = KnowledgeArticle::create([
                'public_id' => (string) Str::uuid(),
                'status' => 'DRAFT',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $version = $this->createVersion($article, 1, $prepared, $actor);
            $article->forceFill(['current_version_id' => $version->id])->save();
            $this->auditArticle(
                'knowledge.article.created',
                $article,
                $version,
                null,
                $this->auditableFields($prepared),
            );

            return $this->loadArticle($article);
        });
    }

    public function revise(KnowledgeArticle $article, array $data, User $actor): KnowledgeArticle
    {
        return $this->database->transaction(function () use ($article, $data, $actor): KnowledgeArticle {
            $article = KnowledgeArticle::query()->lockForUpdate()->findOrFail($article->id);
            $current = $article->currentVersion()->with('tags')->firstOrFail();
            if ((int) $data['expected_version'] !== (int) $current->version) {
                abort(409, 'The article has a newer version.');
            }
            if ($article->status === 'ARCHIVED') {
                abort(409, 'Restore the archived article before editing it.');
            }

            $snapshot = Arr::only($current->getAttributes(), self::VERSION_FIELDS);
            foreach (self::VERSION_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $snapshot[$field] = $data[$field];
                }
            }
            $snapshot['tag_ids'] = array_key_exists('tag_ids', $data)
                ? $data['tag_ids']
                : $current->tags->modelKeys();

            $prepared = $this->prepareVersionData($snapshot);
            $version = $this->createVersion($article, (int) $current->version + 1, $prepared, $actor);
            $article->forceFill([
                'current_version_id' => $version->id,
                'updated_by' => $actor->id,
            ])->save();
            $this->auditArticle(
                'knowledge.article.revised',
                $article,
                $version,
                (int) $current->version,
                $this->auditableFields($data),
            );

            return $this->loadArticle($article);
        });
    }

    public function version(KnowledgeArticle $article, int $number): KnowledgeArticleVersion
    {
        return $article->versions()
            ->with(['category', 'tags'])
            ->where('version', $number)
            ->firstOrFail();
    }

    private function prepareVersionData(array $data): array
    {
        $prepared = Arr::only($data, [...self::VERSION_FIELDS, 'tag_ids']);

        if (array_key_exists('category_id', $prepared) && $prepared['category_id'] !== null) {
            $category = KnowledgeCategory::query()
                ->whereKey($prepared['category_id'])
                ->where('is_active', true)
                ->first();
            if ($category === null) {
                throw ValidationException::withMessages([
                    'category_id' => 'The selected category is invalid.',
                ]);
            }
            $prepared['category_id'] = $category->id;
        }

        $tagIds = array_map('intval', $prepared['tag_ids'] ?? []);
        if (count($tagIds) !== count(array_unique($tagIds))
            || KnowledgeTag::query()->whereIn('id', $tagIds)->where('is_active', true)->count() !== count($tagIds)) {
            throw ValidationException::withMessages([
                'tag_ids' => 'One or more selected tags are invalid.',
            ]);
        }
        $prepared['tag_ids'] = $tagIds;

        if (array_key_exists('body_html', $prepared)) {
            $prepared['body_html'] = $this->sanitizer->sanitize($prepared['body_html']);
            if (trim((string) $prepared['body_html']) === '') {
                throw ValidationException::withMessages([
                    'body_html' => 'The body must contain content after sanitization.',
                ]);
            }
        }

        return $prepared;
    }

    private function createVersion(
        KnowledgeArticle $article,
        int $number,
        array $data,
        User $actor,
    ): KnowledgeArticleVersion {
        $tagIds = Arr::pull($data, 'tag_ids', []);
        $version = KnowledgeArticleVersion::create($data + [
            'article_id' => $article->id,
            'version' => $number,
            'author_id' => $actor->id,
        ]);
        if ($tagIds !== []) {
            $version->tags()->attach($tagIds, [
                'tenant_id' => app(TenantContext::class)->requireId(),
            ]);
        }

        return $version;
    }

    private function loadArticle(KnowledgeArticle $article): KnowledgeArticle
    {
        return $article->load([
            'currentVersion.category',
            'currentVersion.tags',
            'publishedVersion.category',
            'publishedVersion.tags',
        ]);
    }

    /** @return list<string> */
    private function auditableFields(array $data): array
    {
        return array_values(array_diff(array_keys($data), ['expected_version', 'body_html']));
    }

    /** @param list<string> $changedFields */
    private function auditArticle(
        string $action,
        KnowledgeArticle $article,
        KnowledgeArticleVersion $version,
        ?int $previousVersion,
        array $changedFields,
    ): void {
        $this->audit->record(
            $action,
            $article,
            oldValues: $previousVersion === null ? null : ['version' => $previousVersion],
            newValues: [
                'version' => (int) $version->version,
                'changed_fields' => $changedFields,
            ],
        );
    }
}
