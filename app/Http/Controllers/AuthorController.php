<?php

namespace App\Http\Controllers;

use App\Models\Author;
use App\Models\Event;
use App\Support\GallerySelection;
use Illuminate\Contracts\View\View;

class AuthorController extends Controller
{
    public function index(): View
    {
        $authors = Author::query()
            ->withCount(['galleries as photos_count' => fn ($q) => $q->has('photos')])
            ->whereHas('galleries.photos')
            ->orderBy('name')
            ->get();

        return view('authors.index', [
            'authors' => $authors,
        ]);
    }

    public function show(string $slug): View
    {
        $author = Author::where('slug', $slug)->firstOrFail();
        abort_unless($author->galleries()->whereHas('photos')->exists(), 404);

        $events = $author->events()->paginate(12);

        $covers = $events->getCollection()->mapWithKeys(fn (Event $event) => [
            $event->id => GallerySelection::forEvent($event, [$author->slug])->photosQuery()->first(),
        ]);

        return view('authors.show', [
            'author' => $author,
            'events' => $events,
            'covers' => $covers,
        ]);
    }
}

