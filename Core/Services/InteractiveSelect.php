<?php

declare(strict_types=1);

namespace Forge\Core\Services;

use Forge\Core\DI\Attributes\Service;

#[Service]
final class InteractiveSelect
{
    private bool $initialRender = true;
    private const VISIBLE_ITEMS = 7;
    
    public function select(array $options, string $prompt = "Select an option", ?int $defaultIndex = null): ?int
    {
        if (empty($options)) {
            return null;
        }
        
        $selectedIndex = $defaultIndex ?? 0;
        $totalOptions = count($options);
        $startIndex = 0;
        
        if (!$this->isInteractive()) {
            return $this->fallbackSelection($options, $prompt, $defaultIndex);
        }
        
        $this->hideCursor();
        
        try {
            $this->setRawMode();
            
            echo "\033[1;36m{$prompt}:\033[0m\n";
            echo "Use ↑↓ to navigate, Enter to select, Esc to cancel\n\n";
            
            $this->initialRender = true;
            
            while (true) {
                $startIndex = $this->calculateStartIndex($selectedIndex, $totalOptions);
                $this->renderMenu($options, $selectedIndex, $startIndex);
                $this->initialRender = false;
                
                $key = $this->readKey();
                
                if ($key === 'up' && $selectedIndex > 0) {
                    $selectedIndex--;
                } elseif ($key === 'down' && $selectedIndex < $totalOptions - 1) {
                    $selectedIndex++;
                } elseif ($key === 'enter') {
                    $this->showCursor();
                    $this->restoreMode();
                    echo "\n";
                    return $selectedIndex;
                } elseif ($key === 'escape') {
                    $this->showCursor();
                    $this->restoreMode();
                    echo "\n";
                    return null;
                }
            }
        } catch (\Exception $e) {
            $this->showCursor();
            $this->restoreMode();
            return $this->fallbackSelection($options, $prompt, $defaultIndex);
        }
    }
    
    private function calculateStartIndex(int $selectedIndex, int $totalOptions): int
    {
        $visibleCount = min(self::VISIBLE_ITEMS, $totalOptions);
        
        if ($selectedIndex < $visibleCount) {
            return 0;
        }
        
        if ($selectedIndex >= $totalOptions - $visibleCount) {
            return max(0, $totalOptions - $visibleCount);
        }
        
        return $selectedIndex - (int)floor($visibleCount / 2);
    }
    
    private function renderMenu(array $options, int $selectedIndex, int $startIndex): void
    {
        $totalOptions = count($options);
        $visibleCount = min(self::VISIBLE_ITEMS, $totalOptions);
        $endIndex = min($startIndex + $visibleCount, $totalOptions);
        
        if (!$this->initialRender) {
            echo "\033[{$visibleCount}A";
        }
        
        for ($i = $startIndex; $i < $endIndex; $i++) {
            $option = $options[$i] ?? '';
            $line = rtrim($option);
            
            if ($i === $selectedIndex) {
                echo "\033[K\033[1;32m> {$line}\033[0m\n";
            } else {
                echo "\033[K  {$line}\033[0m\n";
            }
        }
    }
    
    private function readKey(): ?string
    {
        $read = [STDIN];
        $write = null;
        $except = null;
        
        $result = stream_select($read, $write, $except, 0, 200000);
        if ($result === false || $result === 0) {
            return null;
        }
        
        if (empty($read)) {
            return null;
        }
        
        $char = fread(STDIN, 1);
        
        if ($char === false || $char === '') {
            return null;
        }
        
        if ($char === "\033") {
            $char2 = fread(STDIN, 1);
            if ($char2 === false || $char2 === '') {
                return 'escape';
            }
            if ($char2 === '[') {
                $char3 = fread(STDIN, 1);
                if ($char3 === false || $char3 === '') {
                    return null;
                }
                if ($char3 === 'A') {
                    return 'up';
                } elseif ($char3 === 'B') {
                    return 'down';
                } elseif ($char3 === 'C') {
                    return 'right';
                } elseif ($char3 === 'D') {
                    return 'left';
                }
            }
        } elseif ($char === "\n" || $char === "\r") {
            return 'enter';
        } elseif ($char === ' ') {
            return 'space';
        }
        
        return null;
    }
    
    private function setRawMode(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return;
        }
        
