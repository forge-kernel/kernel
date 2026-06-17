<?php

declare(strict_types=1);

namespace Forge\CLI\Traits;

use Forge\CLI\Traits\OutputHelper;

trait ManagesMaintenanceMode
{
    use OutputHelper;

    private const string MAINTENANCE_SOURCE = BASE_PATH . '/kernel/Core/Http/ErrorPages/maintenance.html';
    private const string MAINTENANCE_DEST = BASE_PATH . '/storage/framework/maintenance.html';

    public function enableMaintenance(): int
    {
        if (!file_exists(self::MAINTENANCE_SOURCE)) {
            $this->error("Maintenance file not found at " . self::MAINTENANCE_SOURCE);
            return 1;
        }

        if (copy(self::MAINTENANCE_SOURCE, self::MAINTENANCE_DEST)) {
            $this->success("Maintenance mode enabled. File copied to " . self::MAINTENANCE_DEST);
            return 0;
        }

        $this->error("Failed to copy maintenance file");
        return 1;
    }

    public function disableMaintenance(): int
    {
        if (!file_exists(self::MAINTENANCE_DEST)) {
            $this->error("Maintenance file not found at " . self::MAINTENANCE_DEST);
            return 1;
        }

        if (unlink(self::MAINTENANCE_DEST)) {
            $this->success("Maintenance mode disabled. File deleted from " . self::MAINTENANCE_DEST);
            return 0;
        }

        $this->error("Failed to delete maintenance file");
        return 1;
    }

    public function isMaintenanceEnabled(): bool
    {
        return file_exists(self::MAINTENANCE_DEST);
    }
}
