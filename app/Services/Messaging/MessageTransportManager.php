<?php

namespace App\Services\Messaging;

use App\Contracts\MessageTransport;
use Illuminate\Validation\ValidationException;

final class MessageTransportManager
{
    /** @var array<int, MessageTransport> */
    private array $transports = [];

    public function register(MessageTransport $transport): void
    {
        $this->transports[] = $transport;
    }

    public function for(string $channel): MessageTransport
    {
        foreach ($this->transports as $transport) {
            if ($transport->supports($channel)) {
                return $transport;
            }
        }

        throw ValidationException::withMessages([
            'channel' => "No operational transport is configured for channel [$channel].",
        ]);
    }
}
