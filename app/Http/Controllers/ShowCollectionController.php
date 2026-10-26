<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;

class ShowCollectionController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->route('profile', User::freek()->handle, 301);
    }
}
