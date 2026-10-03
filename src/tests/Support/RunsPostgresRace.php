<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

trait RunsPostgresRace
{
    protected function race(array $callbacks): array
    {
        $directory = storage_path('framework/testing/supplier-race-'.bin2hex(random_bytes(8)));
        mkdir($directory, 0700, true);
        $children = [];
        DB::disconnect();
        try {
            foreach ($callbacks as $index => $callback) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('Unable to fork supplier worker.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        DB::reconnect();
                        DB::statement("SET lock_timeout TO '8s'");
                        DB::statement("SET statement_timeout TO '12s'");
                        $deadline = microtime(true) + 10;
                        while (! is_file($directory.'/release') && microtime(true) < $deadline) {
                            usleep(1000);
                        }
                        $result = ['ok' => true, 'value' => $callback()];
                    } catch (Throwable $error) {
                        $result = ['ok' => false, 'error' => $error::class];
                    }
                    file_put_contents($directory.'/'.$index.'.json', json_encode($result, JSON_THROW_ON_ERROR));
                    exit($result['ok'] ? 0 : 1);
                }
                $children[$index] = $pid;
            }
            touch($directory.'/release');
            $remaining = $children;
            $deadline = microtime(true) + 25;
            while ($remaining !== [] && microtime(true) < $deadline) {
                foreach ($remaining as $index => $pid) {
                    if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                        unset($remaining[$index]);
                    }
                }
                usleep(10000);
            }
            foreach ($remaining as $pid) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            $this->assertSame([], $remaining, 'Supplier concurrency workers exceeded the bounded deadline.');
            $results = [];
            foreach ($children as $index => $pid) {
                $result = json_decode(file_get_contents($directory.'/'.$index.'.json'), true, flags: JSON_THROW_ON_ERROR);
                $this->assertTrue($result['ok'], $result['error'] ?? 'Worker failed');
                $results[] = $result['value'];
            }

            return $results;
        } finally {
            DB::reconnect();
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
