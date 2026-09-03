<?php

namespace zFramework\Core\Jobs;

use zFramework\Core\Facades\Mail;

/**
 * Queue job that delivers a mail handed over by Mail::send().
 *
 * Run by `php terminal queue work`. Recipients travel in the payload rather than
 * on Mail's statics, since the worker is a different process entirely.
 */
class SendMail
{
    /**
     * @param array $payload to, cc, bcc, data
     * @return void
     */
    public function handle(array $payload): void
    {
        Mail::clearTo();
        Mail::clearCc();
        Mail::clearBcc();

        foreach ($payload['to']  ?? [] as $mail) Mail::to($mail);
        foreach ($payload['cc']  ?? [] as $mail) Mail::cc($mail);
        foreach ($payload['bcc'] ?? [] as $mail) Mail::bcc($mail);

        # PHPMailer is built without exceptions, so a refused connection or a
        # rejected recipient comes back as false rather than a throw. Swallowed,
        # the worker printed 'done', nothing was retried and nothing was reported -
        # a queued mail that failed delivery was simply gone. A throw is what the
        # worker retries and, after --tries, hands to the error handler.
        if (!Mail::sendNow($payload['data'] ?? []))
            throw new \RuntimeException('Mail was not delivered' . (Mail::$mail?->ErrorInfo ? ': ' . Mail::$mail->ErrorInfo : '') . ' (to ' . implode(', ', $payload['to'] ?? []) . ')');
    }
}
