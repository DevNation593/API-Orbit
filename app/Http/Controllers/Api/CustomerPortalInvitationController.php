<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PortalInvitationIndexRequest;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class CustomerPortalInvitationController extends Controller
{
    public function __construct(
        private readonly PortalInvitationService $invitationService,
    ) {}

    public function index(PortalInvitationIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
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
            ->appends(Arr::except($filters, ['page']));
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
