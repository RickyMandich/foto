<?php

use App\Http\Controllers\AuthorController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/eventi/{date}', [GalleryController::class, 'event'])
    ->where('date', '\d{4}-\d{2}-\d{2}')->name('events.show');
Route::get('/galleria', [GalleryController::class, 'selection'])->name('gallery.show');

Route::get('/autori', [AuthorController::class, 'index'])->name('authors.index');
Route::get('/autori/{slug}', [AuthorController::class, 'show'])
    ->where('slug', '[a-z0-9\-]+')->name('authors.show');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

Route::get('/download/zip', [DownloadController::class, 'zip'])
    ->name('download.zip')
    ->middleware('throttle:6,1');
