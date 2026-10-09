<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContactDuplicateCheckRequest;
use App\Http\Requests\MergeContactRequest;
use App\Models\Contact;
use App\Services\DuplicateDetectionService;
use App\Services\RecordMergeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ContactDuplicateController extends Controller
{
    public function __construct(
        private readonly DuplicateDetectionService $duplicates,
        private readonly RecordMergeService $merges,
    ) {}

    public function check(ContactDuplicateCheckRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Contact::class);
        $matches = $this->duplicates->contacts($request->validated());

        return ApiResponse::success($matches, ['count' => $matches->count()]);
    }

    public function merge(MergeContactRequest $request, int $contact): JsonResponse
    {
        abort_unless($request->user()->hasPermission('duplicates.manage'), 403, 'You do not have permission to merge duplicate records.');
        $target = Contact::findOrFail($contact);
        $source = Contact::findOrFail((int) $request->validated('duplicate_id'));
        $this->authorize('update', $target);
        $this->authorize('delete', $source);

        return ApiResponse::success($this->merges->mergeContacts(
            $target,
            $source,
            $request->validated('field_overrides', []),
        ));
    }
}
