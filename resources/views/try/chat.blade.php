{{--
    The tester's page on a public test link (design 2026-09-21 §2).

    English chrome, Arabic conversation: the shell is `lang="en" dir="ltr"` and every
    bubble carries `dir="auto"`, so the bot's Arabic lays itself out right-to-left
    inside a page the whole team reads the same way. `noindex` everywhere — the token
    is the only secret this link has.
--}}
<!DOCTYPE html>
<html lang="en" dir="ltr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="robots" content="noindex, nofollow, noarchive">
        <meta name="referrer" content="no-referrer">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#ffffff">
        <title>{{ $link->label }} · Test chat</title>
        @vite(['resources/js/try.ts'])
    </head>
    <body>
        <noscript>
            <div style="padding:24px;font-family:sans-serif;text-align:center">
                This test chat needs JavaScript. Please open the link in your phone's normal browser.
            </div>
        </noscript>

        <div id="try-app" data-token="{{ $token }}" data-state="{{ json_encode($state, JSON_UNESCAPED_UNICODE) }}"></div>
    </body>
</html>
