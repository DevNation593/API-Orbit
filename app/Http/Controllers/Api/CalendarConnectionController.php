<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalendarConnectionRequest;
use App\Models\CalendarConnection;
use App\Support\ApiResponse;
use App\Support\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CalendarConnectionController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CalendarConnection::class);
        $query = CalendarConnection::query()->with($this->relations())
            ->when($request->filled('user_id'), fn ($builder) => $builder->where('user_id', $request->integer('user_id')))
            ->when($request->filled('provider'), fn ($builder) => $builder->where('provider', $request->string('provider')->lower()->value()))
            ->orderBy('user_id')->orderBy('provider');

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function store(CalendarConnectionRequest $request): JsonResponse
    {
        $this->authorize('create', CalendarConnection::class);
        $data = $request->validated();
        $this->assertUnique($data);
        $connection = CalendarConnection::create($data);
        $this->audit->record('calendar_connection_created', $connection, newValues: $this->auditValues($connection));

        return ApiResponse::success($connection->load($this->relations()), [], 201);
    }

    public function show(int $connection): JsonResponse
    {
        $model = CalendarConnection::with($this->relations())->findOrFail($connection);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function update(CalendarConnectionRequest $request, int $connection): JsonResponse
    {
        $model = CalendarConnection::findOrFail($connection);
        $this->authorize('update', $model);
        $data = $request->validated();
        if ($model->bookings()->exists() && (isset($data['user_id']) || isset($data['provider']))) {
            throw ValidationException::withMessages(['connection' => 'A used calendar connection cannot change user or provider.']);
        }
        $this->assertUnique(array_replace($model->only(['user_id', 'provider', 'external_calendar_id']), $data), $model->id);
        $old = $this->auditValues($model);
        $model->update($data);
        $this->audit->record('calendar_connection_updated', $model, oldValues: $old, newValues: $this->auditValues($model));

        return ApiResponse::success($model->fresh($this->relations()));
    }

    public function destroy(int $connection): JsonResponse
    {
        $model = CalendarConnection::findOrFail($connection);
        $this->authorize('delete', $model);
        abort_if($model->bookings()->where('status', 'confirmed')->where('starts_at', '>=', now())->exists(), 409,
            'A calendar connection with future meetings cannot be deleted; deactivate it instead.');
        $old = $this->auditValues($model);
        $model->delete();
        $this->audit->record('calendar_connection_deleted', $model, oldValues: $old);

        return ApiResponse::success(['deleted' => true]);
    }

    /** @param array<string, mixed> $data */
    private function assertUnique(array $data, ?int $ignore = null): void
    {
        $query = CalendarConnection::query()->where('user_id', $data['user_id'])
            ->where('provider', $data['provider'])
            ->where('external_calendar_id', $data['external_calendar_id'] ?? 'primary');
        if ($ignore !== null) {
            $query->where('id', '!=', $ignore);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['external_calendar_id' => 'This calendar is already connected for the user.']);
        }
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return ['user:id,name,email', 'integration:id,provider,name,status'];
    }

    /** @return array<string, mixed> */
    private function auditValues(CalendarConnection $connection): array
    {
        return $connection->only([
            'user_id', 'integration_id', 'provider', 'external_calendar_id', 'timezone', 'status',
        ]);
    }
}
