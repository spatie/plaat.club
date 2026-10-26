<?php

namespace App\Http\Controllers;

use App\Models\User;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        return $this->start($request);
    }

    public function link(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'string', 'current_password:web']]);
        $user = $request->user();
        if ($user->google_id !== null) {
            return redirect('/settings')->with('status', 'Google sign-in is already connected.');
        }

        return $this->start($request, $user);
    }

    protected function start(Request $request, ?User $user = null): RedirectResponse
    {
        $provider = $this->provider();
        $request->session()->put('google_pending', [
            'user_id' => $user?->id,
            'password_hash' => $user?->password,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        return $provider->with(['prompt' => 'select_account'])->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull('google_pending');
        $destination = $request->user() ? '/settings' : '/login';

        try {
            if (! is_array($pending) || $pending['expires_at'] < now()->timestamp
                || $pending['user_id'] !== $request->user()?->id || $request->has('error')) {
                return redirect($destination)->withErrors(['google' => 'Google sign-in was cancelled or expired. Please try again.']);
            }

            $google = $this->provider()->user();
            $email = strtolower(trim((string) $google->getEmail()));
            $identity = $google instanceof GoogleUser ? $google->getRaw() : [];
            $googleId = $identity['sub'] ?? null;
            if (($identity['email_verified'] ?? null) !== true
                || ! is_string($googleId) || $googleId === '' || strlen($googleId) > 255
                || Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:254']])->fails()) {
                return redirect($destination)->withErrors(['google' => 'Google must provide a verified email address to continue.']);
            }

            $avatar = $google->getAvatar();
            if (Validator::make(['avatar' => $avatar], ['avatar' => ['nullable', 'url:https', 'max:2048']])->fails()) {
                $avatar = null;
            }

            if ($pending['user_id'] !== null) {
                $user = $request->user()->fresh();
                if (! $user->hasVerifiedEmail() || ! hash_equals($user->password, $pending['password_hash'])
                    || $user->email !== $email || $user->google_id !== null
                    || User::query()->where('google_id', $googleId)->exists()) {
                    return redirect('/settings')->withErrors(['google' => 'Google could not be linked. Choose the Google account with your Plaat Club email and confirm your password again.']);
                }

                $updated = User::query()->whereKey($user->id)->where('password', $pending['password_hash'])
                    ->whereNull('google_id')->update(['google_id' => $googleId, 'google_avatar_url' => $avatar]);
                if (! $updated) {
                    return redirect('/settings')->withErrors(['google' => 'Your Google connection changed. Please try again.']);
                }

                $request->session()->regenerate();

                return redirect('/settings')->with('status', 'Google sign-in connected. You can now use either Google or your password.');
            }

            $user = User::query()->where('google_id', $googleId)->first();
            if (! $user) {
                if (User::query()->where('email', $email)->exists()) {
                    return redirect('/login')->withErrors(['google' => 'An account already uses this email. Sign in with your password, then connect Google in Settings.']);
                }

                $user = User::query()->create([
                    'email' => $email, 'google_id' => $googleId,
                    'name' => Str::substr(trim((string) $google->getName()), 0, 80),
                    'password' => Str::random(64), 'email_verified_at' => now(),
                ]);
                event(new Registered($user));
            }

            if ($user->email === $email && ! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
                event(new Verified($user));
            }

            $user->update(['google_avatar_url' => $avatar]);
            Auth::login($user, remember: true);
            $request->session()->regenerate();

            return redirect()->intended(route('home'));
        } catch (InvalidStateException|GuzzleException) {
            return redirect($destination)->withErrors(['google' => 'Google sign-in could not be completed. Please try again.']);
        } catch (UniqueConstraintViolationException) {
            return redirect($destination)->withErrors(['google' => 'This account changed while connecting. Please sign in again and retry.']);
        } finally {
            $request->session()->forget(['state', 'code_verifier']);
        }
    }

    protected function provider(): GoogleProvider
    {
        abort_unless(config('services.google.client_id') && config('services.google.client_secret'), 404);

        /** @var GoogleProvider $provider */
        $provider = Socialite::driver('google');

        return $provider->enablePKCE();
    }
}
