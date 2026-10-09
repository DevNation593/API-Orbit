<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Preferencias de comunicación</title>
    <style>
        body { font: 16px/1.5 system-ui, sans-serif; background: #f4f6f8; color: #182331; margin: 0; padding: 32px 16px; }
        main { max-width: 520px; margin: 5vh auto; padding: 32px; background: white; border-radius: 12px; }
        h1 { font-size: 24px; line-height: 1.2; }
        label { display: block; padding: 12px 0; }
        button { padding: 12px 18px; border: 0; border-radius: 6px; background: #164e63; color: white; font: inherit; cursor: pointer; }
        .success { color: #166534; }
    </style>
</head>
<body>
<main>
    <h1>Preferencias de comunicación</h1>
    @if ($updated)
        <p class="success" role="status">Tu solicitud de baja se ha registrado.</p>
    @endif
    <p>Selecciona los canales de los que deseas darte de baja.</p>
    <form method="post" action="{{ url('/api/v1/public/preferences/'.$token) }}">
        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
        @foreach (['email' => 'Correo electrónico', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'] as $channel => $label)
            @if (in_array($channel, $available, true))
                <label>
                    <input type="checkbox" name="preferences[{{ $channel }}]" value="opt_out" @checked($channel === 'email')>
                    {{ $label }} — {{ $preferences[$channel] === 'opt_in' ? 'Suscrito' : ($preferences[$channel] === 'opt_out' ? 'Dado de baja' : 'Sin consentimiento registrado') }}
                </label>
            @endif
        @endforeach
        <button type="submit">Confirmar baja</button>
    </form>
    <p><small>Este enlace permite gestionar únicamente las bajas. No compartas el enlace.</small></p>
</main>
</body>
</html>
