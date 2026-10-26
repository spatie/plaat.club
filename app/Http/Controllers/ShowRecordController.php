<?php

namespace App\Http\Controllers;

use App\Models\CollectionRecord;
use Illuminate\Http\RedirectResponse;

class ShowRecordController extends Controller
{
    public function __invoke(string $instanceId): RedirectResponse
    {
        $record = CollectionRecord::legacy()->where('public_id', $instanceId)->firstOrFail();

        return redirect()->to($record->url(), 301);
    }
}
