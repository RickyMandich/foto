<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Support\GallerySelection;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        $events = Event::query()
            ->withCount(['photos', 'tracks', 'galleries as authors_count'])
            ->whereHas('galleries.photos')
            ->orderByDesc('date')
            ->paginate(12);

        $covers = $events->getCollection()->mapWithKeys(fn (Event $event) => [
            $event->id => GallerySelection::forEvent($event)->photosQuery()->with('gallery.author')->first(),
        ]);

        return view('home', [
            'events' => $events,
            'covers' => $covers,
        ]);
    }
}

