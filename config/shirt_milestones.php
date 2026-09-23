<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Milestone cadence
    |--------------------------------------------------------------------------
    |
    | Every completed interval of tenure since the employee's latest hire or
    | rehire opens a new shirt milestone. Anchored per employment stint, so a
    | rehired employee starts earning shirts again a month after coming back.
    |
    */

    'interval_months' => (int) env('SHIRT_MILESTONE_INTERVAL_MONTHS', 1),

    /*
    |--------------------------------------------------------------------------
    | Generation floor
    |--------------------------------------------------------------------------
    |
    | Milestones whose due date falls before this are never generated. Without
    | it the first run backfills every month of every long-tenured employee's
    | history at once. Set this to the date the feature goes live.
    |
    | Override per-run with: php artisan shirts:generate-milestones --since=
    |
    */

    'generate_from' => env('SHIRT_MILESTONE_GENERATE_FROM'),

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    |
    | 'entry'      - notified when a milestone opens; fills in the shirt form.
    | 'fulfilment' - notified once an entry is submitted; orders the shirt and
    |                marks it delivered. This role has NOT been chosen yet, so
    |                it is deliberately empty by default.
    |
    | These are notification TARGETS only. Authorization is the auth server's
    | call, as it is for every other route in this app — auth.token.store sends
    | it the route name and method and requires ext.authorized back.
    |
    | While 'fulfilment' is empty the submitted-notification is skipped with a
    | logged warning rather than sent with an empty role list, which
    | NotificationsPizza would resolve as "every role in these stores". Naming
    | the role later is one env change with no code deploy.
    |
    */

    'roles' => [

        'entry' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SHIRT_MILESTONE_ENTRY_ROLES', 'Store Manager'))
        ))),

        'fulfilment' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SHIRT_MILESTONE_FULFILMENT_ROLES', ''))
        ))),

    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    */

    'uploads' => [
        // Kilobytes, matching Laravel's `max:` validation unit.
        'max_size' => (int) env('SHIRT_MILESTONE_UPLOAD_MAX_KB', 2048),
    ],

];
