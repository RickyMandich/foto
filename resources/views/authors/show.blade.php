<x-layout :title="$author->name" :description="$author->bio ? \Illuminate\Support\Str::limit(strip_tags($author->bio), 160) : 'Foto di '.$author->name">
    <div class="space-y-8">
        <!-- Scheda Profilo Autore -->
        <header class="rounded-xl border border-neutral-800 bg-neutral-900/60 p-6 sm:p-8">
            <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-6">
                <div class="space-y-3">
                    <h1 class="text-3xl font-bold tracking-tight text-neutral-100 sm:text-4xl">
                        {{ $author->name }}
                    </h1>

                    @if ($author->bioHtml())
                        <div class="prose prose-invert prose-sm max-w-none text-neutral-300">
                            {{ $author->bioHtml() }}
                        </div>
                    @endif

                    @if (!empty($author->contactLinks()))
                        <div class="flex flex-wrap gap-3 pt-2">
                            @foreach ($author->contactLinks() as $link)
                                <a 
                                    href="{{ $link['href'] }}" 
                                    @if ($link['external']) target="_blank" rel="noopener noreferrer nofollow" @endif
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-700 bg-neutral-800 px-3 py-1.5 text-xs text-neutral-200 hover:border-neutral-600 hover:text-white transition"
                                >
                                    <x-icon :name="$link['icon']" class="size-3.5" />
                                    <span>{{ $link['label'] }}: {{ $link['text'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                <!-- Azioni globali autore -->
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 shrink-0">
                    <x-button :href="route('gallery.show', ['autori' => [$author->slug]])" variant="primary">
                        Guarda tutte le foto
                    </x-button>

                    <x-button :href="\App\Support\GallerySelection::forAuthor($author)->zipUrl()" variant="outline">
                        <x-icon name="archive-box-arrow-down" class="size-4" />
                        <span>Scarica tutte (ZIP)</span>
                    </x-button>
                </div>
            </div>
        </header>

        <!-- Form selezione Multi-Evento ed Elenco Eventi -->
        <form method="get" action="{{ route('gallery.show') }}" class="space-y-6">
            <input type="hidden" name="autori[]" value="{{ $author->slug }}">

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-neutral-800 pb-4">
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-neutral-100">Eventi documentati</h2>
                    <p class="text-xs text-neutral-400">Seleziona uno o più eventi per vederli o scaricarli insieme.</p>
                </div>

                <div class="flex items-center gap-3">
                    <x-button type="submit" variant="primary">
                        Guarda selezione
                    </x-button>

                    <x-button type="submit" formaction="{{ route('download.zip') }}" variant="outline">
                        <x-icon name="archive-box-arrow-down" class="size-4" />
                        <span>Scarica ZIP</span>
                    </x-button>
                </div>
            </div>

            @if ($events->isEmpty())
                <div class="rounded-xl border border-dashed border-neutral-800 p-8 text-center text-neutral-500">
                    Nessun evento disponibile per questo autore.
                </div>
            @else
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($events as $event)
                        <div class="relative">
                            <label class="absolute top-3 left-3 z-20 flex items-center gap-2 rounded-md bg-neutral-900/90 border border-neutral-700 px-2.5 py-1 text-xs text-neutral-200 backdrop-blur cursor-pointer select-none">
                                <input type="checkbox" name="eventi[]" value="{{ $event->date }}" class="rounded border-neutral-700 bg-neutral-800 text-sky-500 focus:ring-sky-400 size-4">
                                <span>Seleziona</span>
                            </label>

                            <x-event-card 
                                :event="$event" 
                                :cover="$covers[$event->id] ?? null"
                                :href="route('events.show', [$event->date, 'autori' => [$author->slug]])"
                            />
                        </div>
                    @endforeach
                </div>

                <div class="mt-8">
                    {{ $events->links() }}
                </div>
            @endif
        </form>
    </div>
</x-layout>

