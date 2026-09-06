<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Queue notification email
    |--------------------------------------------------------------------------
    |
    | Whether the supplementary email that accompanies a notification is handed
    | to the queue instead of being sent inside the request.
    |
    | OFF BY DEFAULT, AND THAT IS THE SAFE DEFAULT. Queued mail needs a running
    | worker for anybody to hear anything. The initial production target is
    | Hostinger Premium shared hosting, which offers scheduled cron tasks
    | rather than persistent processes -- so a deployment without a worker cron
    | would silently stop sending email altogether. Switching this on is a
    | deliberate act taken once a worker is genuinely running, not something a
    | deployment should inherit by accident.
    |
    | WHAT IT CHANGES WHEN ON. `auctions:tick` closes auctions and, for each
    | winner, sends "you won" and "here is your settlement checkout". Those are
    | SMTP round trips inside the sweep. Handing them to the queue keeps the
    | sweep bounded by database work, which matters when the schedule is every
    | minute and the overlap lock is five.
    |
    | WHAT IT NEVER CHANGES. No auction outcome, payment, credit movement,
    | inventory movement or settlement depends on email in either mode. The
    | in-app notification is written and committed before any of this runs, and
    | a mail failure is recorded on that row rather than raised.
    |
    */

    'queue_mail' => (bool) env('NOTIFICATIONS_QUEUE_MAIL', false),

    /*
    |--------------------------------------------------------------------------
    | Queue name
    |--------------------------------------------------------------------------
    |
    | Kept separate from the default queue so a worker can be pointed at
    | communication work specifically, and so a backlog of email can never
    | delay anything else that is queued later.
    |
    */

    'queue' => env('NOTIFICATIONS_QUEUE', 'notifications'),

];
