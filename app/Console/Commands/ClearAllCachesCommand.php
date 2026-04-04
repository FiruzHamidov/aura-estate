<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ClearAllCachesCommand extends Command
{
    protected $signature = 'cache:full-clear {--with-opcache : Attempt to reset OPcache in the current PHP process}';

    protected $description = 'Completely clear Laravel caches and compiled bootstrap cache files';

    public function handle(): int
    {
        $this->info('Starting full cache cleanup...');

        $commands = [
            'optimize:clear',
            'cache:clear',
            'config:clear',
            'route:clear',
            'view:clear',
            'event:clear',
            'clear-compiled',
        ];

        foreach ($commands as $command) {
            $this->line("Running: php artisan {$command}");

            $exitCode = $this->call($command);

            if ($exitCode !== self::SUCCESS) {
                $this->warn("Command finished with code {$exitCode}: {$command}");
            }
        }

        $this->clearBootstrapCache();
        $this->clearFrameworkArtifacts();
        $this->resetOpcacheIfRequested();

        $this->newLine();
        $this->info('Full cache cleanup completed.');

        return self::SUCCESS;
    }

    private function clearBootstrapCache(): void
    {
        $cachePath = base_path('bootstrap/cache');

        if (!File::isDirectory($cachePath)) {
            return;
        }

        foreach (File::files($cachePath) as $file) {
            if ($file->getFilename() === '.gitignore') {
                continue;
            }

            File::delete($file->getPathname());
            $this->line("Deleted bootstrap cache file: {$file->getFilename()}");
        }
    }

    private function clearFrameworkArtifacts(): void
    {
        $paths = [
            storage_path('framework/cache/data'),
            storage_path('framework/views'),
            storage_path('framework/sessions'),
            storage_path('framework/testing'),
        ];

        foreach ($paths as $path) {
            if (!File::exists($path)) {
                continue;
            }

            File::cleanDirectory($path);
            $this->line("Cleaned directory: {$path}");
        }
    }

    private function resetOpcacheIfRequested(): void
    {
        if (!$this->option('with-opcache')) {
            return;
        }

        if (!function_exists('opcache_reset')) {
            $this->warn('OPcache reset skipped: opcache extension is not available.');
            return;
        }

        if (!opcache_reset()) {
            $this->warn('OPcache reset did not report success.');
            return;
        }

        $this->line('OPcache reset requested successfully.');
    }
}
