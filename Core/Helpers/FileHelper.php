<?php

declare(strict_types=1);

namespace Forge\Core\Helpers;

final class File
{
    public static function folderSize($dir)
    {
        $size = 0;
        foreach (glob(rtrim($dir, '/').'/*', GLOB_NOSORT) as $each) {
            $size += is_file($each) ? filesize($each) : self::folderSize($each);
        }
        return $size;
    }

    public static function bucketSize(string $bucket)
    {
        $folder = BASE_PATH . "/storage/app/$bucket";
        return self::folderSize($folder);
    }
}
