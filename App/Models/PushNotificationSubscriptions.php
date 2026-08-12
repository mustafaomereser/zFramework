<?php

namespace App\Models;

use zFramework\Core\Abstracts\Model;

/**
 * Subscriptions the push channels read and write. Lives in the application,
 * like SystemDbCollector, so the table is yours to extend.
 */
class PushNotificationSubscriptions extends Model
{
    public $table = "push_notification_subscriptions";
}
