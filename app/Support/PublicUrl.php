<?php

namespace App\Support;

class PublicUrl
{
    /**
     * URL pubblico (path assoluto) di un file dentro storage/app/public.
     *
     * nginx serve /storage/ da quella cartella. Ogni segmento va codificato
     * (spazi, accenti, parentesi, #, ? ... nei nomi di cartelle e file), ma
     * gli "/" tra i segmenti restano.
     */
    public static function for(string $relativePath): string
    {
        $encoded = collect(explode('/', $relativePath))
            ->map(fn (string $segment) => rawurlencode($segment))
            ->implode('/');

        return '/storage/'.$encoded;
    }
}
