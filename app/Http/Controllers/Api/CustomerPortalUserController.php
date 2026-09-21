<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PortalUserStatusRequest;
use App\Http\Resources\PortalUserAdminResource;
use App\Models\PortalUser;
use App\Models\User;
use App\Services\CustomerPortalService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CustomerPortalUserController extends Controller
{
    public function __construct(private readonly CustomerPortalService $portalService) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PortalUser::class);
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in([
                PortalUser::STATUS_ACTIVE,
                PortalUser::STATUS_SUSPENDED,
            ])],
            'contact_id' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', Rule::in(['created_at', 'last_login_at', 'email'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $query = PortalUser::query()->with([
            'contact:id,tenant_id,first_name,last_name,email',
        ]);

        if (filled($filters['q'] ?? null)) {
            $search = str_replace(
                ['!', '%', '_'],
                ['!!', '!%', '!_'],
                mb_strtolower(trim((string) $filters['q'])),
            );
            $pattern = '%'.$search.'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("LOWER(portal_users.email) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('contact', function (Builder $contact) use ($pattern): void {
                        $contact->whereRaw("LOWER(first_name) LIKE ? ESCAPE '!'", [$pattern])
                            ->orWhereRaw("LOWER(last_name) LIKE ? ESCAPE '!'", [$pattern])
                            ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$pattern]);
                    });
            });
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['contact_id'])) {
            $query->where('contact_id', $filters['contact_id']);
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';
        $page = $query->orderBy('portal_users.'.$sort, $direction)
            ->orderBy('portal_users.id', $direction)
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();
        $page->through(
            fn (PortalUser $portalUser): array => (new PortalUserAdminResource($portalUser))
                ->resolve($request),
        );

        return ApiResponse::paginated($page);
    }

    public function update(PortalUserStatusRequest $request, int $portalUser): JsonResponse
    {
        Gate::authorize('viewAny', PortalUser::class);
        $model = PortalUser::query()->findOrFail($portalUser);
        Gate::authorize('update', $model);
        /** @var User $actor */
        $actor = $request->user();
        $model = $this->portalService->changeStatus(
            $model,
            $request->validated('status'),
            $actor,
        )->load('contact:id,tenant_id,first_name,last_name,email');

        return ApiResponse::success(
            (new PortalUserAdminResource($model))->resolve($request),
        );
    }
}
