<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class SharedHostingProcess
{
    public function run(int $business, string $task, float $seconds): bool
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'bizismart:shared-unit', (string) $business, $task], base_path());
        $process->setTimeout($seconds);
        // Do not collect tenant details or possible native/provider exception output.
        $process->disableOutput();
        try {
            return $process->run() === 0;
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
            return false;
        }
    }
}
