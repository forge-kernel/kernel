<?php

declare(strict_types=1);

namespace Forge\Core\Helpers;

final class File
{
    private const BUCKET_PATH = BASE_PATH . "/storage/app/";

    public static function folderSize($dir)
    {
        $size = 0;
        foreach (glob(rtrim($dir, '/').'/*', GLOB_NOSORT) as $each) {
            $size += is_file($each) ? filesize($each) : self::folderSize($each);
        }
        return $size;
    }

    public static function bucketSize(string $bucket): string
    {
        $directory = self::BUCKET_PATH . $bucket;
        return Format::fileSize(self::folderSize($directory));
    }

    public static function countDirectoryFiles(string $bucket): int
    {
        $fileCount = 0;
        $directory = self::BUCKET_PATH . $bucket . '/user-files/';
        $files = glob($directory . "*");

        if ($files) {
            $fileCount = count($files);
        }

        return $fileCount;
    }
}
