<?php

return [
    'admin_emails' => array_filter(
        array_map('trim', explode(',', env('ORDER_ADMIN_EMAILS', '')))
    ),
];