        system('stty -icanon -echo');
    }
    
    private function restoreMode(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return;
        }
        
        system('stty icanon echo');
    }
    
    private function hideCursor(): void
    {
        echo "\033[?25l";
    }
    
    private function showCursor(): void
    {
        echo "\033[?25h";
    }
    
    private function isInteractive(): bool
    {
        if (!function_exists('posix_isatty')) {
            return false;
        }
        return posix_isatty(STDIN);
    }
    
    public function multiSelect(array $options, string $prompt = "Select options", array $defaultSelected = []): ?array
    {
        if (empty($options)) {
            return null;
        }
        
        $selectedIndices = array_flip($defaultSelected);
        $currentIndex = 0;
        $totalOptions = count($options);
        $startIndex = 0;
        
        if (!$this->isInteractive()) {
            return $this->fallbackMultiSelection($options, $prompt, $defaultSelected);
        }
        
        $this->hideCursor();
        
        try {
            $this->setRawMode();
            
            echo "\033[1;36m{$prompt}:\033[0m\n";
            echo "Use ↑↓ to navigate, Space to toggle, Enter to confirm, Esc to cancel\n\n";
            
            $this->initialRender = true;
            
            while (true) {
                $startIndex = $this->calculateStartIndex($currentIndex, $totalOptions);
                $this->renderMultiMenu($options, $currentIndex, $startIndex, $selectedIndices);
                $this->initialRender = false;
                
                $key = $this->readKey();
                
                if ($key === 'up' && $currentIndex > 0) {
                    $currentIndex--;
                } elseif ($key === 'down' && $currentIndex < $totalOptions - 1) {
                    $currentIndex++;
                } elseif ($key === 'space') {
                    if (isset($selectedIndices[$currentIndex])) {
                        unset($selectedIndices[$currentIndex]);
                    } else {
                        $selectedIndices[$currentIndex] = true;
                    }
                } elseif ($key === 'enter') {
                    $this->showCursor();
                    $this->restoreMode();
                    echo "\n";
                    $result = array_keys($selectedIndices);
                    sort($result);
                    return empty($result) ? null : $result;
                } elseif ($key === 'escape') {
                    $this->showCursor();
                    $this->restoreMode();
                    echo "\n";
                    return null;
                }
            }
        } catch (\Exception $e) {
            $this->showCursor();
            $this->restoreMode();
            return $this->fallbackMultiSelection($options, $prompt, $defaultSelected);
        }
    }
    
    private function renderMultiMenu(array $options, int $currentIndex, int $startIndex, array $selectedIndices): void
    {
        $totalOptions = count($options);
        $visibleCount = min(self::VISIBLE_ITEMS, $totalOptions);
        $endIndex = min($startIndex + $visibleCount, $totalOptions);
        
        if (!$this->initialRender) {
            echo "\033[{$visibleCount}A";
        }
        
        for ($i = $startIndex; $i < $endIndex; $i++) {
            $option = $options[$i] ?? '';
            $line = rtrim($option);
            $isSelected = isset($selectedIndices[$i]);
            $checkbox = $isSelected ? '[✓]' : '[ ]';
            
            if ($i === $currentIndex) {
                echo "\033[K\033[1;32m> {$checkbox} {$line}\033[0m\n";
            } else {
                echo "\033[K  {$checkbox} {$line}\033[0m\n";
            }
        }
    }
    
    private function fallbackSelection(array $options, string $prompt, ?int $defaultIndex): ?int
    {
        echo "\033[1;36m{$prompt}:\033[0m\n\n";
        
        foreach ($options as $index => $option) {
            $number = $index + 1;
            echo "[{$number}] {$option}\n";
        }
        
        echo "\n";
        $input = readline("Enter number (1-" . count($options) . "): ");
        
        if (is_numeric($input)) {
            $selected = (int)$input - 1;
            if ($selected >= 0 && $selected < count($options)) {
                return $selected;
            }
        }
        
        return $defaultIndex;
    }
    
    private function fallbackMultiSelection(array $options, string $prompt, array $defaultSelected): ?array
    {
        echo "\033[1;36m{$prompt}:\033[0m\n\n";
        
        foreach ($options as $index => $option) {
            $number = $index + 1;
            $default = in_array($index, $defaultSelected, true) ? ' (default)' : '';
            echo "[{$number}] {$option}{$default}\n";
        }
        
        echo "\n";
        $input = readline("Enter numbers separated by commas (e.g., 1,3,5): ");
        
        if (empty($input)) {
            return empty($defaultSelected) ? null : $defaultSelected;
        }
        
        $selected = [];
        $numbers = explode(',', $input);
        foreach ($numbers as $num) {
            $num = trim($num);
            if (is_numeric($num)) {
                $idx = (int)$num - 1;
                if ($idx >= 0 && $idx < count($options)) {
                    $selected[] = $idx;
                }
            }
        }
        
        return empty($selected) ? null : $selected;
    }

    public function selectMultiColumn(array $options, string $prompt = "Select an option", ?int $defaultIndex = null, ?int $terminalWidth = null): ?int
    {
        if (empty($options)) {
            return null;
        }

        $terminalWidth = $terminalWidth ?? $this->getTerminalWidth();
        $minColumnWidth = 30;
        
        // Calculate longest option (strip ANSI codes for length calculation)
        $longestOption = 0;
        foreach ($options as $option) {
            $cleanOption = preg_replace('/\033\[[0-9;]*m/', '', $option);
            $longestOption = max($longestOption, strlen($cleanOption));
        }
        
        $optimalColumnWidth = max($longestOption + 4, $minColumnWidth);
        $actualColumns = max(1, (int)floor($terminalWidth / $optimalColumnWidth));
        
        // If we have few options or only 1 column fits, use single column layout
        if ($actualColumns === 1 || count($options) <= 5) {
            return $this->select($options, $prompt, $defaultIndex);
        }

        $selectedIndex = $defaultIndex ?? 0;
        $totalOptions = count($options);
        $rowsPerColumn = (int)ceil($totalOptions / $actualColumns);
        
        if (!$this->isInteractive()) {
            return $this->fallbackSelection($options, $prompt, $defaultIndex);
        }
        
        $this->hideCursor();
        
        try {
            $this->setRawMode();
            
            echo "\033[1;36m{$prompt}:\033[0m\n";
            echo "Use ↑↓←→ to navigate, Enter to select, Esc to cancel\n";
            flush();
            
            $this->initialRender = true;
            
            while (true) {
                if ($this->initialRender) {
                    $this->renderMultiColumnMenu($options, $selectedIndex, $actualColumns, $rowsPerColumn, $optimalColumnWidth);
                    $this->initialRender = false;
                }
                
                $key = $this->readKey();
                
                if ($key === null) {
                    continue;
                }
                
                if ($key === 'up') {
                    $newIndex = max(0, $selectedIndex - $actualColumns);
                    if ($newIndex !== $selectedIndex) {
                        $selectedIndex = $newIndex;
                        $this->renderMultiColumnMenu($options, $selectedIndex, $actualColumns, $rowsPerColumn, $optimalColumnWidth);
                    }
                } elseif ($key === 'down') {
                    $newIndex = min($totalOptions - 1, $selectedIndex + $actualColumns);
                    if ($newIndex !== $selectedIndex) {
                        $selectedIndex = $newIndex;
                        $this->renderMultiColumnMenu($options, $selectedIndex, $actualColumns, $rowsPerColumn, $optimalColumnWidth);
                    }
                } elseif ($key === 'left') {
                    $currentCol = $selectedIndex % $actualColumns;
                    if ($currentCol > 0) {
                        $newIndex = $selectedIndex - 1;
                        if ($newIndex !== $selectedIndex) {
                            $selectedIndex = $newIndex;
                            $this->renderMultiColumnMenu($options, $selectedIndex, $actualColumns, $rowsPerColumn, $optimalColumnWidth);
                        }
                    }
                } elseif ($key === 'right') {
                    $currentCol = $selectedIndex % $actualColumns;
                    if ($currentCol < $actualColumns - 1 && $selectedIndex + 1 < $totalOptions) {
                        $newIndex = $selectedIndex + 1;
                        if ($newIndex !== $selectedIndex) {
                            $selectedIndex = $newIndex;
                            $this->renderMultiColumnMenu($options, $selectedIndex, $actualColumns, $rowsPerColumn, $optimalColumnWidth);
                        }
                    }
                } elseif ($key === 'enter') {
                    $this->showCursor();
                    $this->restoreMode();
                    echo "\n";
                    return $selectedIndex;
                } elseif ($key === 'escape') {
                    $this->showCursor();
                    $this->restoreMode();
                    echo "\n";
                    return null;
                }
            }
        } catch (\Exception $e) {
            $this->showCursor();
            $this->restoreMode();
            return $this->fallbackSelection($options, $prompt, $defaultIndex);
        }
    }

    private function renderMultiColumnMenu(array $options, int $selectedIndex, int $columns, int $rowsPerColumn, int $columnWidth): void
    {
        $totalOptions = count($options);
        $totalRows = (int)ceil($totalOptions / $columns);
        
        if (!$this->initialRender) {
            echo "\033[{$totalRows}A";
        }
        
        for ($row = 0; $row < $totalRows; $row++) {
            $line = '';
            for ($col = 0; $col < $columns; $col++) {
                $index = ($row * $columns) + $col;
                
                if ($index >= $totalOptions) {
                    $line .= str_repeat(' ', $columnWidth);
                    continue;
                }
                
                $option = $options[$index] ?? '';
                $option = substr($option, 0, $columnWidth - 4);
                $paddedOption = str_pad($option, $columnWidth - 4);
                
                if ($index === $selectedIndex) {
                    $line .= "\033[1;32m> {$paddedOption}\033[0m";
                } else {
                    $line .= "  {$paddedOption}";
                }
            }
            echo "\033[K" . $line . "\n";
        }
        flush();
    }

    private function getTerminalWidth(): int
    {
        if (function_exists('exec')) {
            $output = [];
            $return = 0;
            @exec('tput cols 2>/dev/null', $output, $return);
            if ($return === 0 && !empty($output) && is_numeric($output[0])) {
                return (int)$output[0];
            }
        }
        
        if (isset($_ENV['COLUMNS']) && is_numeric($_ENV['COLUMNS'])) {
            return (int)$_ENV['COLUMNS'];
        }
        
        return 80;
    }
}

