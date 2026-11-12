<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class SourceScanner
{
    public function scan(string $path): void
    {
        $binary = config('sources.scanner_binary');
        if (!is_file($binary)) {
            throw new RuntimeException('Malware scanning is unavailable. Ask the administrator to configure the source scanner.');
        }
        $args = match (config('sources.scanner')) {
            'defender' => [$binary, '-Scan', '-ScanType', '3', '-File', $path, '-DisableRemediation'],
            'clamav' => [$binary, '--no-summary', '--infected', $path],
            default => throw new RuntimeException('The source scanner is not configured.'),
        };
        $process = new Process($args, dirname($path), ['TEMP' => sys_get_temp_dir(), 'TMP' => sys_get_temp_dir()]);
        $process->setTimeout(45);
        try { $process->run(); } catch (\Throwable) {
            throw new RuntimeException('The malware scan did not finish. Upload the file again later.');
        }
        if (!$process->isSuccessful()) {
            throw new RuntimeException('The file failed the malware scan or the scanner is unavailable. It has been removed.');
        }
    }
}
