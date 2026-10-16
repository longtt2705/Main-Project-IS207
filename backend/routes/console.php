<?php

use App\Services\Payment\SlotHoldService;
use App\Services\TicketService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('bookings:expire', function () {
    $holds = app(SlotHoldService::class)->expireOverdueHolds();
    $tickets = app(TicketService::class)->expireUnused();
    $this->info("Released {$holds} holds; expired {$tickets} unused tickets.");
})->purpose('Release unpaid holds and mark unused tickets / appointments as expired / no-show');

Schedule::command('bookings:expire')->everyMinute()->withoutOverlapping();

Artisan::command('tickets:issue-missing', function () {
    $this->info('Issued '.app(TicketService::class)->issueMissing().' missing tickets.');
    app(TicketService::class)->expireUnused();
})->purpose('Backfill tickets for existing paid, confirmed hospital bookings after migration');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
