<x-layout title="Sessione scaduta">
    <div class="flex flex-col items-center justify-center py-20 text-center">
        <h1 class="text-4xl font-bold tracking-tight text-neutral-100 sm:text-5xl">419</h1>
        <p class="mt-4 text-lg text-neutral-400">La sessione è scaduta. Ricarica la pagina o torna alla home.</p>
        <div class="mt-8">
            <x-button :href="Route::has('home') ? route('home') : url('/')" variant="primary">
                Torna alla home
            </x-button>
        </div>
    </div>
</x-layout>

