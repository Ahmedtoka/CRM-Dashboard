{{-- One policy section: heading, body paragraphs, bullet items ([label, text] or text), after paragraphs, optional link to another legal page. --}}
<section id="{{ $section['id'] }}" class="scroll-mt-32 pt-8" aria-labelledby="{{ $section['id'] }}-heading">
    <h2 id="{{ $section['id'] }}-heading" class="text-lg font-semibold sm:text-xl">
        <a href="#{{ $section['id'] }}" class="hover:text-primary">{{ $section['heading'] }}</a>
    </h2>

    @foreach ($section['body'] ?? [] as $paragraph)
        <p class="mt-3 text-[15px] leading-8">{{ $rich($paragraph) }}</p>
    @endforeach

    @if (! empty($section['items']))
        <ul class="mt-3 space-y-2.5 text-[15px] leading-8">
            @foreach ($section['items'] as $item)
                <li class="flex gap-3">
                    <span class="mt-3 size-1.5 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
                    <span>
                        @if (is_array($item))
                            <strong class="font-semibold">{{ $item[0] }}:</strong> {{ $rich($item[1]) }}
                        @else
                            {{ $rich($item) }}
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif

    @foreach ($section['after'] ?? [] as $paragraph)
        <p class="mt-3 text-[15px] leading-8">{{ $rich($paragraph) }}</p>
    @endforeach

    @if (! empty($section['link']))
        <a href="{{ route('legal.'.$section['link'], ['lang' => $locale]) }}"
           class="mt-4 inline-flex items-center gap-2 rounded-lg border bg-accent px-4 py-2 text-sm font-semibold text-primary underline-offset-4 hover:underline">
            {{ data_get($ui, 'nav.'.str_replace('-', '_', $section['link'])) }}
            <span class="rtl-flip" aria-hidden="true">→</span>
        </a>
    @endif
</section>
