<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Helper per i test dei contenuti (foto e audio)
|--------------------------------------------------------------------------
*/

/**
 * Crea una cartella temporanea e la configura come disk dei contenuti
 * (al posto di storage/app/public). Ritorna il percorso.
 */
function makeMediaRoot(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'foto-test-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($root);

    config([
        'filesystems.disks.media_test' => ['driver' => 'local', 'root' => $root],
        'photos.disk' => 'media_test',
    ]);

    return $root;
}

/** Scrive un JPEG valido (rettangolo colorato) di dimensioni date. */
function putImage(string $path, int $width = 64, int $height = 48): void
{
    File::ensureDirectoryExists(dirname($path));

    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 80, 40));
    imagejpeg($image, $path, 80);
    imagedestroy($image);
}

/** Scrive un file qualsiasi (es. audio finto). */
function putFile(string $path, string $content = 'x'): void
{
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, $content);
}
