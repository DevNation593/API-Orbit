<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeetingAdminControlRequest;
use App\Jobs\SyncMeetingBookingJob;
use App\Models\MeetingBooking;
use App\Services\MeetingBookingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeetingBookingController extends Controller
{
    public function __construct(private readonly MeetingBookingService $bookings) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MeetingBooking::class);
        $query = MeetingBooking::query()->with($this->relations())
            ->when($request->filled('meeting_type_id'), fn ($builder) => $builder->where('meeting_type_id', $request->integer('meeting_type_id')))
            ->when($request->filled('host_user_id'), fn ($builder) => $builder->where('host_user_id', $request->integer('host_user_id')))
            ->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->string('status')->lower()->value()))
            ->when($request->filled('date_from'), fn ($builder) => $builder->where('starts_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($builder) => $builder->where('starts_at', '<=', $request->input('date_to')))
            ->orderByDesc('starts_at');

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage($request->input('per_page', 25)))->withQueryString());
    }

    public function show(int $booking): JsonResponse
    {
        $model = MeetingBooking::with($this->relations())->findOrFail($booking);
        $this->authorize('view', $model);

        return ApiResponse::success($model);
    }

    public function cancel(MeetingAdminControlRequest $request, int $booking): JsonResponse
    {
        $model = MeetingBooking::findOrFail($booking);
        $this->authorize('cancel', $model);

        return ApiResponse::success($this->bookings->cancel($model, $request->validated('reason')));
    }

    public function sync(MeetingAdminControlRequest $request, int $booking): JsonResponse
    {
        $model = MeetingBooking::findOrFail($booking);
        $this->authorize('cancel', $model);
        abort_if($model->calendar_connection_id === null, 409, 'This booking has no calendar connection.');
        abort_if($model->status !== 'confirmed', 409, 'Only confirmed meetings can be synchronized.');
        $model->update(['sync_status' => 'pending', 'sync_error' => null]);
        SyncMeetingBookingJob::dispatch((int) $model->tenant_id, (int) $model->id, 'sync')->onQueue('integrations');

        return ApiResponse::success($model->fresh(), ['queued' => true], 202);
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return [
            'meetingType:id,public_id,name,duration_minutes,timezone,location_type',
            'host:id,name,email',
            'contact:id,first_name,last_name,email,phone',
            'lead:id,first_name,last_name,email,phone',
            'calendarConnection:id,user_id,provider,external_calendar_id,status',
            'participants',
        ];
    }
}
