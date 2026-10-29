<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/test-layout-standard', function () {
        return view('components.layout', ['title' => 'Test Pagina', 'app' => false, 'slot' => '<p>Contenuto di prova</p>']);
    });

    Route::get('/test-layout-app', function () {
        return view('components.layout', ['title' => 'Galleria App', 'app' => true, 'slot' => '<p>Vista app</p>']);
    });
});

test('una pagina con layout ha lang it, titolo corretto e link salta contenuto', function () {
    $response = $this->get('/test-layout-standard');

    $response->assertOk()
        ->assertSee('lang="it"', false)
        ->assertSee('Test Pagina — Foto', false)
        ->assertSee('Vai al contenuto', false);
});

test('il layout standard ha header e footer mentre app=true non li ha', function () {
    $standard = $this->get('/test-layout-standard');
    $standard->assertSee('<header', false)
        ->assertSee('<footer', false);

    $app = $this->get('/test-layout-app');
    $app->assertDontSee('<header', false)
        ->assertDontSee('<footer', false);
});

test('l HTML non contiene CDN esterne o script remoti non autorizzati', function () {
    $response = $this->get('/test-layout-standard');
    $content = $response->getContent();

    expect($content)->not->toContain('https://cdn')
        ->not->toContain('googleapis.com')
        ->not->toContain('cloudflare.com')
        ->not->toContain('<script src="http');
});

test('pagina inesistente restituisce la vista 404 in italiano', function () {
    $response = $this->get('/pagina-inesistente-' . uniqid());

    $response->assertNotFound()
        ->assertSee('Pagina non trovata');
});
