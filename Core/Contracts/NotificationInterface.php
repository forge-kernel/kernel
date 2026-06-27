<?php

declare(strict_types=1);

namespace Forge\Core\Contracts;

interface NotificationInterface
{
  public function email(): object;

  public function sms(): object;

  public function push(): object;
}
