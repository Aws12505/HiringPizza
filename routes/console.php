<?php


Schedule::command('outbox:publish-pending')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Opens a shirt milestone for each completed month of tenure. Idempotent:
// re-running it creates nothing new, so a missed night self-heals.
Schedule::command('shirts:generate-milestones')
    ->dailyAt('02:00')
    ->withoutOverlapping();
