<?php

namespace App\Http\Controllers;

use App\Models\Photo;
use App\Support\GallerySelection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

class DownloadController extends Controller
{
    /**
     * ZIP in streaming (modalità STORE, nessun file temporaneo) con le foto originali.
     */
    public function zip(Request $request): StreamedResponse
    {
        $selection = GallerySelection::fromRequest($request, 422);

        abort_if($selection->isEmpty(), 422, 'Indica almeno un evento o un autore.');

        $photos = $selection->photos();

        abort_if($photos->isEmpty(), 404);

        $name = $selection->zipFileName();

        return response()->streamDownload(function () use ($photos, $name) {
            set_time_limit(0);

            $zip = new ZipStream(
                outputName: $name,
                sendHttpHeaders: false,
                defaultCompressionMethod: CompressionMethod::STORE,
                flushOutput: true,
            );

            foreach ($photos as $photo) {
                $event = $photo->gallery->event;
                $eventFolder = $event->date.($event->title ? ' '.$this->safe($event->title) : '');

                $zip->addFileFromPath(
                    fileName: $eventFolder.'/'.$this->safe($photo->gallery->author->folder).'/'.$photo->filename,
                    path: $photo->absolutePath(),
                );
            }

            $zip->finish();
        }, $name, [
            'Content-Type' => 'application/zip',
            'X-Accel-Buffering' => 'no', // nginx non deve bufferizzare lo stream
        ]);
    }

    /** Toglie i caratteri non ammessi nei nomi di file di Windows. */
    private function safe(string $name): string
    {
        return trim(preg_replace('/[\\\\\/:*?"<>|]/u', ' ', $name));
    }
}
