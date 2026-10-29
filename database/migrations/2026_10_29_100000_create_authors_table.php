<?php

use App\Support\DatabaseCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authors', function (Blueprint $table) {
            $table->id();

            // Nome esatto della cartella dell'autore (confronto binario, vedi DatabaseCollation).
            $folder = $table->string('folder');
            if ($collation = DatabaseCollation::exact()) {
                $folder->collation($collation);
            }
            $folder->unique();

            $table->string('slug')->unique();   // Str::slug(folder), usato negli URL
            $table->string('name');             // da autore.md, altrimenti = folder
            $table->text('bio')->nullable();
            $table->json('contacts')->nullable(); // recapiti facoltativi
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authors');
    }
};
