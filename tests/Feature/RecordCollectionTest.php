<?php

use App\Models\User;
use App\Support\RecordCollection;
use App\Support\RecordShareImage;
use App\Support\StoredShareImages;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects the legacy collection to the current profile layout', function () {
    $owner = User::freek();
    socialRecord($owner, 'Current shelf record');

    $this->get(route('collection'))->assertStatus(301)->assertRedirect(route('profile', $owner->handle));
    $this->followingRedirects()->get(route('collection'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('social/ProfilePage')
            ->where('records.data.0.displayTitle', 'Current shelf record'));
});

it('provides track positions when opening a record', function () {
    $this->getJson(route('recordDetails', ['instanceId' => legacyRecordPublicId(2189134863)]))
        ->assertOk()
        ->assertJsonPath('title', 'Shebang')
        ->assertJsonPath('tracks.0.position', 'A1')
        ->assertJsonPath('tracks.2.position', 'B1');
});

it('keeps bundled snapshot links stable without persistent collection caching', function () {
    $publicId = legacyRecordPublicId(2189134863);
    Cache::flush();

    expect(legacyRecordPublicId(2189134863))->toBe($publicId)
        ->not->toBe(legacyRecordPublicId(2189134981));
    $this->getJson('/api/records/'.$publicId)->assertOk()->assertJsonPath('title', 'Shebang');
});

it('provides direct and search listening links when opening a record', function () {
    $this->getJson(route('recordDetails', ['instanceId' => legacyRecordPublicId(2189134981)]))
        ->assertOk()
        ->assertJsonPath('tracks.1.appleMusicSearch', false)
        ->assertJsonPath('tracks.1.appleMusicUrl', fn (string $url) => str_contains($url, '?i='))
        ->assertJsonPath('tracks.1.spotifySearch', true)
        ->assertJsonPath('tracks.1.spotifyUrl', 'https://open.spotify.com/search/George%20Harrison%20My%20Sweet%20Lord');
});

it('returns 404 for unknown collection copies', function () {
    $this->getJson(route('recordDetails', ['instanceId' => 9999999999]))->assertNotFound();
});

it('does not route invalid collection copy ids', function () {
    $this->getJson('/api/records/not-a-record')->assertNotFound();
});

it('redirects legacy record URLs to the current record view', function (string $slug) {
    $collection = app(RecordCollection::class);
    $collection->store($collection->snapshot()[1]);
    $record = User::freek()->records()->firstOrFail();

    $this->get('/record/'.$record->public_id.$slug)->assertRedirect($record->url())->assertStatus(301);
    $this->followingRedirects()->get('/record/'.$record->public_id.$slug)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('social/ProfilePage')
            ->where('recordOverlay.record.payload.title', 'Shebang'));
})->with([
    'current slug' => ['/oren-ambarchi-shebang'],
    'outdated slug' => ['/old-album-title'],
    'missing slug' => [''],
]);

it('returns 404 for unknown shared records', function () {
    $this->get('/record/9999999999/unknown-album')->assertNotFound();
});

it('stores a small preview image for a shared record and redirects to it', function () {
    $response = $this->get(route('recordShareImage', legacyRecordPublicId(2189134863)));

    $response->assertRedirect()->assertHeaderMissing('Set-Cookie');
    expect($response->headers->get('Cache-Control'))->toContain('public', 's-maxage=3600')
        ->and($response->headers->get('Location'))->toStartWith(storedShareImageUrl('share/records/2189134863-'));

    expectStoredShareImage($response->headers->get('Location'));
});

