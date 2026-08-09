<?php

namespace App\Console\Commands;

use App\Enums\KlikQrisEnvironment;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use Illuminate\Console\Command;

class ValidateKlikQrisProduction extends Command
{
    protected $signature = 'klikqris:validate-production';

    protected $description = 'Report sanitized KlikQRIS production readiness without provider traffic or transaction creation.';

    public function handle(KlikQrisProviderReadiness $readiness): int
    {
        $report = $readiness->forEnvironment(KlikQrisEnvironment::Production);
        $checks = [];

        foreach ($report->missing() as $label) {
            $checks[] = ['status' => 'fail', 'label' => $label.' configured', 'message' => 'Value is blank or disabled.'];
        }

        foreach ($report->invalid() as $label => $message) {
            $checks[] = ['status' => 'fail', 'label' => $label.' valid', 'message' => $this->safeMessage($message)];
        }

        if ($report->isReady()) {
            $checks[] = ['status' => 'pass', 'label' => 'KlikQRIS production readiness', 'message' => 'Configuration and operational dependencies are ready.'];
        }

        foreach ($checks as $check) {
            $line = strtoupper($check['status']).' '.$check['label'];
            if ($check['message'] !== '') {
                $line .= ' - '.$check['message'];
            }
            $this->line($line);
        }

        $this->line('SUMMARY environment=production readiness='.($report->isReady() ? 'ready' : 'not_ready'));

        return $report->isReady() ? self::SUCCESS : self::FAILURE;
    }

    private function safeMessage(string $message): string
    {
        return preg_replace('/[^A-Za-z0-9 .:_=\-\/]/', '', $message) ?: 'Invalid value.';
    }
}
