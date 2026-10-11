<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class SmokeCustomerAuthMail extends Command
{
    protected $signature = 'customer-auth:smoke-mail
        {--confirm-real-send : Confirm approval to send to a real non-production inbox}
        {--confirm-tracking-disabled : Confirm Resend domain open/click tracking is disabled}
        {--inbox-stdin : Read the approved inbox from standard input instead of a hidden prompt}';

    protected $description = 'Send a token-free Resend SMTP probe in a non-production environment.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing', 'staging'])) {
            $this->error('Mail smoke is restricted to local, testing or staging environments.');

            return self::FAILURE;
        }
        if (! $this->option('confirm-real-send') || ! $this->option('confirm-tracking-disabled')) {
            $this->error('Approved real inbox and disabled domain open/click tracking must be confirmed.');

            return self::FAILURE;
        }
        $smtp = config('mail.mailers.smtp', []);
        if (config('mail.default') !== 'smtp'
            || ($smtp['transport'] ?? null) !== 'smtp'
            || ($smtp['scheme'] ?? null) !== 'smtp'
            || filled($smtp['url'] ?? null)
            || ($smtp['host'] ?? null) !== 'smtp.resend.com'
            || (int) ($smtp['port'] ?? 0) !== 587
            || ($smtp['username'] ?? null) !== 'resend'
            || ! is_string($smtp['password'] ?? null) || $smtp['password'] === ''
            || ($smtp['auto_tls'] ?? null) !== true
            || ($smtp['require_tls'] ?? null) !== true
            || config('mail.from.address') !== 'no-reply@notify.lhiestore.web.id') {
            $this->error('Resend SMTP configuration is incomplete or unsafe; inspect secret owners without printing values.');

            return self::FAILURE;
        }

        $inbox = $this->option('inbox-stdin')
            ? trim((string) fgets(STDIN, 320))
            : $this->secret('Approved non-production inbox (hidden)');
        if (! is_string($inbox) || strlen($inbox) > 254 || ! filter_var($inbox, FILTER_VALIDATE_EMAIL)) {
            $this->error('An approved valid inbox is required.');

            return self::FAILURE;
        }
        $correlation = (string) Str::uuid();
        try {
            $sent = Mail::mailer('smtp')->raw(
                "Customer authentication SMTP deployment probe.\nCorrelation: {$correlation}\nThis message contains no authentication grant.\n",
                function (Message $message) use ($inbox, $correlation): void {
                    $message->to($inbox)->subject('Customer auth SMTP probe '.$correlation);
                },
            );
            if (! $sent instanceof SentMessage) {
                $this->error('Probe was not accepted by SMTP. No inbox delivery has been verified.');

                return self::FAILURE;
            }
        } catch (Throwable) {
            $this->error('SMTP probe failed. No inbox delivery has been verified.');

            return self::FAILURE;
        }
        $this->info('SMTP accepted probe '.$correlation.'. Inbox delivery is unverified; confirm receipt and headers privately.');

        return self::SUCCESS;
    }
}
