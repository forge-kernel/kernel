<?php

declare(strict_types=1);

namespace Forge\Core\Contracts;

use App\Modules\ForgeNotification\Channels\EmailChannel;
use App\Modules\ForgeNotification\Channels\PushChannel;
use App\Modules\ForgeNotification\Channels\SmsChannel;

/**
 * Interface for notification services that can be registered with the kernel.
 * Modules implementing this interface will be automatically discovered
 * and used for notification handling.
 */
interface NotificationInterface
{
  /**
   * Get the email channel.
   *
   * @return EmailChannel
   */
  public function email(): EmailChannel;

  /**
   * Get the SMS channel.
   *
   * @return SmsChannel
   */
  public function sms(): SmsChannel;

  /**
   * Get the push notification channel.
   *
   * @return PushChannel
   */
  public function push(): PushChannel;
}
