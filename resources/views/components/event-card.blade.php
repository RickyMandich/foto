@props([
    'event',
    'cover' => null,
    'href' => null,
])

@php
    $link = $href ?? (Route::has('events.show') ? route('events.show', $event->date) : url('/eventi/'.$event->date));
@endphp

<article class="group relative flex flex-col overflow-hidden rounded-xl bg-neutral-900 border border-neutral-800 transition hover:border-neutral-700 hover:shadow-lg">
    <div class="aspect-video w-full overflow-hidden bg-neutral-950 relative">
        @if ($cover)
            <img 
                src="{{ $cover->url('thumb') }}" 
                @if ($cover->srcset()) srcset="{{ $cover->srcset() }}" sizes="(min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw" @endif
                alt="Copertina: {{ $event->displayTitle() }}" 
                loading="lazy" 
                decoding="async" 
                class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
            >
        @else
            <div class="flex h-full w-full items-center justify-center text-neutral-600">
                <x-icon name="photo" class="size-12" />
            </div>
        @endif
    </div>

    <div class="flex flex-1 flex-col p-4">
        <div class="flex items-center gap-2 text-xs text-neutral-400">
            <x-icon name="calendar" class="size-4" />
            <time datetime="{{ $event->date }}">{{ $event->day()->translatedFormat('j F Y') }}</time>
        </div>

        <h2 class="mt-2 text-lg font-semibold text-neutral-100 group-hover:text-sky-300 transition">
            <a href="{{ $link }}">
                <span class="absolute inset-0 z-10" aria-hidden="true"></span>
                {{ $event->displayTitle() }}
            </a>
        </h2>

        <div class="mt-auto pt-4 flex flex-wrap items-center gap-2">
            <x-badge>
                <x-icon name="photo" class="size-3.5" />
                {{ $event->photos_count }} {{ $event->photos_count === 1 ? 'foto' : 'foto' }}
            </x-badge>

            @if ($event->tracks_count > 0)
                <x-badge>
                    <x-icon name="musical-note" class="size-3.5" />
                    {{ $event->tracks_count }} {{ $event->tracks_count === 1 ? 'brano' : 'brani' }}
                </x-badge>
            @endif

            @if ($event->authors_count > 1)
                <x-badge>
                    <x-icon name="user" class="size-3.5" />
                    {{ $event->authors_count }} autori
                </x-badge>
            @elseif ($cover && $cover->gallery && $cover->gallery->author)
                <x-badge>
                    <x-icon name="user" class="size-3.5" />
                    {{ $cover->gallery->author->name }}
                </x-badge>
            @endif
        </div>
    </div>
</article>

