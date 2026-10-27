<?php

namespace App\Console\Commands;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\ScheduleRunCommand;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Context\Repository as Context;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class RunBoundedSchedule extends ScheduleRunCommand
{
    protected $signature = 'ops:schedule-run {--whisper : Suppress the message when no tasks are due}';

    protected $description = 'Run due tasks with at most two children and priority slots for expiry.';

    /** @var list<Event> */
    private array $pending = [];

    /** @var list<Event> */
    private array $priority = [];

    private bool $failed = false;

    private bool $interrupted = false;

    public function handle(Schedule $schedule, Dispatcher $dispatcher, Cache $cache, ExceptionHandler $handler): int
    {
        $lock = fopen(storage_path('framework/scheduler-cycle.lock'), 'c');
        if ($lock === false) {
            $this->error('Cannot open the scheduler cycle lock.');

            return self::FAILURE;
        }

        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $this->error('Another scheduler cycle is still running.');

            return self::FAILURE;
        }

        $this->pending = [];
        $this->priority = [];
        $this->failed = false;
        $this->interrupted = false;
        $this->eventsRan = false;
        $this->startedAt = now();

        try {
            parent::handle($schedule, $dispatcher, $cache, $handler);
            $this->pending = array_merge($this->priority, $this->pending);
            $this->priority = [];
            $this->drain();

            return $this->failed || $this->interrupted ? self::FAILURE : self::SUCCESS;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            $this->pending = [];
            $this->priority = [];
        }
    }

    public function getSubscribedSignals(): array
    {
        return extension_loaded('pcntl') ? [SIGTERM, SIGINT, SIGQUIT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->interrupted = true;

        return false;
    }

    protected function runEvent($event)
    {
        if ($this->interrupted) {
            return;
        }
        if ($event->runInBackground) {
            $this->pending[] = $event;

            return;
        }

        if ($event instanceof CallbackEvent) {
            $releaseOnSignals = $event->releaseOnTerminationSignals;
            $event->releaseOnTerminationSignals = false;
            try {
                $event->exitCode = null;
                parent::runEvent($event);
                if (! $event->skippedBecauseOverlapping && $event->exitCode !== 0) {
                    $this->failed = true;
                }
            } finally {
                $event->releaseOnTerminationSignals = $releaseOnSignals;
            }

            return;
        }

        $this->priority[] = $event;
    }

    private function drain(): void
    {
        $next = 0;
        $active = [];
        try {
            while (($next < count($this->pending) || $active !== []) && ! $this->interrupted) {
                while (count($active) < 2 && $next < count($this->pending) && ! $this->interrupted) {
                    $event = $this->pending[$next++];
                    $process = $this->startProcess($event);
                    if ($process !== null) {
                        $active[] = [$event, $process, microtime(true)];
                    }
                }
                foreach ($active as $index => [$event, $process, $started]) {
                    if ($process->isRunning()) {
                        continue;
                    }
                    unset($active[$index]);
                    $this->finishTaskProcess($event, $process, $started);
                }
                if ($active !== []) {
                    usleep(20_000);
                }
            }
        } finally {
            // Stop every child before releasing any overlap protection.
            foreach ($active as [$event, $process]) {
                $process->stop(2);
            }
            foreach ($active as [$event, $process]) {
                if ($event->withoutOverlapping && ! $process->isRunning()) {
                    $event->mutex->forget($event);
                }
            }
        }
    }

    private function startProcess(Event $event): ?Process
    {
        if ($event->shouldSkipDueToOverlapping()) {
            $this->dispatcher->dispatch(new ScheduledTaskSkipped($event));

            return null;
        }

        try {
            $background = $event->runInBackground;
            $this->dispatcher->dispatch(new ScheduledTaskStarting($event));
            $event->callBeforeCallbacks($this->laravel);
            if ($this->interrupted) {
                if ($event->withoutOverlapping) {
                    $event->mutex->forget($event);
                }

                return null;
            }
            $event->runInBackground = false;
            try {
                $command = $event->buildCommand();
            } finally {
                $event->runInBackground = $background;
            }
            $process = Process::fromShellCommandline('exec '.$command, base_path(), [
                '__LARAVEL_CONTEXT' => json_encode($this->laravel->make(Context::class)->dehydrate(), JSON_THROW_ON_ERROR),
            ], null, null);
            $process->disableOutput();
            $process->start();

            return $process;
        } catch (Throwable $error) {
            if ($event->withoutOverlapping) {
                $event->mutex->forget($event);
            }
            $this->reportTaskFailure($event, $error);

            return null;
        }
    }

    private function finishTaskProcess(Event $event, Process $process, float $started): void
    {
        $code = $process->getExitCode() ?? 1;
        try {
            $event->finish($this->laravel, $code);
            $this->dispatcher->dispatch($event->runInBackground
                ? new ScheduledBackgroundTaskFinished($event)
                : new ScheduledTaskFinished($event, round(microtime(true) - $started, 2)));
            $this->line(sprintf('Scheduled task completed: %s exit=%d', $event->command, $code));
            if ($code !== 0) {
                throw new RuntimeException('Scheduled task failed with exit code '.$code.'.');
            }
        } catch (Throwable $error) {
            $this->reportTaskFailure($event, $error);
        }
    }

    private function reportTaskFailure(Event $event, Throwable $error): void
    {
        $this->failed = true;
        $this->dispatcher->dispatch(new ScheduledTaskFailed($event, $error));
        $this->handler->report($error);
    }
}
