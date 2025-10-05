<?php

declare(strict_types=1);

namespace Whiterhino\Imaging\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Whiterhino\Imaging\Handlers\GdHandler;
use Whiterhino\Imaging\Handlers\HandlerContract;
use Whiterhino\Imaging\Handlers\ImagickHandler;

final class DiagnoseCommand extends Command
{
    protected $signature = 'imaging:diagnose {--handler=}';

    protected $description = 'Diagnose imaging configuration and environment readiness';

    public function handle(): int
    {
        $handlerOverride = $this->option('handler');
        $handlerClass = $handlerOverride ?: Config::get('imaging.def_handler');
        $issues = [];

        if (!is_string($handlerClass) || $handlerClass === '') {
            $issues[] = 'Default handler class is not configured.';
        } elseif (!class_exists($handlerClass)) {
            $issues[] = sprintf('Handler class %s does not exist.', $handlerClass);
        } elseif (!is_subclass_of($handlerClass, HandlerContract::class)) {
            $issues[] = sprintf('Handler class %s does not implement HandlerContract.', $handlerClass);
        } else {
            $this->info(sprintf('Handler: %s', $handlerClass));
            $issues = array_merge($issues, $this->checkHandlerRequirements($handlerClass));
        }

        $issues = array_merge($issues, $this->checkDisks());
        $issues = array_merge($issues, $this->checkTempDirectory());

        if ($issues === []) {
            $this->info('Imaging diagnostics completed successfully.');

            return Command::SUCCESS;
        }

        $this->error('Detected issues:');

        foreach ($issues as $issue) {
            $this->line('- ' . $issue);
        }

        return Command::FAILURE;
    }

    /**
     * @param class-string<HandlerContract> $handlerClass
     * @return string[]
     */
    private function checkHandlerRequirements(string $handlerClass): array
    {
        return match ($handlerClass) {
            GdHandler::class => $this->checkGdRequirements(),
            ImagickHandler::class => $this->checkImagickRequirements(),
            default => [],
        };
    }

    /**
     * @return string[]
     */
    private function checkGdRequirements(): array
    {
        if (function_exists('imagecreatetruecolor')) {
            $this->info('GD extension: available');

            return [];
        }

        return ['GD extension functions are missing (imagecreatetruecolor not available).'];
    }

    /**
     * @return string[]
     */
    private function checkImagickRequirements(): array
    {
        if (extension_loaded('imagick')) {
            $this->info('Imagick extension: available');

            return [];
        }

        return ['Imagick extension is not loaded.'];
    }

    /**
     * @return string[]
     */
    private function checkDisks(): array
    {
        $origin = Config::get('imaging.def_origin_disk');
        $target = Config::get('imaging.def_target_disk');
        $issues = [];

        foreach (['Origin disk' => $origin, 'Target disk' => $target] as $label => $diskName) {
            if (!is_string($diskName) || $diskName === '') {
                $issues[] = sprintf('%s is not configured.', $label);

                continue;
            }

            try {
                Storage::disk($diskName);
                $this->info(sprintf('%s "%s": available', $label, $diskName));
            } catch (InvalidArgumentException) {
                $issues[] = sprintf('%s "%s" is not defined in filesystems configuration.', $label, $diskName);
            }
        }

        return $issues;
    }

    /**
     * @return string[]
     */
    private function checkTempDirectory(): array
    {
        $tempDir = Config::get('imaging.temp_dir') ?? sys_get_temp_dir();

        if (!is_string($tempDir) || $tempDir === '') {
            return ['Temporary directory is not configured.'];
        }

        if (!file_exists($tempDir)) {
            return [sprintf('Temporary directory "%s" does not exist.', $tempDir)];
        }

        if (!is_writable($tempDir)) {
            return [sprintf('Temporary directory "%s" is not writable.', $tempDir)];
        }

        $this->info(sprintf('Temporary directory "%s": writable', $tempDir));

        return [];
    }
}
