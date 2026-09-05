<?php

use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\get;

it('renders the Dashboard page inside an Arabic RTL shell', function () {
    get('/')
        ->assertOk()
        ->assertSee('lang="ar"', false)
        ->assertSee('dir="rtl"', false)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('locale', 'ar')
            ->where('dir', 'rtl')
            ->where('app.name', config('app.name')));
});
