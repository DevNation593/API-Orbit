<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PortalInvitationRequest;
use App\Http\Resources\PortalInvitationAdminResource;
use App\Models\Contact;
use App\Models\PortalInvitation;
use App\Models\User;
use App\Services\PortalInvitationService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CustomerPortalInvitationController extends Controller
{
    public function __construct(
        private readonly PortalInvitationService $invitationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PortalInvitation::class);
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([
                PortalInvitation::STATUS_PENDING,
                PortalInvitation::STATUS_ACCEPTED,
                PortalInvitation::STATUS_REVOKED,
                PortalInvitation::STATUS_EXPIRED,
            ])],
            'contact_id' => ['sometimes', 'integer', 'min:1'],
            'created_from' => ['sometimes', 'date'],
            'created_to' => ['sometimes', 'date'],
            'expires_before' => ['sometimes', 'date'],
            'sort' => ['sometimes', Rule::in(['created_at', 'expires_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $query = PortalInvitation::query()->with([
            'contact:id,tenant_id,first_name,last_name,email',
        ]);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['contact_id'])) {
            $query->where('contact_id', $filters['contact_id']);
        }
        if (isset($filters['created_from'])) {
            $query->where(
                'created_at',
                '>=',
                CarbonImmutable::parse($filters['created_from'])->startOfDay(),
            );
        }
        if (isset($filters['created_to'])) {
            $query->where(
                'created_at',
                '<=',
                CarbonImmutable::parse($filters['created_to'])->endOfDay(),
            );
        }
        if (isset($filters['expires_before'])) {
            $query->where(
                'expires_at',
                '<=',
                CarbonImmutable::parse($filters['expires_before'])->endOfDay(),
            );
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';
        $page = $query->orderBy('portal_invitations.'.$sort, $direction)
            ->orderBy('portal_invitations.id', $direction)
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();
        $page->through(
            fn (PortalInvitation $invitation): array => (new PortalInvitationAdminResource(
                $invitation,
            ))->resolve($request),
        );

        return ApiResponse::paginated($page);
    }

    public function store(PortalInvitationRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $contact = Contact::query()->findOrFail((int) $request->validated('contact_id'));
        $result = $this->invitationService->invite($contact, $actor);
        $result['invitation']->load('contact:id,tenant_id,first_name,last_name,email');
        $meta = ['notification_sent' => $result['notification_sent']];
        if (app()->environment('local', 'testing')) {
            $meta['activation_url'] = $result['activation_url'];
        }

        return ApiResponse::success(
            (new PortalInvitationAdminResource($result['invitation']))->resolve($request),
            $meta,
            201,
        );
    }

    public function destroy(Request $request, int $invitation): JsonResponse
    {
        Gate::authorize('viewAny', PortalInvitation::class);
        $model = PortalInvitation::query()->findOrFail($invitation);
        Gate::authorize('update', $model);
        /** @var User $actor */
        $actor = $request->user();
        $model = $this->invitationService->revoke($model, $actor)
            ->load('contact:id,tenant_id,first_name,last_name,email');

        return ApiResponse::success(
            (new PortalInvitationAdminResource($model))->resolve($request),
        );
    }
}
