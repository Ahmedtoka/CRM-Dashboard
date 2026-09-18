@php
    $dir = $locale === 'ar' ? 'rtl' : 'ltr';
    $pages = ['privacy' => 'legal.privacy', 'terms' => 'legal.terms', 'data-deletion' => 'legal.data-deletion'];
    $navKey = fn (string $p) => 'nav.'.str_replace('-', '_', $p);
    $pageUrl = fn (string $p, ?string $lang = null) => route($pages[$p], ['lang' => $lang ?? $locale]);
    $email = $contactEmail;
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $content['title'] }} · {{ $company }}</title>
        <meta name="description" content="{{ $content['description'] }}">
        <meta name="robots" content="index, follow">
        <link rel="canonical" href="{{ $pageUrl($page) }}">
        <link rel="alternate" hreflang="ar" href="{{ $pageUrl($page, 'ar') }}">
        <link rel="alternate" hreflang="en" href="{{ $pageUrl($page, 'en') }}">
        <meta property="og:title" content="{{ $content['title'] }} · {{ $company }}">
        <meta property="og:description" content="{{ $content['description'] }}">
        <meta property="og:type" content="website">
        {{-- Visitors follow their system theme unless they picked one inside the app. --}}
        <script>
            (function () {
                try {
                    var a = localStorage.getItem('appearance') || 'system';
                    var dark = a === 'dark' || (a === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', dark);
                } catch (e) {}
            })();
        </script>
        @vite(['resources/js/legal.ts'])
    </head>
    <body class="min-h-svh bg-background font-sans text-foreground antialiased">
        <a href="#content" class="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-card focus:px-3 focus:py-2">{{ $ui['skip'] }}</a>

        <header class="sticky top-0 z-40 border-b bg-card/90 backdrop-blur supports-[backdrop-filter]:bg-card/75">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
                <a href="{{ $pageUrl('privacy') }}" class="flex min-w-0 items-center gap-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                        <svg viewBox="0 0 40 40" fill="none" class="size-6" aria-hidden="true">
                            <path fill="currentColor" d="M8 4h18a6 6 0 0 1 6 6v10a6 6 0 0 1-6 6H15.5L9 31.5V26H8a6 6 0 0 1-6-6V10a6 6 0 0 1 6-6Z" />
                            <path fill="currentColor" opacity="0.45" d="M34 13.2A6 6 0 0 1 38 19v9a6 6 0 0 1-6 6h-1v4.5L25.5 34H18a6 6 0 0 1-4.8-2.4h13.3a7.5 7.5 0 0 0 7.5-7.5V13.2Z" />
                            <circle cx="11" cy="15" r="2" fill="currentColor" class="text-primary" />
                            <circle cx="17" cy="15" r="2" fill="currentColor" class="text-primary" />
                            <circle cx="23" cy="15" r="2" fill="currentColor" class="text-primary" />
                        </svg>
                    </span>
                    <span class="min-w-0 leading-tight">
                        <span class="block truncate text-base font-bold">{{ $company }}</span>
                        <span class="block truncate text-xs text-muted-foreground">{{ $ui['brand_tagline'] }}</span>
                    </span>
                </a>

                <a href="{{ $switchUrl }}" hreflang="{{ $otherLocale }}" lang="{{ $otherLocale }}"
                   aria-label="{{ $ui['switch_language_label'] }}" title="{{ $ui['switch_language_label'] }}"
                   class="inline-flex h-9 shrink-0 items-center gap-2 rounded-full border bg-card px-3 text-sm font-semibold hover:bg-accent">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-4 text-muted-foreground" aria-hidden="true"><path d="m5 8 6 6"/><path d="m4 14 6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="m22 22-5-10-5 10"/><path d="M14 18h6"/></svg>
                    <span class="sm:hidden">{{ $ui['switch_language_short'] }}</span>
                    <span class="hidden sm:inline">{{ $ui['switch_language'] }}</span>
                </a>
            </div>

            <nav aria-label="{{ $company }}" class="mx-auto max-w-5xl px-4 sm:px-6">
                <ul class="-mb-px flex gap-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    @foreach (array_keys($pages) as $p)
                        <li class="shrink-0">
                            <a href="{{ $pageUrl($p) }}" @if ($p === $page) aria-current="page" @endif
                               class="inline-block whitespace-nowrap border-b-2 px-2 py-2.5 text-sm sm:px-3 font-medium transition-colors {{ $p === $page ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}">
                                {{ data_get($ui, $navKey($p)) }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </header>

        <main id="content" class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-12">
            <div class="flex gap-10">
                @if (! empty($content['sections']))
                    <aside class="hidden w-56 shrink-0 lg:block">
                        <div class="sticky top-32">
                            <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ $ui['on_this_page'] }}</p>
                            <ul class="space-y-1 border-s text-sm">
                                @if (! empty($content['methods']))
                                    <li><a href="#methods" class="-ms-px block border-s-2 border-transparent py-1 ps-3 text-muted-foreground hover:border-primary hover:text-foreground">{{ $content['methods_heading'] }}</a></li>
                                @endif
                                @foreach ($content['sections'] as $section)
                                    <li><a href="#{{ $section['id'] }}" class="-ms-px block border-s-2 border-transparent py-1 ps-3 text-muted-foreground hover:border-primary hover:text-foreground">{{ $section['heading'] }}</a></li>
                                @endforeach
                                @if ($page === 'data-deletion')
                                    <li><a href="#status" class="-ms-px block border-s-2 border-transparent py-1 ps-3 text-muted-foreground hover:border-primary hover:text-foreground">{{ $ui['lookup']['heading'] }}</a></li>
                                @endif
                            </ul>
                        </div>
                    </aside>
                @endif

                <article class="mx-auto min-w-0 max-w-3xl flex-1">
                    <div class="rounded-xl border bg-card p-5 shadow-[var(--shadow-card)] sm:p-10">
                        <header class="border-b pb-6">
                            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $content['title'] }}</h1>
                            <p class="mt-2 text-sm text-muted-foreground">{{ str_replace(':date', $lastUpdated, $ui['last_updated']) }}</p>
                            <div class="mt-5 space-y-3 text-base leading-8">
                                @foreach ($content['intro'] as $paragraph)
                                    <p>{{ $rich($paragraph) }}</p>
                                @endforeach
                            </div>
                        </header>

                        <div>
                            @yield('body')
                        </div>
                    </div>

                    <p class="mt-6 text-center">
                        <a href="#content" class="text-sm text-muted-foreground hover:text-foreground">{{ $ui['back_to_top'] }} ↑</a>
                    </p>
                </article>
            </div>
        </main>

        <footer class="border-t bg-card">
            <div class="mx-auto flex max-w-5xl flex-col gap-4 px-4 py-8 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <nav aria-label="{{ $ui['contact'] }}">
                    <ul class="flex flex-wrap items-center gap-x-2 gap-y-1 text-muted-foreground">
                        @foreach (array_keys($pages) as $i => $p)
                            @if ($i > 0)<li aria-hidden="true">·</li>@endif
                            <li><a href="{{ $pageUrl($p) }}" class="hover:text-foreground hover:underline">{{ data_get($ui, $navKey($p)) }}</a></li>
                        @endforeach
                        <li aria-hidden="true">·</li>
                        <li>
                            @if ($email)
                                <a href="mailto:{{ $email }}" class="hover:text-foreground hover:underline">{{ $ui['contact'] }}: <span dir="ltr">{{ $email }}</span></a>
                            @else
                                {{ $ui['contact_page'] }}
                            @endif
                        </li>
                    </ul>
                </nav>
                <p class="text-muted-foreground">{{ strtr($ui['rights'], [':year' => now()->year, ':company' => $company]) }}</p>
            </div>
        </footer>
    </body>
</html>
