<?php

declare(strict_types=1);

namespace Forge\Core\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

final class ArchiveService
{
    public function createZip(string $sourceDir, string $zipFilePath): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $sourceDir = rtrim($sourceDir, '/');

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($sourceDir) + 1);

                $zip->addFile($filePath, $relativePath);
            }
        }

        return $zip->close();
    }

    public function calculateIntegrity(string $filePath): string|bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        return hash_file('sha256', $filePath);
    }
}
