<?php

declare(strict_types=1);

namespace Forge\Core\Services;

use Forge\Core\DI\Attributes\Service;

#[Service]
final class GitService
{
    public function init(string $path): bool
    {
        $command = "cd " . escapeshellarg($path) . " && git init";
        exec($command . " 2>&1", $output, $exitCode);
        return $exitCode === 0;
    }
    
    public function setRemote(string $path, string $name, string $url): bool
    {
        $command = "cd " . escapeshellarg($path) . " && git remote add " . escapeshellarg($name) . " " . escapeshellarg($url) . " 2>&1 || git remote set-url " . escapeshellarg($name) . " " . escapeshellarg($url);
        exec($command . " 2>&1", $output, $exitCode);
        return $exitCode === 0;
    }
    
    public function setAuth(string $path, string $token): void
    {
        $url = $this->getRemoteUrl($path, 'origin');
        if (!$url) {
            return;
        }
        
        if (str_starts_with($url, 'https://')) {
            $urlWithToken = str_replace('https://', 'https://' . $token . '@', $url);
            $this->setRemote($path, 'origin', $urlWithToken);
        }
    }
    
    public function getRemoteUrl(string $path, string $name): ?string
    {
        $command = "cd " . escapeshellarg($path) . " && git remote get-url " . escapeshellarg($name) . " 2>&1";
        exec($command, $output, $exitCode);
        
        if ($exitCode !== 0 || empty($output)) {
            return null;
        }
        
        return trim($output[0]);
    }
    
    public function addAll(string $path): bool
    {
        $command = "cd " . escapeshellarg($path) . " && git add .";
        exec($command . " 2>&1", $output, $exitCode);
        return $exitCode === 0;
    }
    
    public function commit(string $path, string $message): bool
    {
        $command = "cd " . escapeshellarg($path) . " && git commit -m " . escapeshellarg($message);
        exec($command . " 2>&1", $output, $exitCode);
        return $exitCode === 0;
    }
    
    public function push(string $path, string $branch, ?string $token = null): bool
    {
        if ($token) {
            $this->setAuth($path, $token);
        }
        
        $command = "cd " . escapeshellarg($path) . " && git push -u origin " . escapeshellarg($branch);
        exec($command . " 2>&1", $output, $exitCode);
        return $exitCode === 0;
    }
    
    public function pull(string $path, string $remote = 'origin', string $branch = 'main'): bool
    {
        $command = "cd " . escapeshellarg($path) . " && git pull " . escapeshellarg($remote) . " " . escapeshellarg($branch);
        exec($command . " 2>&1", $output, $exitCode);
        return $exitCode === 0;
    }
    
    public function isGitRepository(string $path): bool
    {
        return is_dir($path . '/.git') || file_exists($path . '/.git');
    }
    
    public function addFile(string $path, string $file): bool
    {
        $originalDir = getcwd();
        if (!@chdir($path)) {
            return false;
        }
        
        try {
            $command = "git add " . escapeshellarg($file) . " 2>&1";
            exec($command, $output, $exitCode);
            chdir($originalDir);
            return $exitCode === 0;
        } catch (\Exception $e) {
            chdir($originalDir);
            return false;
        }
    }
    
    public function commitFile(string $path, string $file, string $message): bool
    {
        $originalDir = getcwd();
        if (!@chdir($path)) {
            return false;
        }
        
        try {
            $command = "git commit -m " . escapeshellarg($message) . " -- " . escapeshellarg($file) . " 2>&1";
            exec($command, $output, $exitCode);
            chdir($originalDir);
            return $exitCode === 0;
        } catch (\Exception $e) {
            chdir($originalDir);
            return false;
        }
    }
    
    public function getLastCommitMessage(string $path, ?string $file = null): ?string
    {
        if (!$this->isGitRepository($path)) {
            return null;
        }
        
        $originalDir = getcwd();
        if (!@chdir($path)) {
            return null;
        }
        
        try {
            if ($file !== null) {
                $filePath = $path . '/' . $file;
                if (!file_exists($filePath)) {
                    chdir($originalDir);
                    return null;
                }
                $command = "git log -1 --format=%s -- " . escapeshellarg($file) . " 2>&1";
            } else {
                $command = "git log -1 --format=%s 2>&1";
            }
            
            exec($command, $output, $exitCode);
            
            chdir($originalDir);
            
            if ($exitCode !== 0 || empty($output)) {
                return null;
            }
            
            $message = trim(implode(' ', $output));
            
            if (empty($message)) {
                return null;
            }
            
            if (strlen($message) > 80) {
                return substr($message, 0, 77) . '...';
            }
            
            return $message;
        } catch (\Exception $e) {
            chdir($originalDir);
            return null;
        }
    }
    
    public function getCurrentBranch(string $path): ?string
    {
        if (!$this->isGitRepository($path)) {
            return null;
        }
        
        $originalDir = getcwd();
        if (!@chdir($path)) {
            return null;
        }
        
        try {
            $command = "git rev-parse --abbrev-ref HEAD 2>&1";
            exec($command, $output, $exitCode);
            chdir($originalDir);
            
            if ($exitCode === 0 && !empty($output)) {
                return trim($output[0]);
            }
            
            return null;
        } catch (\Exception $e) {
            chdir($originalDir);
            return null;
        }
    }
    
    public function getRemoteBranch(string $path, string $remote = 'origin'): ?string
    {
        if (!$this->isGitRepository($path)) {
            return null;
        }
        
        $originalDir = getcwd();
        if (!@chdir($path)) {
            return null;
        }
        
        try {
            $command = "git symbolic-ref refs/remotes/{$remote}/HEAD 2>&1 | sed 's@^refs/remotes/{$remote}/@@'";
            exec($command, $output, $exitCode);
            
            if ($exitCode !== 0 || empty($output)) {
                $command = "git remote show {$remote} 2>&1 | grep 'HEAD branch' | cut -d' ' -f5";
                exec($command, $output, $exitCode);
            }
            
            chdir($originalDir);
            
            if ($exitCode === 0 && !empty($output)) {
                return trim($output[0]);
            }
            
            return null;
        } catch (\Exception $e) {
            chdir($originalDir);
            return null;
        }
    }
}

