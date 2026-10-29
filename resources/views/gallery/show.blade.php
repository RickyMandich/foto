<x-layout :title="$selection->title()" :app="true">
    @php
        $initialPhoto = $photos[$startIndex] ?? $photos->first();
        $isGrid = $grid;
    @endphp

    <div data-module="gallery" class="flex h-full flex-col" data-view="{{ $isGrid ? 'grid' : 'carousel' }}">
        <!-- Barra strumenti -->
        <header
            class="flex h-14 shrink-0 items-center justify-between gap-4 border-b border-neutral-800 bg-neutral-900/90 px-4 backdrop-blur overflow-x-auto z-20">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('home') }}"
                    class="inline-flex size-9 items-center justify-center rounded-lg text-neutral-400 hover:bg-neutral-800 hover:text-white transition"
                    aria-label="Torna agli eventi">
                    <x-icon name="arrow-left" class="size-5" />
                </a>

                <div class="min-w-0">
                    <h1 class="truncate text-sm font-semibold text-neutral-100 sm:text-base">
                        {{ $selection->title() }}
                    </h1>
                    <div class="flex items-center gap-2 text-xs text-neutral-400 truncate">
                        <span>{{ count($photos) }} foto</span>
                        @if ($tracks->isNotEmpty())
                            <span>• {{ count($tracks) }} brani</span>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Controlli centro / destra -->
            <div class="flex items-center gap-2 shrink-0">
                <!-- Autoplay (solo carosello) -->
                <div id="autoplay-controls" class="flex items-center gap-1 border-r border-neutral-800 pr-2">
                    <button id="autoplay-toggle" type="button"
                        class="inline-flex size-9 items-center justify-center rounded-lg text-neutral-400 hover:bg-neutral-800 hover:text-white transition"
                        aria-pressed="false" aria-label="Avvia riproduzione automatica">
                        <x-icon name="play" class="size-5" />
                    </button>
                    <input id="autoplay-interval" type="number" min="1" max="60" value="4"
                        class="w-12 rounded bg-neutral-800 px-1 py-1 text-center text-xs text-neutral-200 border border-neutral-700"
                        title="Secondi per foto" aria-label="Secondi per foto">
                </div>

                <!-- Toggle vista -->
                <div role="radiogroup" aria-label="Modalità di visualizzazione"
                    class="flex items-center rounded-lg bg-neutral-800 p-0.5">
                    <button id="btn-view-carousel" type="button" role="radio"
                        aria-checked="{{ !$isGrid ? 'true' : 'false' }}" aria-label="Vista carosello"
                        class="inline-flex size-8 items-center justify-center rounded-md {{ !$isGrid ? 'bg-neutral-700 text-white' : 'text-neutral-400 hover:text-white' }}">
                        <x-icon name="photo" class="size-4" />
                    </button>
                    <button id="btn-view-grid" type="button" role="radio"
                        aria-checked="{{ $isGrid ? 'true' : 'false' }}" aria-label="Vista griglia"
                        class="inline-flex size-8 items-center justify-center rounded-md {{ $isGrid ? 'bg-neutral-700 text-white' : 'text-neutral-400 hover:text-white' }}">
                        <x-icon name="squares-2x2" class="size-4" />
                    </button>
                </div>

                <!-- Download singola foto -->
                <a id="download-photo" href="{{ $initialPhoto->url('original') }}"
                    download="{{ $initialPhoto->filename }}"
                    class="inline-flex size-9 items-center justify-center rounded-lg text-neutral-400 hover:bg-neutral-800 hover:text-white transition"
                    aria-label="Scarica foto originale" title="Scarica foto originale">
                    <x-icon name="arrow-down-tray" class="size-5" />
                </a>

                <!-- Condivisione (placeholder piano 08) -->
                <button id="share-photo" type="button"
                    class="inline-flex size-9 items-center justify-center rounded-lg text-neutral-400 hover:bg-neutral-800 hover:text-white transition"
                    aria-label="Condividi foto" title="Condividi foto">
                    <x-icon name="share" class="size-5" />
                </button>

                <!-- Fullscreen (placeholder piano 05) -->
                <button id="fullscreen" type="button"
                    class="inline-flex size-9 items-center justify-center rounded-lg text-neutral-400 hover:bg-neutral-800 hover:text-white transition"
                    aria-label="Schermo intero" title="Schermo intero">
                    <x-icon name="arrows-pointing-out" class="size-5" />
                </button>

                <!-- Audio controls se presenti brani -->
                @if ($tracks->isNotEmpty())
                    <div id="audio-controls" class="flex items-center gap-1.5 border-l border-neutral-800 pl-2">
                        <button id="audio-play" type="button"
                            class="inline-flex size-9 items-center justify-center rounded-lg text-neutral-400 hover:bg-neutral-800 hover:text-white transition"
                            aria-label="Riproduci musica">
                            <x-icon name="speaker-wave" class="size-5" />
                        </button>
                        <button id="audio-next" type="button"
                            class="inline-flex size-9 items-center justify-center rounded-lg text-neutral-400 hover:bg-neutral-800 hover:text-white transition"
                            aria-label="Brano successivo">
                            <x-icon name="forward" class="size-5" />
                        </button>
                        <input id="audio-volume" type="range" min="0" max="1" step="0.05"
                            value="0.6" class="w-16 accent-sky-400 hidden md:inline" aria-label="Volume">
                        <span id="audio-title"
                            class="hidden max-w-[14vw] truncate text-xs text-sky-300 lg:inline"></span>
                    </div>
                @endif

                <!-- Bottone scarica tutte ZIP -->
                <a href="{{ $selection->zipUrl() }}"
                    class="ml-2 inline-flex items-center gap-1.5 rounded-lg bg-sky-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-sky-500 transition shrink-0"
                    title="Scarica tutte le foto della selezione in uno ZIP">
                    <x-icon name="archive-box-arrow-down" class="size-4" />
                    <span class="hidden sm:inline">Scarica tutte (ZIP, {{ count($photos) }} foto,
                        {{ $selection->formattedSize() }})</span>
                    <span class="sm:hidden">ZIP</span>
                </a>
            </div>
        </header>

        <!-- Area Carosello -->
        <section id="carousel-view"
            class="{{ $isGrid ? 'hidden' : 'relative flex flex-1 min-h-0 bg-black items-center justify-center overflow-hidden' }}">
            <div id="carousel" class="relative h-full w-full flex items-center justify-center"
                aria-roledescription="carosello" aria-label="{{ $selection->title() }}">
                <div id="counter"
                    class="absolute top-4 right-4 z-10 rounded-full bg-black/60 px-3 py-1 text-xs text-white backdrop-blur">
                    {{ $startIndex + 1 }}/{{ count($photos) }}
                </div>

                <button id="prev" type="button" aria-label="Foto precedente"
                    class="absolute left-4 z-10 inline-flex size-11 items-center justify-center rounded-full bg-black/40 text-white/80 hover:bg-black/70 hover:text-white transition">
                    <x-icon name="chevron-left" class="size-6" />
                </button>

                <div id="carousel-track" class="flex h-full w-full snap-x snap-mandatory overflow-x-auto">
                    <div class="flex h-full w-full shrink-0 items-center justify-center snap-center">
                        <img src="{{ $initialPhoto->url('web') }}"
                            alt="Foto {{ $startIndex + 1 }} di {{ count($photos) }} — {{ $selection->title() }} ({{ $initialPhoto->gallery->author->name ?? $initialPhoto->gallery->author->folder }})"
                            fetchpriority="high" class="max-h-full max-w-full object-contain select-none">
                    </div>
                </div>

                <button id="next" type="button" aria-label="Foto successiva"
                    class="absolute right-4 z-10 inline-flex size-11 items-center justify-center rounded-full bg-black/40 text-white/80 hover:bg-black/70 hover:text-white transition">
                    <x-icon name="chevron-right" class="size-6" />
                </button>
            </div>
        </section>

        <!-- Area Griglia Server-Side -->
        <section id="grid-view"
            class="{{ !$isGrid ? 'hidden' : '' }} flex-1 min-h-0 overflow-y-auto p-4 sm:p-6 bg-neutral-950">
            <!-- Eventuali filtri autori se ci sono più autori -->
            @if ($event && $event->galleries->count() > 1)
                <div class="mb-6 flex flex-wrap items-center gap-2 border-b border-neutral-800 pb-4">
                    <span class="text-xs font-semibold text-neutral-400 uppercase tracking-wider mr-2">Filtra
                        autore:</span>
                    <a href="{{ route('events.show', $event->date) }}"
                        class="rounded-full px-3 py-1 text-xs font-medium {{ empty(request()->query('autori')) ? 'bg-sky-500 text-white' : 'bg-neutral-800 text-neutral-300 hover:bg-neutral-700' }}">
                        Tutti
                    </a>
                    @foreach ($event->galleries as $gal)
                        @php
                            $slug = $gal->author->slug;
                            $isActive = in_array($slug, (array) request()->query('autori', []));
                            $authorUrl = route('events.show', [$event->date, 'autori' => [$slug]]);
                        @endphp
                        <a href="{{ $authorUrl }}"
                            class="rounded-full px-3 py-1 text-xs font-medium {{ $isActive ? 'bg-sky-500 text-white' : 'bg-neutral-800 text-neutral-300 hover:bg-neutral-700' }}">
                            {{ $gal->author->name }}
                        </a>
                    @endforeach
                </div>
            @endif

            <ul class="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                @foreach ($photos as $i => $photo)
                    <li class="relative aspect-square overflow-hidden rounded-lg bg-neutral-900"
                        style="content-visibility: auto">
                        <a href="{{ $photo->url('original') }}" data-index="{{ $i }}"
                            class="group block h-full w-full">
                            <img src="{{ $photo->url('thumb') }}" loading="lazy" decoding="async"
                                alt="Foto {{ $i + 1 }} di {{ count($photos) }} — {{ $selection->title() }} ({{ $photo->gallery->author->name ?? $photo->gallery->author->folder }})"
                                class="h-full w-full object-cover transition duration-200 group-hover:scale-105">
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>

        <!-- Elemento Audio se ci sono brani -->
        @if ($tracks->isNotEmpty())
            <audio id="audio-player" preload="metadata" hidden></audio>
        @endif

        <!-- Dati per JavaScript -->
        <script type="application/json" id="gallery-data">{!! json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    </div>

    <noscript>
        <style>
            #carousel-view {
                display: none !important;
            }

            #grid-view {
                display: block !important;
            }
        </style>
    </noscript>
</x-layout>
