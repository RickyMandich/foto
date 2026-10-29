<?php

namespace App\Services\Media;

use App\Models\Photo;
use Illuminate\Support\Facades\File;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Genera le versioni leggere (web, miniatura) di una foto, accanto agli originali,
 * in <disk>/_derived/<versione>/<id foto>.webp.
 */
class PhotoVersionGenerator
{
    public function generate(Photo $photo): void
    {
        $photo->loadMissing('gallery.author');
        $source = $photo->absolutePath();

        if (! is_file($source)) {
            return; // sparita dal disco: ci penserà media:sync
        }

        // Con GD l'orientamento EXIF viene applicato in lettura (autoOrientation di Intervention v3).
        $image = (new ImageManager(new Driver()))->read($source);

        // Dalla versione più grande alla più piccola: la seconda parte dall'immagine già ridotta.
        // scaleDown() non ingrandisce mai.
        foreach (config('photos.versions') as $name => $options) {
            $image->scaleDown($options['max_side'], $options['max_side']);

            $target = $photo->versionAbsolutePath($name);
            File::ensureDirectoryExists(dirname($target));

            $temporary = $target.'.tmp';
            $image->toWebp($options['quality'])->save($temporary);
            rename($temporary, $target);
        }

        $photo->forceFill(['versions_ready' => true])->save();

        unset($image);
        gc_collect_cycles();
    }
}
