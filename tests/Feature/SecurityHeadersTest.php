<?php

use App\Models\Author;
use App\Models\Event;
use App\Models\Gallery;
use App\Models\Photo;

beforeEach(function () {
    $author = Author::create(['folder' => 'anna', 'slug' => 'anna', 'name' => 'Anna']);
    $event = Event::create(['date' => '2025-09-20', 'title' => 'Corteo']);
    $gallery = Gallery::create(['author_id' => $author->id, 'event_id' => $event->id, 'folder' => '2025 09 20']);
    Photo::create(['gallery_id' => $gallery->id, 'filename' => 'A.JPG', 'size' => 10, 'mtime' => 1]);
});

test('le risposte HTML includono tutti gli header di sicurezza', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("default-src 'none'")
        ->toContain("frame-ancestors 'none'");
});

