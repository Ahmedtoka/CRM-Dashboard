@extends('legal.layout')

@php
    $methods = collect($content['methods'])->reject(fn ($m, $key) => $key === 'email' && ! $contactEmail);
    $icons = [
        'page' => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        'email' => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'facebook' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
    ];
    $status = $lookup['status'] ?? null;
    $tone = [
        'pending' => 'border-warning/40 bg-warning/10',
        'completed' => 'border-success/40 bg-success/10',
        'not_found' => 'border-border bg-muted',
    ];
    $badgeTone = [
        'pending' => 'bg-warning text-warning-foreground',
        'completed' => 'bg-success text-success-foreground',
        'not_found' => 'bg-secondary text-secondary-foreground',
    ];
    $dateFormat = fn ($date) => $date?->locale($locale === 'ar' ? 'ar_EG' : 'en')->translatedFormat('j F Y');
@endphp

@section('body')
    <section id="methods" class="scroll-mt-32 pt-8" aria-labelledby="methods-heading">
        <h2 id="methods-heading" class="text-lg font-semibold sm:text-xl">{{ $content['methods_heading'] }}</h2>
        <ol class="mt-4 grid gap-3">
            @foreach ($methods as $key => $method)
                <li class="flex gap-4 rounded-lg border p-4">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-accent text-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-5" aria-hidden="true">{!! $icons[$key] !!}</svg>
                    </span>
                    <div class="min-w-0">
                        <h3 class="font-semibold">
                            <span class="text-muted-foreground">{{ $loop->iteration }}.</span> {{ $method['title'] }}
                        </h3>
                        <p class="mt-1 text-[15px] leading-7">{{ $rich($method['body']) }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
    </section>

    @foreach ($content['sections'] as $section)
        @include('legal.partials.section', ['section' => $section])
    @endforeach

    <section id="status" class="mt-10 scroll-mt-32 rounded-xl border bg-background/60 p-5 sm:p-6" aria-labelledby="status-heading">
        <h2 id="status-heading" class="text-lg font-semibold sm:text-xl">{{ $ui['lookup']['heading'] }}</h2>
        <p class="mt-2 text-[15px] leading-7 text-muted-foreground">{{ $ui['lookup']['body'] }}</p>

        <form method="GET" action="{{ route('legal.data-deletion') }}#status" class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-end">
            <input type="hidden" name="lang" value="{{ $locale }}">
            <div class="flex-1">
                <label for="code" class="mb-1 block text-sm font-medium">{{ $ui['lookup']['label'] }}</label>
                <input id="code" name="code" type="text" dir="ltr" autocomplete="off" spellcheck="false" maxlength="40" required
                       value="{{ $lookup['code'] ?? '' }}" placeholder="{{ $ui['lookup']['placeholder'] }}"
                       class="h-10 w-full rounded-md border border-input bg-card px-3 font-mono text-sm uppercase tracking-wider placeholder:font-sans placeholder:normal-case placeholder:tracking-normal placeholder:text-muted-foreground">
            </div>
            <button type="submit" class="h-10 rounded-md bg-primary px-5 text-sm font-semibold text-primary-foreground hover:bg-primary-hover">
                {{ $ui['lookup']['submit'] }}
            </button>
        </form>

        @if ($lookup)
            <div class="mt-4 rounded-lg border p-4 {{ $status ? $tone[$status] : 'border-destructive/40 bg-destructive/10' }}" role="status" aria-live="polite">
                @if ($status)
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold">{{ str_replace(':code', '', $ui['lookup']['result_for']) }}<span dir="ltr" class="font-mono">{{ strtoupper($lookup['code']) }}</span></span>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $badgeTone[$status] }}" data-status="{{ $status }}">{{ $ui['lookup']['badge'][$status] }}</span>
                    </div>
                    <p class="mt-2 text-[15px] leading-7">{{ $ui['lookup']['status'][$status] }}</p>
                    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                        <dt class="text-muted-foreground">{{ $ui['lookup']['requested_at'] }}</dt>
                        <dd>{{ $dateFormat($lookup['requested_at']) }}</dd>
                        @if ($lookup['completed_at'])
                            <dt class="text-muted-foreground">{{ $ui['lookup']['completed_at'] }}</dt>
                            <dd>{{ $dateFormat($lookup['completed_at']) }}</dd>
                        @endif
                    </dl>
                @else
                    <p class="text-[15px] leading-7" data-status="unknown">{{ $rich($ui['lookup']['unknown']) }}</p>
                @endif
            </div>
        @endif
    </section>
@endsection
