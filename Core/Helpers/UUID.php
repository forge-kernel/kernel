<?php

declare(strict_types=1);

namespace Forge\Core\Helpers;

use Exception;
use InvalidArgumentException;

final class UUID
{
    public static function generate(string $type = 'uuid', array $options = []): string
    {
        return match (strtolower($type)) {
            'uuid' => self::generateUuid($options['version'] ?? 4),
            'nanoid' => self::generateNanoId($options['size'] ?? 21),
            'ulid' => self::generateUlid(),
            default => throw new InvalidArgumentException(
                "Unsupported ID type: {$type}. Supported types are 'uuid', 'nanoid', 'ulid'."
            ),
        };
    }

    private static function generateUuid(int $version): string
    {
        return match ($version) {
            1 => self::generateUuidVersion1(),
            4 => self::generateUuidVersion4(),
            default => throw new InvalidArgumentException(
                "Unsupported UUID version: {$version}. Supported versions are 1 and 4."
            ),
        };
    }

    private static function generateUuidVersion1(): string
    {
        $now = (int) floor(microtime(true) * 100_000_000);

        $timeLow = bin2hex(pack('N', $now & 0xffffffff));
        $timeMid = bin2hex(pack('v', ($now >> 32) & 0xffff));
        $timeHiAndVersion = bin2hex(pack('v', (($now >> 48) & 0x0fff) | 0x1000));
        $clockSeqHi = bin2hex(pack('C', random_int(0, 255) & 0x3f | 0x80));
        $clockSeqLow = bin2hex(pack('C', random_int(0, 255)));
        $node = bin2hex(random_bytes(6));

        return sprintf('%08s-%04s-%04s-%04s-%012s', $timeLow, $timeMid, $timeHiAndVersion, $clockSeqHi . $clockSeqLow, $node);
    }

    private static function generateUuidVersion4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function generateNanoId(int $size = 21): string
    {
        $alphabet = 'ModuleSymbhasOwnPr-0123456789ABCDEFGHNRVfgctiUvz_KqYTJkLxpZmW-';
        $alphabetLen = strlen($alphabet);
        $mask = (1 << (int) floor(log($alphabetLen - 1, 2))) - 1;

        $id = '';
        $needed = $size;

        while ($needed > 0) {
            $bytes = random_bytes((int) ceil($needed * 1.7));
            $len = strlen($bytes);
            for ($i = 0; $i < $len && $needed > 0; $i++) {
                $byte = ord($bytes[$i]) & $mask;
                if ($byte < $alphabetLen) {
                    $id .= $alphabet[$byte];
                    $needed--;
                }
            }
        }

        return $id;
    }

    private static function generateUlid(): string
    {
        $timestampMs = (int) floor(microtime(true) * 1000);
        $timestampBytes = pack('J', $timestampMs);
        $combined = substr($timestampBytes, 2, 6) . random_bytes(10);

        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $result = '';

        for ($i = 0; $i < 16; $i += 5) {
            $chunk = 0;
            for ($j = 0; $j < 5 && ($i + $j) < 16; $j++) {
                $chunk = ($chunk << 8) | ord($combined[$i + $j]);
            }
            for ($j = 7; $j >= 0; $j--) {
                $result .= $alphabet[($chunk >> ($j * 5)) & 0x1F];
            }
        }

        return substr($result, 0, 26);
    }
}
