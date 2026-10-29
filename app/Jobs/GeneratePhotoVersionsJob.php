<?php

namespace App\Jobs;

use App\Models\Photo;
use App\Services\Media\PhotoVersionGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GeneratePhotoVersionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $photoId)
    {
    }

    public function handle(PhotoVersionGenerator $generator): void
    {
        $photo = Photo::query()->with('gallery.author')->find($this->photoId);

        if ($photo === null) {
            return; // eliminata nel frattempo da media:sync
        }

        $generator->generate($photo);
    }
}
