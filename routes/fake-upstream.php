<?php

use App\Http\Controllers\FakeUpstreamController;
use Illuminate\Support\Facades\Route;

// Stand-in upstream API for local runs and tests (see App\Upstream\Fake\FakeUpstream)
Route::get('/fake-upstream/{account}/profiles/{username}', FakeUpstreamController::class)
    ->name('fake-upstream.profile');
