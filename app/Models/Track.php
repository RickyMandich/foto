<?php

namespace App\Models;

use App\Support\PublicUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Track extends Model
{
    protected $guarded = [];

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    /** Percorso del file audio dentro il disk (richiede gallery.author caricato). */
    public function relativePath(): string
    {
        return $this->gallery->author->folder.'/'.$this->gallery->folder.'/'.$this->filename;
    }

    public function url(): string
    {
        return PublicUrl::for($this->relativePath());
    }

    /** Titolo del brano: il nome del file senza estensione. */
    public function title(): string
    {
        return pathinfo($this->filename, PATHINFO_FILENAME);
    }
}
