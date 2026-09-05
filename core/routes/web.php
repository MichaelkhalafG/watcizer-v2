<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Wave 0 walking skeleton. Authentication and the /manage/* dashboard routes arrive in wave 4.
Route::get('/', fn () => Inertia::render('Dashboard'))->name('dashboard');
