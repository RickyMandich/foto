<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class DatabaseCollation
{
    /**
     * Collation "esatta" (binaria, senza pad dei trailing spaces dove disponibile)
     * per le colonne che contengono nomi di file e di cartelle.
     *
     * Con le collation di default (case e accent insensitive) due file che
     * differiscono solo per maiuscole, accenti o spazi finali sarebbero la
     * stessa riga e violerebbero gli indici unici. Su SQLite il confronto è
     * già esatto, quindi non serve nulla.
     */
    public static function exact(): ?string
    {
        return match (DB::getDriverName()) {
            'mariadb' => 'utf8mb4_nopad_bin',
            'mysql' => 'utf8mb4_bin',
            default => null,
        };
    }
}