it('keeps record artwork prominent and text inside the social preview safe area', function (string $artist, string $title, ?int $year) {
    $cover = imagecreatetruecolor(32, 32);
    imagefill($cover, 0, 0, 0x307A90);
    ob_start();
    imagejpeg($cover);
    Storage::disk('public')->put('records/42.jpg', ob_get_clean());

    $bytes = app(RecordShareImage::class)->render([
        'cover' => Storage::disk('public')->url('records/42.jpg'),
        'artist' => $artist,
        'displayTitle' => $title,
        'originalYear' => $year,
    ]);

    [$width, $height, $type] = getimagesizefromstring($bytes);
    expect([$width, $height, $type])->toBe([1200, 630, IMAGETYPE_JPEG])
        ->and(strlen($bytes))->toBeLessThan(150 * 1024);

    $image = imagecreatefromstring($bytes);
    $art = imagecolorsforindex($image, imagecolorat($image, 450, 550));
    $vinyl = imagecolorsforindex($image, imagecolorat($image, 590, 315));
    $edge = imagecolorsforindex($image, imagecolorat($image, 8, 315));

    expect($art['blue'])->toBeGreaterThan(130)
        ->and($art['red'])->toBeLessThan(60)
        ->and($vinyl['red'])->toBeLessThan(70)
        ->and($edge['green'])->toBeGreaterThan(190);

    $inkBottom = 0;
    $yearTop = 630;
    $yearPixels = 0;
    $overflow = 0;

    for ($y = 160; $y < 630; $y++) {
        for ($x = 610; $x < 1200; $x++) {
            $pixel = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            $isInk = max($pixel['red'], $pixel['green'], $pixel['blue']) < 100 && abs($pixel['red'] - $pixel['green']) < 20;
            $isAccent = $pixel['red'] > 115 && $pixel['red'] > $pixel['green'] * 1.6 && $pixel['green'] < 110;

            if ($isInk) {
                $inkBottom = max($inkBottom, $y);
            }

            if ($isAccent) {
                $yearTop = min($yearTop, $y);
                $yearPixels++;
            }

            if (($isInk || $isAccent) && ($x < 638 || $x > 1150 || $y > 580)) {
                $overflow++;
            }
        }
    }

    expect($inkBottom)->toBeGreaterThan(250)
        ->and($overflow)->toBe(0);

    if ($year !== null) {
        expect($yearPixels)->toBeGreaterThan(100)
            ->and($yearTop)->toBeGreaterThan($inkBottom + 12)
            ->and($yearTop)->toBeLessThan(550);
    } else {
        expect($yearPixels)->toBe(0);
    }
})->with([
    'short title' => ['Oren Ambarchi', 'Shebang', 2022],
    'long title' => ['David Bowie', 'The Rise And Fall Of Ziggy Stardust And The Spiders From Mars', 1972],
    'two-line artist' => ['King Gizzard And The Lizard Wizard', 'Polygondwanaland', 2017],
    'unbroken title' => ['Björk', str_repeat('Ágætisbyrjun', 12), 1999],
    'overlong metadata' => [str_repeat('A collaborative artist ensemble ', 8), str_repeat('A very long album title ', 30), 2001],
    'unknown year' => ['SML', 'How You Been', null],
]);

it('replaces the old record layout without reusing its immutable image', function () {
    $record = app(RecordCollection::class)->findSummary(2189134863);
    $fields = Arr::only($record, ['cover', 'artist', 'displayTitle', 'originalYear']);
    $oldHash = substr(hash('sha256', json_encode([2, [...$fields, 'layoutVersion' => 2]], JSON_THROW_ON_ERROR)), 0, 16);
    $oldPath = "share/records/2189134863-{$oldHash}.jpg";
    Storage::disk('public')->put($oldPath, 'old layout');

    $images = app(StoredShareImages::class);
    $url = $images->recordUrl($record);

    expect($url)->not->toBe(storedShareImageUrl($oldPath))
        ->and($images->recordUrl($record))->toBe($url)
        ->and(Storage::disk('public')->allFiles('share/records'))->toHaveCount(2)
        ->and(Storage::disk('public')->get($oldPath))->toBe('old layout');

    expectStoredShareImage($url);
});

it('returns 404 for the preview image of an unknown record', function () {
    $this->get(route('recordShareImage', 9999999999))->assertNotFound();
});

it('stores a small preview image for the collection and redirects to it', function () {
    $response = $this->get(route('collectionShareImage', ['v' => 2]));

    $response->assertRedirect()->assertHeaderMissing('Set-Cookie');
    expect($response->headers->get('Cache-Control'))->toContain('public', 's-maxage=3600')
        ->and($response->headers->get('Location'))->toStartWith(storedShareImageUrl('share/collection-'));

    expectStoredShareImage($response->headers->get('Location'));
});

it('renders each share image only once', function () {
    $this->get(route('collectionShareImage'))->assertRedirect();
    $this->get(route('collectionShareImage'))->assertRedirect();

    expect(Storage::disk('public')->allFiles('share'))->toHaveCount(1);
});

it('reads the persisted collection once records are stored', function () {
    $collection = app(RecordCollection::class);
    $record = $collection->snapshot()[0];

    $collection->store($record);

    $this->followingRedirects()->get(route('collection'))->assertInertia(fn (Assert $page) => $page
        ->component('social/ProfilePage')
        ->has('records.data', 1)
        ->where('records.data.0.instanceId', $record['instanceId'])
        ->missing('records.data.0.tracks'));
});

it('shows collection changes immediately when a record is stored', function () {
    $collection = app(RecordCollection::class);
    [$first, $second] = $collection->snapshot();

    $collection->store($first);

    expect($collection->summaries())->toHaveCount(1);

    $collection->store($second);

    expect($collection->summaries())->toHaveCount(2);
});

function storedShareImageUrl(string $pathPrefix): string
{
    return Storage::disk('public')->url($pathPrefix);
}

function expectStoredShareImage(string $url): void
{
    $path = substr($url, strlen(storedShareImageUrl('')));

    Storage::disk('public')->assertExists($path);

    $bytes = Storage::disk('public')->get($path);

    [$width, $height, $type] = getimagesizefromstring($bytes);

    expect($path)->toEndWith('.jpg')
        ->and($type)->toBe(IMAGETYPE_JPEG)
        ->and($width)->toBe(1200)
        ->and($height)->toBe(630)
        ->and(strlen($bytes))->toBeLessThan(150 * 1024);
}
