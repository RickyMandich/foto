<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class Event extends Model
{
    protected $guarded = [];

    // Nessun cast su "date": resta la stringa "AAAA-MM-GG", così i confronti
    // nelle query (firstOrCreate, whereIn) funzionano uguali su MariaDB e SQLite.

    public function galleries(): HasMany
    {
        return $this->hasMany(Gallery::class);
    }

    public function photos(): HasManyThrough
    {
        return $this->hasManyThrough(Photo::class, Gallery::class);
    }

    public function tracks(): HasManyThrough
    {
        return $this->hasManyThrough(Track::class, Gallery::class);
    }

    public function day(): Carbon
    {
        return Carbon::parse($this->date);
    }

    /** Titolo da mostrare: quello della cartella con la prima lettera maiuscola, oppure la data. */
    public function displayTitle(): string
    {
        if ($this->title !== null && trim($this->title) !== '') {
            return Str::ucfirst(trim($this->title));
        }

        return 'Evento del '.$this->day()->format('d/m/Y');
    }
}
