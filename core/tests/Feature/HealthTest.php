<?php

use function Pest\Laravel\get;

it('answers the health route', function () {
    get('/up')->assertOk();
});
