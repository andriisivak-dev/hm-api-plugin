<?php

declare(strict_types=1);

namespace CSP\Brevo;

class SyncQueueFactory
{
    public static function create(): SyncQueueInterface
    {
        if (ActionSchedulerSyncQueue::is_available()) {
            return new ActionSchedulerSyncQueue();
        }

        return new WpCronSyncQueue();
    }
}
