<?php

namespace App\Jobs;

use App\Models\MeetingBooking;
use App\Services\Calendar\CalendarProviderManager;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncMeetingBookingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    public array $backoff = [30, 120, 300, 900];

    public function __construct(
        public readonly int $tenantId,
        public readonly int $bookingId,
        public readonly string $operation = 'sync',
    ) {}

    public function handle(CalendarProviderManager $providers, TenantContext $context): void
    {
        $previous = $context->id();
        try {
            $context->set($this->tenantId);
            $booking = MeetingBooking::query()->with([
                'meetingType', 'calendarConnection.integration', 'participants',
            ])->findOrFail($this->bookingId);
            if ($booking->calendarConnection === null) {
                $booking->update(['sync_status' => $this->operation === 'cancel' ? 'cancelled' : 'not_required']);

                return;
            }
            $provider = $providers->for($booking->calendarConnection->provider);
            if ($this->operation === 'cancel') {
                $provider->cancel($booking);
                $booking->update(['sync_status' => 'cancelled', 'sync_error' => null]);

                return;
            }
            if ($booking->status !== 'confirmed'
                || ($booking->sync_status === 'synced' && filled($booking->external_event_id))) {
                return;
            }
            $result = $provider->create($booking);
            $booking->update([
                'sync_status' => 'synced',
                'sync_error' => null,
                'provider' => $booking->calendarConnection->provider,
                'external_event_id' => $result['external_event_id'],
                'conference_url' => $result['conference_url'] ?? $booking->conference_url,
                'location' => $result['location'] ?? $booking->location,
            ]);
        } catch (Throwable $exception) {
            MeetingBooking::query()->whereKey($this->bookingId)->update([
                'sync_status' => 'failed',
                'sync_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
            throw $exception;
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        try {
            $context->set($this->tenantId);
            MeetingBooking::query()->whereKey($this->bookingId)->update([
                'sync_status' => 'failed',
                'sync_error' => 'Calendar synchronization failed after all retries.',
            ]);
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
