<?php

declare(strict_types=1);

namespace Forge\Core\Security;

enum PermissionsEnum: string
{
    case RUN_COMMAND = 'run:command';
    case VIEW_COMMAND = 'view:command';
}
