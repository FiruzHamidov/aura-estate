<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

class DispatchNotificationRemindersCommand extends Command
{
    protected $signature = 'notifications:dispatch-reminders';

    protected $description = 'Dispatch due CRM notification reminders and motivational digests';

    public function handle(NotificationService $notifications): int
    {
        $results = $notifications->dispatchScheduledReminders();

        foreach ($results as $type => $count) {
            $this->line(sprintf('%s: %d', $type, $count));
        }

        return self::SUCCESS;
    }
}
