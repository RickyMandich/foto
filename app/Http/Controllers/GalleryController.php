<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Photo;
use App\Models\Track;
use App\Support\GallerySelection;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class GalleryController extends Controller
{
    public function event(Request $request, string $date): View
    {
        $event = Event::where('date', $date)->firstOrFail();
        $filter = GallerySelection::fromRequest($request)->authors;

        return $this->render($request, GallerySelection::forEvent($event, $filter), $event);
    }

    public function selection(Request $request): View
    {
        $selection = GallerySelection::fromRequest($request);
        abort_if($selection->isEmpty(), 404);

        return $this->render($request, $selection);
    }

    private function render(Request $request, GallerySelection $selection, ?Event $event = null): View
    {
        $photos = $selection->photos();
        abort_if($photos->isEmpty(), 404);

        $startId = (int) $request->query('f');
        $foundIndex = $photos->search(fn (Photo $p) => $p->id === $startId);
        $startIndex = ($foundIndex !== false) ? $foundIndex : 0;

        $tracks = $selection->tracks();

        $payload = [
            'start' => $startIndex,
            'photos' => $photos->map(fn (Photo $p) => [
                'id' => $p->id,
                'name' => $p->filename,
                'author' => $p->gallery->author->name ?? $p->gallery->author->folder,
                'thumb' => $p->url('thumb'),
                'web' => $p->url('web'),
                'orig' => $p->url('original'),
            ])->values()->all(),
            'tracks' => $tracks->map(fn (Track $t) => [
                'id' => $t->id,
                'title' => pathinfo($t->filename, PATHINFO_FILENAME),
                'url' => $t->url(),
            ])->values()->all(),
        ];

        return view('gallery.show', [
            'selection' => $selection,
            'event' => $event,
            'photos' => $photos,
            'tracks' => $tracks,
            'startIndex' => $startIndex,
            'grid' => $request->query('vista') === 'griglia',
            'payload' => $payload,
        ]);
    }
}

