<?php

declare(strict_types=1);

namespace Whiterhino\Imaging\Tests\Feature;

use Whiterhino\Imaging\Tests\TestCase;

final class DiagnoseCommandTest extends TestCase
{
    public function test_diagnose_command_reports_success(): void
    {
        $this->artisan('imaging:diagnose')
            ->expectsOutput('Imaging diagnostics completed successfully.')
            ->assertExitCode(0);
    }

    public function test_diagnose_command_reports_missing_disk(): void
    {
        $this->app['config']->set('imaging.def_target_disk', 'missing-disk');

        $this->artisan('imaging:diagnose')
            ->expectsOutput('Detected issues:')
            ->expectsOutput('- Target disk "missing-disk" is not defined in filesystems configuration.')
            ->assertExitCode(1);
    }
}
