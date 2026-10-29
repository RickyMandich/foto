<x-layout title="Eventi" description="Gallerie fotografiche di eventi, cortei e presìdi">
    <div class="space-y-6 mt-8">
        <div class="border-b border-neutral-800 pb-4">
            <h1 class="text-2xl font-bold tracking-tight text-neutral-100 sm:text-3xl">Eventi</h1>
            <p class="mt-1 text-sm text-neutral-400">Tutti i cortei, presìdi e momenti documentati.</p>
        </div>

        @if ($events->isEmpty())
            <div class="rounded-xl border border-dashed border-neutral-800 p-12 text-center text-neutral-500">
                <x-icon name="photo" class="mx-auto size-12 stroke-1 text-neutral-600 mb-3" />
                <p class="text-base font-medium text-neutral-300">Non ci sono ancora eventi.</p>
                <p class="mt-1 text-xs">Usa <code>php artisan media:sync</code> per caricare foto dal disco.</p>
            </div>
        @else
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($events as $event)
                    <x-event-card 
                        :event="$event" 
                        :cover="$covers[$event->id] ?? null" 
                    />
                @endforeach
            </div>

            <div class="mt-8">
                {{ $events->links() }}
            </div>
        @endif
    </div>
</x-layout>

