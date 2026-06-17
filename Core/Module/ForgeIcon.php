<?php

declare(strict_types=1);

namespace Forge\Core\Module;

enum ForgeIcon: string
{
    case COG = 'cog';
    case LOG = 'file-lines';
    case STORAGE = 'box-archive';
    case QUEUE = 'list';
    case CACHE = 'bolt';
    case COMMAND = 'terminal';
    case CLOCK = 'clock';
    case MONITOR = 'chart-line';
    case DEPLOY = 'rocket';
}
