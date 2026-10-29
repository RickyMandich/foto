<?php

use App\Support\DatabaseCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una galleria è la cartella di un autore per un evento.
        Schema::create('galleries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            // Nome esatto della cartella evento di quell'autore (può differire tra autori per un typo).
            $folder = $table->string('folder');
            if ($collation = DatabaseCollation::exact()) {
                $folder->collation($collation);
            }

            $table->string('title')->nullable(); // descrizione estratta dal nome della cartella
            $table->timestamps();

            $table->unique(['author_id', 'event_id']);
        });

        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_id')->constrained()->cascadeOnDelete();

            $filename = $table->string('filename');
            if ($collation = DatabaseCollation::exact()) {
                $filename->collation($collation);
            }

            $table->unsignedBigInteger('size');
            $table->unsignedInteger('mtime');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->dateTime('taken_at')->nullable();      // EXIF DateTimeOriginal
            $table->boolean('versions_ready')->default(false);
            $table->timestamps();

            $table->unique(['gallery_id', 'filename']);
        });

        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_id')->constrained()->cascadeOnDelete();

            $filename = $table->string('filename');
            if ($collation = DatabaseCollation::exact()) {
                $filename->collation($collation);
            }

            $table->unsignedBigInteger('size');
            $table->unsignedInteger('mtime');
            $table->timestamps();

            $table->unique(['gallery_id', 'filename']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracks');
        Schema::dropIfExists('photos');
        Schema::dropIfExists('galleries');
    }
};
