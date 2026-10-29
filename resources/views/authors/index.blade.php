<x-layout title="Autori" description="Tutti gli autori e fotografi">
    <div class="space-y-6">
        <div class="border-b border-neutral-800 pb-4">
            <h1 class="text-2xl font-bold tracking-tight text-neutral-100 sm:text-3xl">Autori</h1>
            <p class="mt-1 text-sm text-neutral-400">Le persone che hanno contribuito con le loro fotografie e registrazioni.</p>
        </div>

        @if ($authors->isEmpty())
            <div class="rounded-xl border border-dashed border-neutral-800 p-12 text-center text-neutral-500">
                <x-icon name="user" class="mx-auto size-12 stroke-1 text-neutral-600 mb-3" />
                <p class="text-base font-medium text-neutral-300">Nessun autore trovato.</p>
            </div>
        @else
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($authors as $author)
                    <article class="group relative flex flex-col justify-between rounded-xl border border-neutral-800 bg-neutral-900 p-5 transition hover:border-neutral-700 hover:shadow-lg">
                        <div>
                            <div class="flex items-center gap-3">
                                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-neutral-800 text-sky-400">
                                    <x-icon name="user" class="size-5" />
                                </div>
                                <h2 class="text-lg font-semibold text-neutral-100 group-hover:text-sky-300 transition">
                                    <a href="{{ route('authors.show', $author->slug) }}">
                                        <span class="absolute inset-0 z-10" aria-hidden="true"></span>
                                        {{ $author->name }}
                                    </a>
                                </h2>
                            </div>

                            @if ($author->bio)
                                <p class="mt-3 text-xs text-neutral-400 line-clamp-2">
                                    {{ \Illuminate\Support\Str::limit(strip_tags($author->bio), 140) }}
                                </p>
                            @endif
                        </div>

                        <div class="mt-5 flex items-center gap-2 border-t border-neutral-800/80 pt-3 text-xs text-neutral-400">
                            <x-badge>
                                <x-icon name="calendar" class="size-3.5" />
                                {{ $author->galleries->count() }} {{ $author->galleries->count() === 1 ? 'evento' : 'eventi' }}
                            </x-badge>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</x-layout>

