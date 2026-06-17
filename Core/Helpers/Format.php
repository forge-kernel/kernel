<?php

declare(strict_types=1);

namespace Forge\Core\Helpers;

final class Format
{
    /**
     * Format error code
     *
     * @param string $string
     * @return <h1><span>4</span><span>0</span><span>4</span></h1>
     */
    public static function errorCode(mixed $errorCode)
    {
        $errorCodeString = (string) $errorCode;
        $html = '<h1>';
        foreach (str_split($errorCodeString) as $digit) {
            $html .= '<span>' . htmlspecialchars($digit) . '</span>';
        }
        $html .= '</h1>';
        return $html;
    }

    /**
    * Format file size to human readable
    *
    * @param int $bytes
    * @return string
    */
    public static function fileSize(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 Bytes';
        }
        $k = 1024;
        $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes) / log($k));
        return sprintf('%.2f %s', ($bytes / pow($k, $i)), $sizes[$i]);
    }
}
