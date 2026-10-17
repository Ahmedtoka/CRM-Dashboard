@php
    // Inside Shopify admin the page is always Arabic (the owner's language); no session, no locale switch.
    $t = fn (string $key) => __('shopify_app.'.$key, [], 'ar');
    $error ??= null;
    $scriptText = [
        'connected' => $t('connected'), 'not_connected' => $t('not_connected'), 'never' => $t('never'),
        'mode_app' => $t('mode_app'), 'mode_custom_token' => $t('mode_custom_token'),
        'errors' => __('shopify_app.errors', [], 'ar'),
    ];
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="shopify-api-key" content="{{ $apiKey }}">
    {{-- App Bridge must be the first script on the page (shopify.dev App Bridge); it adds the ID token to same-origin fetch calls. --}}
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <title>{{ $t('title') }}</title>
    <style>
        :root { color-scheme: light; --ink: #1f2124; --muted: #616a75; --line: #e3e3e3; --ok: #0a7a4b; --bad: #b42318; --bg: #f6f6f7; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--ink); font: 15px/1.6 system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif; }
        main { max-width: 560px; margin: 24px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 20px; }
        h1 { font-size: 18px; margin: 0 0 12px; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 0 0 16px; }
        dt { color: var(--muted); }
        dd { margin: 0; overflow-wrap: anywhere; }
        .ok { color: var(--ok); } .bad { color: var(--bad); }
        .muted { color: var(--muted); font-size: 13px; }
        a.button { display: inline-block; background: var(--ink); color: #fff; text-decoration: none; padding: 8px 16px; border-radius: 8px; }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
<main>
    <section class="card">
        <h1 id="headline" class="{{ $error ? 'bad' : ($status ? 'ok' : '') }}">
            @if ($error)
                {{ $t('errors.'.$error) }}
            @elseif ($status)
                {{ $status['status'] === 'connected' ? $t('connected') : $t('not_connected') }}
            @else
                {{ $t('connecting') }}
            @endif
        </h1>

        <dl id="details" @if (! $status) hidden @endif>
            <dt>{{ $t('shop') }}</dt><dd id="shop">{{ $status['shop_name'] ?? $status['shop_domain'] ?? '' }}</dd>
            <dt>{{ $t('mode') }}</dt><dd id="mode">{{ isset($status['auth_mode']) ? $t('mode_'.$status['auth_mode']) : '' }}</dd>
            <dt>{{ $t('token_valid_until') }}</dt><dd><span id="token" dir="ltr">{{ $status['access_token_expires_at'] ?? '' }}</span> <span class="muted">({{ $t('token_note') }})</span></dd>
            <dt>{{ $t('last_webhook') }}</dt><dd id="webhook" dir="ltr">{{ $status['last_webhook_at'] ?? $t('never') }}</dd>
        </dl>

        <p><a class="button" href="{{ $crmUrl }}" target="_blank" rel="noopener">{{ $t('open_crm') }}</a></p>
        <p class="muted">{{ $t('open_crm_note') }}</p>
    </section>
</main>

@if (! $status && ! $error)
<script>
    // No id_token on the URL: App Bridge adds the ID token to this same-origin request's Authorization header.
    (function () {
        const text = @json($scriptText);
        const headline = document.getElementById('headline');
        fetch('/shopify/app/session', { method: 'POST', headers: { Accept: 'application/json' } })
            .then((response) => response.json().then((body) => ({ ok: response.ok, body })))
            .then(({ ok, body }) => {
                if (!ok) {
                    headline.textContent = text.errors[body.error] || text.errors.generic;
                    headline.className = 'bad';
                    return;
                }
                headline.textContent = body.status === 'connected' ? text.connected : text.not_connected;
                headline.className = 'ok';
                document.getElementById('shop').textContent = body.shop_name || body.shop_domain;
                document.getElementById('mode').textContent = text['mode_' + body.auth_mode] || body.auth_mode;
                document.getElementById('token').textContent = body.access_token_expires_at || '';
                document.getElementById('webhook').textContent = body.last_webhook_at || text.never;
                document.getElementById('details').hidden = false;
            })
            .catch(() => {
                headline.textContent = text.errors.generic;
                headline.className = 'bad';
            });
    })();
</script>
@endif
</body>
</html>
