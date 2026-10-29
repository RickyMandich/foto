<x-layout title="Servizio non disponibile">
    <div class="flex flex-col items-center justify-center py-20 text-center">
        <h1 class="text-4xl font-bold tracking-tight text-neutral-100 sm:text-5xl">503</h1>
        <p class="mt-4 text-lg text-neutral-400">Il sito è temporaneamente in manutenzione. Torna presto a trovarci.</p>
        <div class="mt-8">
            <x-button :href="Route::has('home') ? route('home') : url('/')" variant="primary">
                Torna alla home
            </x-button>
        </div>
    </div>
</x-layout>

