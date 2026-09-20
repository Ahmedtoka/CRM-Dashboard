{{--
    A link that was stopped, has expired, or never existed (design 2026-09-21 §1).
    Plain HTML with no build step behind it, so a dead link costs nothing to serve.
--}}
<!DOCTYPE html>
<html lang="en" dir="ltr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="robots" content="noindex, nofollow, noarchive">
        <meta name="referrer" content="no-referrer">
        <title>Test chat closed</title>
        <style>
            html, body { height: 100%; margin: 0; }
            body {
                display: grid;
                place-items: center;
                padding: 24px;
                background: #ebedf0;
                color: #050505;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
                text-align: center;
            }
            .card { max-width: 340px; padding: 32px 24px; background: #fff; border-radius: 16px; }
            h1 { margin: 0 0 8px; font-size: 22px; font-weight: 700; letter-spacing: -0.02em; }
            p { margin: 0; color: #65676b; font-size: 15px; line-height: 1.4; }
            .mark {
                width: 44px; height: 44px; margin: 0 auto 16px; border-radius: 50%;
                background: linear-gradient(160deg, #00b2ff, #006aff);
            }
        </style>
    </head>
    <body>
        <main class="card">
            <div class="mark" aria-hidden="true"></div>
            <h1>This chat is closed</h1>
            <p>
                @switch($reason)
                    @case('expired') The test link has expired. Ask whoever sent it for a new one. @break
                    @case('stopped') The test link has been turned off. Ask whoever sent it for a new one. @break
                    @default We couldn't find this test link. Check the address, or ask for a new one.
                @endswitch
            </p>
        </main>
    </body>
</html>
