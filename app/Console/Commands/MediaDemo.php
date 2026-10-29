<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class MediaDemo extends Command
{
    protected $signature = 'media:demo 
                            {--photos=30 : Numero di foto per galleria}
                            {--clean : Cancella le cartelle demo e lancia media:sync}';

    protected $description = 'Genera foto e cartelle demo per lo sviluppo dell\'interfaccia';

    public function handle(): int
    {
        ini_set('memory_limit', '512M');

        if (! app()->environment('local', 'testing')) {
            $this->error('Questo comando è eseguibile solo in ambienti local o testing.');
            return self::FAILURE;
        }

        $disk = Storage::disk(config('photos.disk'));
        $root = $disk->path('');

        if ($this->option('clean')) {
            $this->info('Pulizia cartelle demo...');
            foreach (File::directories($root) as $dir) {
                $basename = basename($dir);
                if (str_starts_with($basename, 'demo-')) {
                    File::deleteDirectory($dir);
                    $this->line("Rimossa: {$basename}");
                }
            }

            $this->info('Eseguo media:sync...');
            $this->call('media:sync');
            return self::SUCCESS;
        }

        $photosCount = (int) $this->option('photos');
        if ($photosCount <= 0) {
            $photosCount = 30;
        }

        // Definizione autori e cartelle
        $demoAuthors = [
            'demo-anna' => [
                'meta' => "---\nnome: Anna Bianchi\nrecapiti:\n  instagram: anna.foto\n  sito: https://anna.example.com\n---\nFotografa freelance a Venezia.",
                'events' => [
                    '2025 09 20 - corteo per gaza' => [
                        'color' => [220, 50, 50],
                    ],
                    '2025 10 15 - assemblea studentesca' => [
                        'color' => [50, 150, 220],
                    ],
                ],
            ],
            'demo-luca' => [
                'meta' => "---\nnome: Luca Rossi\n---\n",
                'events' => [
                    '2025 09 20 - Corteo per Gaza' => [ // Stessa data di Anna, maiuscole diverse
                        'color' => [50, 200, 100],
                    ],
                    '2025 11 02 - presidio antifascista' => [
                        'color' => [230, 180, 40],
                    ],
                ],
            ],
        ];

        foreach ($demoAuthors as $authorFolder => $authorData) {
            $authorPath = $root . DIRECTORY_SEPARATOR . $authorFolder;
            File::ensureDirectoryExists($authorPath);

            // Scrivi autore.md
            File::put($authorPath . DIRECTORY_SEPARATOR . 'autore.md', $authorData['meta']);

            foreach ($authorData['events'] as $eventFolder => $eventCfg) {
                $eventPath = $authorPath . DIRECTORY_SEPARATOR . $eventFolder;
                File::ensureDirectoryExists($eventPath);

                $this->info("Genero foto per {$authorFolder} / {$eventFolder}...");

                for ($i = 1; $i <= $photosCount; $i++) {
                    $filename = sprintf('IMGP%04d.JPG', $i);
                    $filePath = $eventPath . DIRECTORY_SEPARATOR . $filename;

                    if (File::exists($filePath)) {
                        continue;
                    }

                    // Alterna orizzontali e verticali
                    $isLandscape = ($i % 2 !== 0);
                    $w = $isLandscape ? 4000 : 2667;
                    $h = $isLandscape ? 2667 : 4000;

                    $im = imagecreatetruecolor($w, $h);
                    $rgb = $eventCfg['color'];
                    // Varia leggermente la luminosità per foto
                    $tint = imagecolorallocate($im, 
                        max(0, min(255, $rgb[0] + (($i * 3) % 40) - 20)),
                        max(0, min(255, $rgb[1] + (($i * 5) % 40) - 20)),
                        max(0, min(255, $rgb[2] + (($i * 7) % 40) - 20))
                    );
                    imagefill($im, 0, 0, $tint);

                    $textColor = imagecolorallocate($im, 255, 255, 255);
                    $label = "{$authorFolder} - {$i}/{$photosCount}";
                    imagestring($im, 5, 50, 50, $label, $textColor);

                    imagejpeg($im, $filePath, 85);
                    imagedestroy($im);
                }
            }
        }

        $this->info('Foto demo create con successo!');
        $this->comment('Ora puoi lanciare: php artisan media:sync');

        return self::SUCCESS;
    }
}
