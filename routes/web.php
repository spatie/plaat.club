<?php

use App\Http\Controllers\CollectionShareImageController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\RecordDetailsController;
use App\Http\Controllers\RecordShareImageController;
use App\Http\Controllers\ShowCollectionController;
use App\Http\Controllers\ShowRecordController;
use App\Http\Controllers\SocialPreviewController;
use App\Http\Controllers\StopImpersonationController;
use App\Http\Middleware\CacheAtEdge;
use App\Http\Middleware\EnsureCollectionAccess;
use App\Http\Middleware\PrivateResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::post('admin/impersonation/stop', StopImpersonationController::class)
    ->middleware(PrivateResponse::class)->name('impersonation.stop');

Route::get('/', HomeController::class)->middleware([PrivateResponse::class, EnsureCollectionAccess::class])->name('home');

// Public artwork and legacy redirects need no session.
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])->group(function () {

    Route::middleware('throttle:shareImages')->group(function () {
        Route::get('share/site.jpg', [SocialPreviewController::class, 'home'])
            ->middleware(CacheAtEdge::class)->name('socialPreview.home');

        Route::get('share/image.png', CollectionShareImageController::class)
            ->name('collectionShareImage');

        Route::get('record/{instanceId}/image.png', RecordShareImageController::class)
            ->whereUuid('instanceId')
            ->name('recordShareImage');
    });

    Route::middleware(CacheAtEdge::class)->group(function () {
        Route::get('/collection', ShowCollectionController::class)->name('collection');

        Route::get('api/records/{instanceId}', RecordDetailsController::class)
            ->whereUuid('instanceId')
            ->name('recordDetails');

        Route::get('record/{instanceId}/{slug?}', ShowRecordController::class)
            ->whereUuid('instanceId')
            ->name('recordShare');
    });
});

require __DIR__.'/social.php';
