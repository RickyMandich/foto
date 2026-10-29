<?php

namespace App\Models;

use App\Support\PublicUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Photo extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'versions_ready' => 'boolean',
        ];
    }

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    /** Percorso dell'originale dentro il disk (richiede gallery.author caricato). */
    public function relativePath(): string
    {
        return $this->gallery->author->folder.'/'.$this->gallery->folder.'/'.$this->filename;
    }

    public function absolutePath(): string
    {
        return Storage::disk(config('photos.disk'))->path($this->relativePath());
    }

    /** Percorso di una versione generata ("web" o "thumb"): dipende solo dall'id, mai dal nome file. */
    public function versionRelativePath(string $version): string
    {
        return config('photos.derived_dir')."/{$version}/{$this->id}.webp";
    }

    public function versionAbsolutePath(string $version): string
    {
        return Storage::disk(config('photos.disk'))->path($this->versionRelativePath($version));
    }

    /**
     * URL pubblico dell'originale ("original") o di una versione ("web", "thumb").
     * Le versioni hanno ?v=<mtime> così la cache del browser si rinnova se l'originale cambia.
     * Una versione non ancora generata ricade sull'originale.
     */
    public function url(string $variant = 'original'): string
    {
        if ($variant === 'original' || ! $this->versions_ready) {
            return PublicUrl::for($this->relativePath());
        }

        return PublicUrl::for($this->versionRelativePath($variant)).'?v='.$this->mtime;
    }

    /**
     * Valore dell'attributo srcset per le immagini responsive.
     * Restituisce una stringa vuota se le versioni non sono ancora pronte.
     */
    public function srcset(): string
    {
        if (! $this->versions_ready) {
            return '';
        }

        $maxWeb = (int) config('photos.versions.web.max_side', 1920);
        $side = max((int) $this->width, (int) $this->height);
        $webWidth = $side > 0 ? min($maxWeb, $side) : $maxWeb;

        return "{$this->url('thumb')} 480w, {$this->url('web')} {$webWidth}w";
    }
}
