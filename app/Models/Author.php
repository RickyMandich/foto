<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class Author extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'contacts' => 'array',
        ];
    }

    public function galleries(): HasMany
    {
        return $this->hasMany(Gallery::class);
    }

    /**
     * Eventi con almeno una foto di questo autore.
     */
    public function events()
    {
        return Event::query()
            ->whereHas('galleries', fn ($g) => $g->where('author_id', $this->id)->whereHas('photos'))
            ->withCount([
                'photos as photos_count' => fn ($q) => $q->whereHas('gallery', fn ($g) => $g->where('author_id', $this->id)),
                'tracks as tracks_count' => fn ($q) => $q->whereHas('gallery', fn ($g) => $g->where('author_id', $this->id)),
            ])
            ->orderByDesc('date');
    }

    /**
     * Link ai contatti validati e normalizzati per la visualizzazione.
     */
    public function contactLinks(): array
    {
        $links = [];
        $contacts = $this->contacts ?? [];

        if (! empty($contacts['email']) && filter_var($contacts['email'], FILTER_VALIDATE_EMAIL)) {
            $links[] = [
                'label' => 'Email',
                'text' => $contacts['email'],
                'href' => 'mailto:' . $contacts['email'],
                'external' => false,
                'icon' => 'user',
            ];
        }

        if (! empty($contacts['instagram'])) {
            $profile = ltrim(trim($contacts['instagram']), '@');
            if (preg_match('/^[A-Za-z0-9._]{1,30}$/', $profile)) {
                $links[] = [
                    'label' => 'Instagram',
                    'text' => '@' . $profile,
                    'href' => 'https://www.instagram.com/' . $profile . '/',
                    'external' => true,
                    'icon' => 'user',
                ];
            }
        }

        if (! empty($contacts['sito'])) {
            $sito = trim($contacts['sito']);
            if (preg_match('/^https?:\/\//i', $sito)) {
                $links[] = [
                    'label' => 'Sito web',
                    'text' => preg_replace('/^https?:\/\//i', '', rtrim($sito, '/')),
                    'href' => $sito,
                    'external' => true,
                    'icon' => 'user',
                ];
            }
        }

        if (! empty($contacts['telefono'])) {
            $tel = trim($contacts['telefono']);
            $cleanTel = preg_replace('/[^\d+]/', '', $tel);
            if ($cleanTel !== '') {
                $links[] = [
                    'label' => 'Telefono',
                    'text' => $tel,
                    'href' => 'tel:' . $cleanTel,
                    'external' => false,
                    'icon' => 'user',
                ];
            }
        }

        return $links;
    }

    /**
     * Bio resa in HTML sicuro da Markdown.
     */
    public function bioHtml(): ?HtmlString
    {
        if (empty($this->bio)) {
            return null;
        }

        $cut = Str::limit($this->bio, 5000);
        $html = Str::markdown($cut, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return new HtmlString($html);
    }
}
