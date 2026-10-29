<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Contenuti (foto e audio)
    |--------------------------------------------------------------------------
    |
    | I contenuti stanno fuori da git, in questo disk (storage/app/public), con
    | la struttura <autore>/<AAAA MM GG - descrizione>/<file>.
    | Vedi implementationPlan-gestioneFotoFuoriDaGit.md.
    |
    */

    'disk' => 'public',

    // Cartella (dentro il disk) con le versioni generate. Le cartelle che
    // iniziano con "_" o "." non sono mai considerate autori.
    'derived_dir' => '_derived',

    'image_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
    'audio_extensions' => ['mp3', 'aac', 'm4a', 'ogg', 'wav'],

    // Ordine importante: dalla versione più grande alla più piccola (la
    // seconda viene ricavata dall'immagine già ridotta).
    'versions' => [
        'web' => ['max_side' => 1920, 'quality' => 80],
        'thumb' => ['max_side' => 480, 'quality' => 80],
    ],

    // "2025 09 20 - corteo ..." (anche "2025-09-20 - ..." o solo la data).
    'event_folder_regex' => '/^(\d{4})[ \-](\d{2})[ \-](\d{2})(?:\s+-\s+(.+))?$/u',

    // File con i dati dell'autore, dentro la cartella dell'autore.
    'author_file' => 'autore.md',

    // Indicizzazione motori di ricerca (default disattivata)
    'indexable' => env('PHOTOS_INDEXABLE', false),

];
