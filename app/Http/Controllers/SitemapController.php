<?php

namespace App\Http\Controllers;

use App\Models\Author;
use App\Models\Event;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(config('photos.indexable', false), 404);

        $events = Event::has('galleries.photos')->orderByDesc('date')->get();
        $authors = Author::has('galleries.photos')->orderBy('name')->get();

        $content = view('sitemap', [
            'events' => $events,
            'authors' => $authors,
        ])->render();

        return response($content, 200, [
            'Content-Type' => 'application/xml',
        ]);
    }
}

