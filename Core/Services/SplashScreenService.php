<?php

declare(strict_types=1);

namespace Forge\Core\Services;

use Forge\Core\DI\Attributes\Injectable;

#[Injectable]
final class SplashScreenService
{
    private const GREEN_BRIGHT = "\033[1;32m";
    private const GREEN_NORMAL = "\033[0;32m";
    private const RESET = "\033[0m";
    private const BORDER_CHAR = '▓';

    public function showSplashScreen(int $duration = 1500): void
    {
        $this->clearScreen();

        $width = $this->getTerminalWidth();
        $height = $this->getTerminalHeight();

        $border = str_repeat(self::BORDER_CHAR, $width);

        $verticalPadding = max(0, (int) (($height - 15) / 2));

        for ($i = 0; $i < $verticalPadding; $i++) {
            echo PHP_EOL;
        }

        echo self::GREEN_BRIGHT . $border . self::RESET . PHP_EOL;
        echo self::GREEN_BRIGHT . $border . self::RESET . PHP_EOL;
        echo PHP_EOL;

        $forgeText = $this->getSplashAsciiArt();
        $lines = explode("\n", $forgeText);

        foreach ($lines as $line) {
            $linePadding = max(0, (int) (($width - strlen($line)) / 2));
            echo str_repeat(' ', $linePadding) . self::GREEN_BRIGHT . $line . self::RESET . PHP_EOL;
        }

        echo PHP_EOL;
        echo self::GREEN_BRIGHT . $border . self::RESET . PHP_EOL;
        echo self::GREEN_BRIGHT . $border . self::RESET . PHP_EOL;
        echo PHP_EOL;

        $steps = 20;
        $delay = (int) ($duration / $steps);

        for ($i = 0; $i <= $steps; $i++) {
            $progress = (int) (($i / $steps) * 100);
            $this->showLoadingBar($progress, $width);
            usleep($delay * 1000);
        }

        echo PHP_EOL;
        $copyright = "(C) FORGE FRAMEWORK";
        $copyrightPadding = max(0, (int) (($width - strlen($copyright)) / 2));
        echo str_repeat(' ', $copyrightPadding) . self::GREEN_NORMAL . $copyright . self::RESET . PHP_EOL;

        for ($i = 0; $i < $verticalPadding; $i++) {
            echo PHP_EOL;
        }

        usleep(300000);
        $this->clearScreen();
        flush();
        usleep(100000);
    }

    public function getSplashAsciiArt(): string
    {
        return "    F   O   R   G   E";
    }

    public function showLoadingBar(int $progress, int $width = 70): void
    {
        $barWidth = min(40, $width - 20);
        $filled = (int) (($progress / 100) * $barWidth);
        $empty = $barWidth - $filled;

        $bar = str_repeat('█', $filled) . str_repeat('░', $empty);
        $progressText = str_pad((string) $progress, 3, ' ', STR_PAD_LEFT) . '%';

        $barPadding = max(0, (int) (($width - strlen($bar) - strlen($progressText) - 4) / 2));

        echo "\033[K";
        echo str_repeat(' ', $barPadding) . self::GREEN_BRIGHT . '[' . self::RESET;
        echo self::GREEN_NORMAL . $bar . self::RESET;
        echo self::GREEN_BRIGHT . '] ' . $progressText . self::RESET . PHP_EOL;
        echo "\033[A";
    }

    private function clearScreen(): void
    {
        echo "\033[H\033[2J";
    }

    private function getTerminalWidth(): int
    {
        if (function_exists('exec')) {
            $output = [];
            $return = 0;
            @exec('tput cols 2>/dev/null', $output, $return);
            if ($return === 0 && !empty($output) && is_numeric($output[0])) {
                return (int) $output[0];
            }
        }

        if (isset($_ENV['COLUMNS']) && is_numeric($_ENV['COLUMNS'])) {
            return (int) $_ENV['COLUMNS'];
        }

        return 80;
    }

    private function getTerminalHeight(): int
    {
        if (function_exists('exec')) {
            $output = [];
            $return = 0;
            @exec('tput lines 2>/dev/null', $output, $return);
            if ($return === 0 && !empty($output) && is_numeric($output[0])) {
                return (int) $output[0];
            }
        }

        if (isset($_ENV['LINES']) && is_numeric($_ENV['LINES'])) {
            return (int) $_ENV['LINES'];
        }

        return 24;
    }
}
