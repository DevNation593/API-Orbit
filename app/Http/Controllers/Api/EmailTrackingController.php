<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailTrackingLink;
use App\Services\CampaignTelemetryService;
use App\Services\TimelineEventRecorder;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EmailTrackingController extends Controller
{
    public function __construct(private readonly TimelineEventRecorder $timeline) {}

    public function open(Request $request, string $token): Response
    {
        $link = $this->find($token, 'open');
        $this->record($request, $link, 'email.opened');
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);

        return response($gif === false ? '' : $gif, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(Request $request, string $token): RedirectResponse
    {
        $link = $this->find($token, 'click');
        $destination = $link->destination_url;
        abort_unless(is_string($destination) && in_array(parse_url($destination, PHP_URL_SCHEME), ['http', 'https'], true), 404);
        $this->record($request, $link, 'email.clicked');

        return redirect()->away($destination);
    }

    private function find(string $token, string $kind): EmailTrackingLink
    {
        return EmailTrackingLink::query()->withoutGlobalScope('tenant')
            ->where('token_hash', hash('sha256', $token))->where('kind', $kind)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->firstOrFail();
    }

    private function record(Request $request, EmailTrackingLink $link, string $event): void
    {
        $context = app(TenantContext::class);
        $previous = $context->id();
        $context->set((int) $link->tenant_id);
        try {
            $link->events()->create([
                'message_id' => $link->message_id,
                'event' => $event,
                'url_hash' => $link->destination_url === null ? null : hash('sha256', $link->destination_url),
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                'occurred_at' => now(),
            ]);
            $message = $link->message()->with('conversation.contact')->first();
            if ($message !== null) {
                $message->update(['status' => 'read', 'read_at' => $message->read_at ?? now()]);
                app(CampaignTelemetryService::class)->forMessage($message, $event === 'email.clicked' ? 'clicked' : 'opened');
                if ($message->conversation?->contact !== null) {
                    $this->timeline->record($message->conversation->contact, $event, [
                        'message_id' => (int) $message->id,
                        'conversation_id' => (int) $message->conversation_id,
                    ]);
                }
            }
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }
}
