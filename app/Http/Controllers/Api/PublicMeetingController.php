<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeetingAvailabilityRequest;
use App\Http\Requests\MeetingBookingRequest;
use App\Http\Requests\MeetingControlRequest;
use App\Models\MeetingBooking;
use App\Models\MeetingType;
use App\Services\MeetingAvailabilityService;
use App\Services\MeetingBookingService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class PublicMeetingController extends Controller
{
    public function __construct(
        private readonly MeetingAvailabilityService $availability,
        private readonly MeetingBookingService $bookings,
        private readonly TenantContext $context,
    ) {}

    public function show(string $publicId): JsonResponse
    {
        $type = $this->type($publicId);

        return $this->withinTenant($type->tenant_id, fn () => ApiResponse::success([
            'public_id' => $type->public_id,
            'name' => $type->name,
            'description' => $type->description,
            'duration_minutes' => $type->duration_minutes,
            'timezone' => $type->timezone,
            'location_type' => $type->location_type,
        ]));
    }

    public function availability(MeetingAvailabilityRequest $request, string $publicId): JsonResponse
    {
        $type = $this->type($publicId);

        return $this->withinTenant($type->tenant_id, fn () => ApiResponse::success($this->availability->availability(
            $type,
            $request->validated('date_from'),
            $request->validated('date_to'),
            $request->validated('timezone'),
        )));
    }

    public function book(MeetingBookingRequest $request, string $publicId): JsonResponse
    {
        $type = $this->type($publicId);

        return $this->withinTenant($type->tenant_id, function () use ($type, $request): JsonResponse {
            $result = $this->bookings->book($type, $request->validated());

            return ApiResponse::success($this->publicResult($result), ['replayed' => $result['replayed']],
                $result['replayed'] ? 200 : 201);
        });
    }

    public function cancel(MeetingControlRequest $request, string $bookingPublicId): JsonResponse
    {
        $booking = $this->booking($bookingPublicId);

        return $this->withinTenant($booking->tenant_id, function () use ($booking, $request): JsonResponse {
            $this->bookings->assertManageToken($booking, $request->validated('manage_token'));
            $model = $this->bookings->cancel($booking, $request->validated('reason'));

            return ApiResponse::success(['public_id' => $model->public_id, 'status' => $model->status]);
        });
    }

    public function reschedule(MeetingControlRequest $request, string $bookingPublicId): JsonResponse
    {
        $booking = $this->booking($bookingPublicId);

        return $this->withinTenant($booking->tenant_id, function () use ($booking, $request): JsonResponse {
            $data = $request->validated();
            $this->bookings->assertManageToken($booking, $data['manage_token']);
            if (! isset($data['starts_at'])) {
                throw ValidationException::withMessages(['starts_at' => 'A new start time is required.']);
            }
            unset($data['manage_token'], $data['reason']);
            $result = $this->bookings->reschedule($booking, $data);

            return ApiResponse::success($this->publicResult($result), ['replayed' => $result['replayed']],
                $result['replayed'] ? 200 : 201);
        });
    }

    private function type(string $publicId): MeetingType
    {
        return MeetingType::query()->withoutGlobalScope('tenant')->publiclyAvailable()
            ->where('public_id', $publicId)->firstOrFail();
    }

    private function booking(string $publicId): MeetingBooking
    {
        return MeetingBooking::query()->withoutGlobalScope('tenant')->where('public_id', $publicId)->firstOrFail();
    }

    /** @param array{booking: MeetingBooking, manage_token: string, replayed: bool} $result
     * @return array<string, mixed>
     */
    private function publicResult(array $result): array
    {
        $booking = $result['booking'];

        return [
            'public_id' => $booking->public_id,
            'status' => $booking->status,
            'starts_at' => $booking->starts_at?->toIso8601String(),
            'ends_at' => $booking->ends_at?->toIso8601String(),
            'timezone' => $booking->timezone,
            'conference_url' => $booking->conference_url,
            'location' => $booking->location,
            'manage_token' => $result['manage_token'],
        ];
    }

    private function withinTenant(int $tenantId, callable $callback): JsonResponse
    {
        $previous = $this->context->id();
        try {
            $this->context->set($tenantId);

            return $callback();
        } finally {
            $previous === null ? $this->context->clear() : $this->context->set($previous);
        }
    }
}
