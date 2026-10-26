<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmailVerificationRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AccountController extends Controller
{
    public function register(Request $request): RedirectResponse
    {
        $request->merge(['email' => strtolower($request->string('email')->trim()), 'handle' => strtolower($request->string('handle')->trim())]);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'handle' => ['nullable', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9_]+$/', Rule::notIn(User::RESERVED_HANDLES), 'unique:users'],
            'email' => ['required', 'email', 'max:254', 'unique:users'],
            'password' => ['required', PasswordRule::min(12)],
        ], [], ['handle' => 'username']);

        $user = User::query()->create($data);
        event(new Registered($user));
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect('/verify-email');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $data['email'] = strtolower($data['email']);
        if (! Auth::attempt($data, remember: true)) {
            throw ValidationException::withMessages(['email' => 'The supplied credentials are not correct.']);
        }
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Inertia::clearHistory();

        return redirect('/');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->intended('/friends')->with('status', 'Email verified. Welcome to Plaat Club.');
    }

    public function resend(Request $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back();
    }

    public function forgot(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => strtolower($request->string('email')->trim())]);

        return back()->with('status', 'If that account exists, a reset link has been sent.');
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'], 'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);
        $data['email'] = strtolower($data['email']);
        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect('/login')->with('status', 'Password reset. You can now sign in.');
    }

    public function settings(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $request->merge(['handle' => strtolower($request->string('handle')->trim())]);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'handle' => ['required', 'min:3', 'max:30', 'regex:/^[a-z0-9_]+$/', Rule::notIn(User::RESERVED_HANDLES), Rule::unique('users')->ignore($user->id)],
            'visibility' => ['required', Rule::in($user->allowedCollectionVisibilities())],
            'comments_enabled' => ['required', 'boolean'], 'digest_enabled' => ['required', 'boolean'],
        ], [], ['handle' => 'username']);
        $user->update($data);

        return back()->with('status', 'Your preferences have been saved.');
    }
}
