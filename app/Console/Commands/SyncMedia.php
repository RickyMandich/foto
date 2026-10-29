<?php

namespace App\Console\Commands;

use App\Services\Media\MediaSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncMedia extends Command
{
    protected $signature = 'media:sync
        {--dry-run : Mostra cosa cambierebbe senza scrivere nulla}
        {--allow-empty : Consente la sincronizzazione anche se non ci sono cartelle autore}';

    protected $description = 'Allinea il database con le cartelle di foto e audio in storage/app/public';

    public function handle(MediaSynchronizer $synchronizer): int
    {
        $lock = Cache::lock('media-sync', 3600);

        if (! $lock->get()) {
            $this->error('Una sincronizzazione è già in corso.');

            return self::FAILURE;
        }

        try {
            $synchronizer->run(
                dryRun: (bool) $this->option('dry-run'),
                allowEmpty: (bool) $this->option('allow-empty'),
            );
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        if ($this->option('dry-run')) {
            $this->info('Simulazione: il database non è stato modificato.');
        }

        foreach ($synchronizer->stats as $name => $value) {
            $this->line(str_pad($name, 20).$value);
        }

        foreach ($synchronizer->warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
