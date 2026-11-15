<?php

use Illuminate\Support\Facades\Schedule;

// Delete messages older than the retention period and stale invitations.
Schedule::command('model:prune')->daily()->withoutOverlapping();

// Deliver messages published with a delay (X-Delay).
Schedule::command('tocsin:dispatch-scheduled')->everyMinute()->withoutOverlapping();

// Paid relay plans that were not renewed go back to the free plan.
Schedule::command('tocsin:expire-relay-plans')->hourly();

// Remind subscribers (and email on-call people) about unacknowledged messages.
Schedule::command('tocsin:escalate')->everyMinute()->withoutOverlapping();
