<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountDeletionController;
use App\Http\Controllers\CollectionSearchController;
use App\Http\Controllers\CollectionVisibilityController;
use App\Http\Controllers\DiscogsConnectionController;
use App\Http\Controllers\FriendRequestResponseController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvitationsController;
use App\Http\Controllers\ListEntryCoversController;
use App\Http\Controllers\ListRecordCoversController;
use App\Http\Controllers\ListReleaseCoversController;
use App\Http\Controllers\RecordCoverController;
use App\Http\Controllers\RecordListEntriesController;
use App\Http\Controllers\RecordListRecordsController;
use App\Http\Controllers\RecordListsController;
use App\Http\Controllers\RecordNotesController;
use App\Http\Controllers\SocialController;
use App\Http\Controllers\SocialPreviewController;
use App\Http\Controllers\UserListsController;
use App\Http\Middleware\EnsureCollectionAccess;
use App\Http\Middleware\PrivateResponse;
use App\Http\Middleware\RequireVerifiedAccount;
use App\Support\SocialData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware([PrivateResponse::class, RequireVerifiedAccount::class, EnsureCollectionAccess::class])->group(function () {
    foreach (['about', 'privacy', 'terms'] as $document) {
        Route::get("/{$document}", function () use ($document) {
            if ($document === 'terms') {
                abort_unless(app()->environment('local'), 404);
            }

            return Inertia::render('social/'.ucfirst($document).'Page');
        })->name($document);
    }

    Route::get('/community', [HomeController::class, 'intro'])->name('community');
    Route::get('/collections', [HomeController::class, 'collections'])->name('collections');
    Route::get('/lists/{recordList}', [RecordListsController::class, 'show'])->whereUuid('recordList')->name('lists.show');
    Route::get('/lists/{recordList}/records/{copy}', [RecordListRecordsController::class, 'show'])->whereUuid(['recordList', 'copy'])->name('lists.record');
    Route::get('/lists/{recordList}/records/{copy}/cover', ListRecordCoversController::class)->whereUuid(['recordList', 'copy'])->name('lists.cover');
    Route::get('/lists/{recordList}/entries/{entry}', [RecordListEntriesController::class, 'show'])->whereUuid(['recordList', 'entry'])->name('lists.entries.show');
    Route::get('/lists/{recordList}/entries/{entry}/cover', ListEntryCoversController::class)->whereUuid(['recordList', 'entry'])->name('lists.entries.cover');
    Route::get('/about/weekly-email', [HomeController::class, 'weeklyEmail'])->name('weeklyEmail');
    Route::get('/invitations/{token}', [InvitationsController::class, 'show'])->name('invitations.show');
    Route::get('/people/{user:handle}', [SocialController::class, 'profile']);
    Route::get('/shelves/{record}', [SocialController::class, 'record'])->whereUuid('record')->name('shelf.record');
    Route::get('/shelves/{record}/cover', RecordCoverController::class)->whereUuid('record')->name('shelf.cover');
    Route::middleware('throttle:shareImages')->group(function () {
        Route::get('share/collections/{user:handle}.jpg', [SocialPreviewController::class, 'collection'])->name('socialPreview.collection');
        Route::get('share/shelves/{record}.jpg', [SocialPreviewController::class, 'record'])->whereUuid('record')->name('socialPreview.record');
    });
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:20,1')->name('google.callback');

    Route::match(['get', 'post'], '/friends/respond/{friendship}/{recipient}/{action}', FriendRequestResponseController::class)
        ->whereUuid(['friendship', 'recipient'])->whereIn('action', ['accept', 'decline'])
        ->middleware(['signed', 'throttle:30,1'])->name('friendRequest.respond');

    Route::middleware('guest')->group(function () {
        Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:6,1')->name('google.redirect');
        Route::get('/register', fn () => Inertia::render('social/AuthPage', ['mode' => 'register', 'artwork' => SocialData::publicArtwork(limit: 12)]))->name('register');
        Route::post('/register', [AccountController::class, 'register'])->middleware('throttle:6,1');
        Route::get('/login', fn () => Inertia::render('social/AuthPage', ['mode' => 'login', 'artwork' => SocialData::publicArtwork(limit: 12)]))->name('login');
        Route::post('/login', [AccountController::class, 'login'])->middleware('throttle:6,1');
        Route::get('/forgot-password', fn () => Inertia::render('social/AuthPage', ['mode' => 'forgot', 'artwork' => SocialData::publicArtwork(limit: 12)]))->name('password.request');
        Route::post('/forgot-password', [AccountController::class, 'forgot'])->middleware('throttle:6,1');
        Route::get('/reset-password/{token}', fn (Request $request, string $token) => Inertia::render('social/AuthPage', ['mode' => 'reset', 'artwork' => SocialData::publicArtwork(limit: 12), 'token' => $token, 'email' => $request->string('email')->toString()]))->name('password.reset');
        Route::post('/reset-password', [AccountController::class, 'reset'])->middleware('throttle:6,1');
    });

    Route::middleware(['auth', 'auth.session'])->group(function () {
        Route::post('/logout', [AccountController::class, 'logout'])->name('logout');
        Route::get('/verify-email', fn () => Inertia::render('social/AuthPage', ['mode' => 'verify', 'artwork' => SocialData::publicArtwork(limit: 12)]))->name('verification.notice');
        Route::get('/verify-email/{id}/{hash}', [AccountController::class, 'verify'])->whereUuid('id')->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
        Route::post('/email/verification-notification', [AccountController::class, 'resend'])->middleware('throttle:6,1')->name('verification.send');

        Route::middleware('verified')->group(function () {
            Route::get('/ask', [CollectionSearchController::class, 'index'])->name('collectionSearch');
            Route::patch('/ask/answers/{question}/feedback', [CollectionSearchController::class, 'update'])->middleware('throttle:30,1')->name('collectionAnswerFeedback');
            Route::get('/feed', fn (Request $request) => redirect()->route('feed', $request->query(), 301));
            Route::get('/friends', [SocialController::class, 'feed'])->name('feed');
            Route::get('/lists', [RecordListsController::class, 'index'])->name('lists.index');
            Route::get('/lists/{recordList}/releases/{releaseId}/cover', ListReleaseCoversController::class)->whereUuid('recordList')->where('releaseId', '[1-9][0-9]{0,9}')->middleware('throttle:120,1')->name('lists.releaseCover');
            Route::patch('/lists/{recordList}/entries/{entry}', [RecordListEntriesController::class, 'update'])->whereUuid(['recordList', 'entry'])->name('lists.entries.update');
            Route::post('/lists', [RecordListsController::class, 'store'])->middleware('throttle:30,1')->name('lists.store');
            Route::post('/lists/{recordList}/records/{copy}', [RecordListRecordsController::class, 'store'])->whereUuid(['recordList', 'copy'])->name('lists.records.store');
            Route::delete('/lists/{recordList}/records/{copy}', [RecordListRecordsController::class, 'destroy'])->whereUuid(['recordList', 'copy'])->name('lists.records.destroy');
            Route::get('/lists/{recordList}/edit', [RecordListsController::class, 'edit'])->whereUuid('recordList')->middleware('throttle:60,1')->name('lists.edit');
            Route::patch('/lists/{recordList}', [RecordListsController::class, 'update'])->whereUuid('recordList')->name('lists.update');
            Route::delete('/lists/{recordList}', [RecordListsController::class, 'destroy'])->whereUuid('recordList')->name('lists.destroy');
            Route::get('/friends/invite', [InvitationsController::class, 'create'])->name('invitations.create');
            Route::post('/friends/invite', [InvitationsController::class, 'store'])->middleware('throttle:6,1')->name('invitations.store');
            Route::get('/friends/manage', [SocialController::class, 'friends'])->name('friends.manage');
            Route::post('/friends/{user}', [SocialController::class, 'friend'])->whereUuid('user')->middleware('throttle:30,1')->name('friend.action');
            Route::get('/settings', fn () => Inertia::render('social/SettingsPage'))->name('settings');
            Route::patch('/settings', [AccountController::class, 'settings']);
            Route::get('/settings/delete-account', [AccountDeletionController::class, 'show'])->name('accountDeletion.show');
            Route::delete('/settings/account', [AccountDeletionController::class, 'destroy'])->middleware('throttle:6,1')->name('accountDeletion.destroy');
            Route::post('/settings/google', [GoogleAuthController::class, 'link'])->middleware('throttle:6,1')->name('google.link');
            Route::patch('/collection/visibility', [CollectionVisibilityController::class, 'update'])->name('collection.visibility.update');
            Route::patch('/shelves/{record}/notes', [RecordNotesController::class, 'update'])->whereUuid('record')->name('shelf.notes.update');
            Route::post('/shelves/{record}/appreciations', [SocialController::class, 'appreciate'])->middleware('throttle:60,1');
            Route::delete('/shelves/{record}/appreciations', [SocialController::class, 'unappreciate']);
            Route::post('/shelves/{record}/comments', [SocialController::class, 'comment'])->middleware('throttle:20,1');
            Route::delete('/shelves/{record}/comments/{comment}', [SocialController::class, 'deleteComment']);
            Route::get('/notifications', [SocialController::class, 'notifications'])->name('notifications');
            Route::post('/notifications/read', [SocialController::class, 'readNotifications']);
            Route::delete('/notifications', [SocialController::class, 'clearNotifications']);
            Route::post('/discogs/connect', [DiscogsConnectionController::class, 'connect'])->middleware('throttle:6,1');
            Route::get('/discogs/callback', [DiscogsConnectionController::class, 'callback'])->name('discogs.callback');
            Route::get('/discogs/status', [DiscogsConnectionController::class, 'status'])->name('discogs.status');
            Route::post('/discogs/sync', [DiscogsConnectionController::class, 'sync'])->middleware('throttle:3,60');
            Route::delete('/discogs', [DiscogsConnectionController::class, 'disconnect']);
        });
    });

    Route::get('/{user:handle}/lists', UserListsController::class)
        ->where('user', '[a-z0-9_]{3,30}')->withoutMiddleware(EnsureCollectionAccess::class)->name('profile.lists');
    Route::get('/{user:handle}/records/{record}/{slug?}', [SocialController::class, 'profileRecord'])
        ->where('user', '[a-z0-9_]{3,30}')->whereUuid('record')->scopeBindings()->name('profile.record');
    Route::get('/{user:handle}', [SocialController::class, 'profile'])->where('user', '[a-z0-9_]{3,30}')->name('profile');
});
