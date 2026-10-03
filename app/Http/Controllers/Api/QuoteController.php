<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuoteDecisionRequest;
use App\Http\Requests\QuoteRequest;
use App\Http\Requests\QuoteSendRequest;
use App\Jobs\GenerateQuotePdfJob;
use App\Models\ApprovalRequest;
use App\Models\Quote;
use App\Services\ApprovalEngine;
use App\Services\ErpSyncService;
use App\Services\QuoteDeliveryService;
use App\Services\QuoteService;
use App\Support\ApiResponse;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuoteController extends Controller
{
    public function __construct(
        private readonly QuoteService $quotes,
        private readonly ApprovalEngine $approvals,
        private readonly QuoteDeliveryService $delivery,
        private readonly ErpSyncService $erp,
        private readonly FilesystemManager $storage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Quote::class);
        $data = $request->validate([
            'status' => ['nullable', 'in:draft,pending_approval,approved,sent,viewed,accepted,rejected,expired,cancelled'],
            'deal_id' => ['nullable', 'integer', 'min:1'], 'contact_id' => ['nullable', 'integer', 'min:1'],
            'organization_id' => ['nullable', 'integer', 'min:1'], 'owner_id' => ['nullable', 'integer', 'min:1'],
            'created_from' => ['nullable', 'date'], 'created_to' => ['nullable', 'date', 'after_or_equal:created_from'],
            'q' => ['nullable', 'string', 'max:120'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = Quote::query()->with(['currency:id,code,symbol,decimal_places', 'contact:id,first_name,last_name,email', 'organization:id,name', 'owner:id,name'])->withCount('items');
        foreach (['status', 'deal_id', 'contact_id', 'organization_id', 'owner_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $query->where($field, $data[$field]);
            }
        }
        if (isset($data['created_from'])) {
            $query->where('created_at', '>=', $data['created_from']);
        }
        if (isset($data['created_to'])) {
            $query->where('created_at', '<=', $data['created_to']);
        }
        if (filled($data['q'] ?? null)) {
            $escaped = addcslashes((string) $data['q'], '%_\\');
            $query->where(fn ($part) => $part->where('number', 'like', "%{$escaped}%")->orWhere('title', 'like', "%{$escaped}%"));
        }

        return ApiResponse::paginated($query->latest('id')->paginate(ApiResponse::perPage($data['per_page'] ?? 25))->withQueryString());
    }

    public function store(QuoteRequest $request): JsonResponse
    {
        $this->authorize('create', Quote::class);

        return ApiResponse::success($this->quotes->create($request->validated(), (int) $request->user()->id), [], 201);
    }

    public function show(int $quote): JsonResponse
    {
        $model = Quote::with($this->quotes->relations())->findOrFail($quote);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(QuoteRequest $request, int $quote): JsonResponse
    {
        $model = Quote::findOrFail($quote);
        $this->authorize('update', $model);

        return ApiResponse::success($this->quotes->update($model, $request->validated()));
    }

    public function destroy(int $quote): JsonResponse
    {
        $model = Quote::findOrFail($quote);
        $this->authorize('delete', $model);
        $this->quotes->delete($model);

        return ApiResponse::success(['deleted' => true]);
    }

    public function duplicate(Request $request, int $quote): JsonResponse
    {
        $model = Quote::findOrFail($quote);
        $this->authorize('view', $model);
        $this->authorize('create', Quote::class);

        return ApiResponse::success($this->quotes->duplicate($model, (int) $request->user()->id), [], 201);
    }

    public function revise(Request $request, int $quote): JsonResponse
    {
        $model = Quote::findOrFail($quote);
        $this->authorize('update', $model);

        return ApiResponse::success($this->quotes->revise($model, (int) $request->user()->id), [], 201);
    }

    public function submit(Request $request, int $quote): JsonResponse
    {
        $model = Quote::findOrFail($quote);
        $this->authorize('update', $model);

        return ApiResponse::success($this->quotes->submit($model, (int) $request->user()->id));
    }

    public function approve(Request $request, int $quote): JsonResponse
    {
        $model = Quote::findOrFail($quote);
        $this->authorize('approve', $model);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:10000']]);
        $approval = $this->pendingApproval($model);

        return ApiResponse::success($this->approvals->decide($approval, $request->user(), 'approve', $data['comment'] ?? null));
    }

    public function rejectApproval(Request $request, int $quote): JsonResponse
    {
        $model = Quote::findOrFail($quote);
        $this->authorize('approve', $model);
        $data = $request->validate(['comment' => ['required', 'string', 'max:10000']]);

        return ApiResponse::success($this->approvals->decide($this->pendingApproval($model), $request->user(), 'reject', $data['comment']));
    }

    public function send(QuoteSendRequest $request, int $quote): JsonResponse
    {
        $model = Quote::findOrFail($quote);
        $this->authorize('send', $model);
        $result = $this->delivery->send($model, $request->user(), $request->validated());

        return ApiResponse::success($result['quote'], [
            'acceptance_token' => $result['acceptance_token'], 'message_id' => $result['message_id'], 'replayed' => $result['replayed'],
        ]);
    }

    public function accept(QuoteDecisionRequest $request, int $quote): JsonResponse
    {
        $model = Quote::findOrFail($quote);
        $this->authorize('accept', $model);
        $result = $this->quotes->decide($model, 'accept', $request->validated(), $request->ip());

        return ApiResponse::success($result['quote'], ['replayed' => $result['replayed']]);
    }

    public function reject(QuoteDecisionRequest $request, int $quote): JsonResponse
    {
        $model = Quote::findOrFail($quote);
        $this->authorize('accept', $model);
        $result = $this->quotes->decide($model, 'reject', $request->validated(), $request->ip());

        return ApiResponse::success($result['quote'], ['replayed' => $result['replayed']]);
    }

    public function cancel(Request $request, int $quote): JsonResponse
    {
        $model = Quote::findOrFail($quote);
        $this->authorize('update', $model);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return ApiResponse::success($this->quotes->cancel($model, $data['reason'] ?? null));
    }

    public function generatePdf(int $quote): JsonResponse
    {
        $model = Quote::findOrFail($quote);
        $this->authorize('view', $model);
        GenerateQuotePdfJob::dispatch((int) $model->tenant_id, (int) $model->id)->onQueue('documents');

        return ApiResponse::success(['queued' => true], [], 202);
    }

    public function downloadPdf(int $quote): StreamedResponse
    {
        $model = Quote::with('pdfFile')->findOrFail($quote);
        $this->authorize('view', $model);
        if ($model->pdfFile === null || ! $this->storage->disk($model->pdfFile->disk)->exists($model->pdfFile->path)) {
            throw ValidationException::withMessages(['pdf' => 'Generate the quote PDF before downloading it.']);
        }

        return $this->storage->disk($model->pdfFile->disk)->download($model->pdfFile->path, $model->pdfFile->filename, ['Content-Type' => 'application/pdf']);
    }

    public function activities(int $quote): JsonResponse
    {
        $model = Quote::findOrFail($quote);
        $this->authorize('view', $model);

        return ApiResponse::paginated($model->activities()->with('user:id,name')->paginate(50)->withQueryString());
    }

    public function syncErp(int $quote): JsonResponse
    {
        $model = Quote::findOrFail($quote);
        $this->authorize('view', $model);
        abort_unless(request()->user()->hasPermission('erp_sync.manage'), 403);
        if ($model->status !== 'accepted') {
            throw ValidationException::withMessages(['status' => 'Only accepted quotes can be synchronized to ERP.']);
        }

        return ApiResponse::success($this->erp->queue('quote', $model), [], 202);
    }

    private function pendingApproval(Quote $quote): ApprovalRequest
    {
        return ApprovalRequest::query()->where('approvable_type', 'quote')->where('approvable_id', $quote->id)->where('status', 'pending')->latest('id')->first()
            ?? throw ValidationException::withMessages(['approval' => 'This quote has no pending approval request.']);
    }
}
