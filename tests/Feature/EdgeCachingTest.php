<?php

use App\Support\RecordCollection;
use Illuminate\Support\Facades\File;

it('reloads an open Inertia tab after assets change even when the asset origin stays the same', function () {
    config(['app.asset_url' => 'https://assets.example.test']);
    app()->usePublicPath(storage_path('test-public'));
    File::ensureDirectoryExists(public_path('build'));
    File::put(public_path('build/manifest.json'), '{"app":"first-build.js"}');
    $version = $this->get('/login')->assertOk()->viewData('page')['version'];

    $this->get('/login', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertOk();

    File::put(public_path('build/manifest.json'), '{"app":"next-build.js"}');
    $this->get('/login', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
        ->assertStatus(409)->assertHeader('X-Inertia-Location', url('/login'));
});

it('lets the edge cache pages without starting a session', function (string $url) {
    $url = str_replace('{publicId}', legacyRecordPublicId(2189134863), $url);
    $response = $this->get($url);

    $response->assertOk()->assertHeaderMissing('Set-Cookie');

    expect($response->headers->get('Cache-Control'))->toBe('max-age=60, public, s-maxage=86400');
})->with([
    'homepage artwork' => ['/share/site.jpg'],
    'record details' => ['/api/records/{publicId}'],
]);

it('does not cache inertia visits at the edge', function () {
    $response = $this->get('/collection', ['X-Inertia' => 'true']);

    expect((string) $response->headers->get('Cache-Control'))->not->toContain('public');
});

it('does not cache missing pages at the edge', function () {
    $response = $this->get('/record/9999999999/unknown-album');

    $response->assertNotFound();
    expect((string) $response->headers->get('Cache-Control'))->not->toContain('public');
});

it('does not cache slug redirects at the edge', function () {
    $collection = app(RecordCollection::class);
    $collection->store($collection->snapshot()[1]);
    $response = $this->get('/record/'.legacyRecordPublicId(2189134863).'/old-album-title');

    $response->assertStatus(301);
    expect((string) $response->headers->get('Cache-Control'))->not->toContain('s-maxage');
});

it('redirects authenticated legacy collection visits without exposing viewer data', function () {
    $viewer = socialUser('legacy_cache_viewer');
    $response = $this->actingAs($viewer)->get('/collection')->assertStatus(301)->assertHeaderMissing('Set-Cookie')
        ->assertDontSee($viewer->email)->assertDontSee($viewer->handle);

    expect((string) $response->headers->get('Cache-Control'))->not->toContain('s-maxage');
});
