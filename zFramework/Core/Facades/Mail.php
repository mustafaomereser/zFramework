<?php

namespace zFramework\Core\Facades;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

class Mail
{
    static $mail;

    static $toMail  = [];
    static $cc      = [];
    static $bcc     = [];
    static $sending = false;

    /**
     * Whether set() has run since the last state flush.
     *
     * init() is called once by the autoloader. In a long-running worker that is
     * the only time it would ever run, so after flushRequestState() the mailer
     * would be left unconfigured - $sending false, $mail null - and every mail
     * from the second request on would fail. Both entry points re-init on this.
     */
    private static bool $booted = false;

    private static $security = [
        'tls' => PHPMailer::ENCRYPTION_STARTTLS,
        'ssl' => PHPMailer::ENCRYPTION_SMTPS
    ];

    /**
     * Drop recipients and the PHPMailer instance.
     *
     * A recipient list surviving into the next request means one user's mail is
     * addressed to another - the kind of leak nobody notices until it is in
     * someone else's inbox.
     *
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::$mail    = null;
        self::$toMail  = [];
        self::$cc      = [];
        self::$bcc     = [];
        self::$sending = false;
        self::$booted  = false;
    }

    /**
     * Configure the mailer if it is not configured yet.
     *
     * @return void
     */
    private static function boot(): void
    {
        if (!self::$booted) self::init();
    }
    /**
     * Initalize settings.
     */
    public static function init()
    {
        self::set(Config::get('mail'));
    }

    /**
     * Set mailer settings
     * @param $mailConfig
     */
    public static function set(array $mailConfig)
    {
        self::$booted  = true;
        self::$sending = $mailConfig['sending'] ?? false;
        if (!$mailConfig['sending']) return false;

        self::$mail = new PHPMailer;
        self::$mail->isSMTP();

        if (@$mailConfig['debug'] == true) self::$mail->SMTPDebug = SMTP::DEBUG_SERVER;

        self::$mail->Host = $mailConfig['mail'];
        self::$mail->Port = $mailConfig['port'];
        if (!empty($mailConfig['security'])) self::$mail->SMTPSecure = self::$security[$mailConfig['security']];

        self::$mail->SMTPAuth = ($mailConfig['SMTPAuth'] ?? false);
        if (@$mailConfig['SMTPAuth'] === true) {
            self::$mail->Username = @$mailConfig['username'];
            self::$mail->Password = @$mailConfig['password'];
        }

        self::$mail->SetLanguage("tr", "phpmailer/language");
        self::$mail->CharSet  = "utf-8";
        self::$mail->Encoding = "base64";

        self::$mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true
            ]
        ];

        if (isset($mailConfig['from'])) self::$mail->setFrom($mailConfig['from'][1], $mailConfig['from'][0]);
        if (isset($mailConfig['reply'])) self::$mail->addReplyTo($mailConfig['reply'][1], $mailConfig['reply'][0]);
    }

    /**
     * add mail to list
     * @param string $toMail
     * @return self
     */
    public static function to(string $toMail): self
    {
        if (!filter_var($toMail, FILTER_VALIDATE_EMAIL)) throw new \Exception(_l('errors.mail.not-validate-mail'));
        self::$toMail[] = $toMail;
        return new self();
    }

    /**
     * @return self
     */
    public static function clearTo(): self
    {
        self::$toMail = [];
        return new self();
    }

    /**
     * add cc to list
     * @param string $cc
     * @return self
     */
    public static function cc(string $cc): self
    {
        if (!filter_var($cc, FILTER_VALIDATE_EMAIL)) throw new \Exception(_l('errors.mail.not-validate-mail'));
        self::$cc[] = $cc;
        return new self();
    }

    /**
     * @return self
     */
    public static function clearCc(): self
    {
        self::$cc = [];
        return new self();
    }

    /**
     * add bcc to list
     * @param string $bcc
     * @return self
     */
    public static function bcc(string $bcc): self
    {
        if (!filter_var($bcc, FILTER_VALIDATE_EMAIL)) throw new \Exception(_l('errors.mail.not-validate-mail'));
        self::$bcc[] = $bcc;
        return new self();
    }

    /**
     * @return self
     */
    public static function clearBcc(): self
    {
        self::$bcc = [];
        return new self();
    }

    /**
     * Send Mail
     * @param array $data
     * @return bool
     */
    /**
     * Send the mail, or hand it to the queue.
     *
     * An SMTP handshake plus delivery is 100-1000ms and it happens inside the
     * request, holding a PHP worker the whole time. With config mail.queue on,
     * the recipients and payload go to the queue instead and a worker sends it -
     * the request returns immediately.
     *
     * Falls back to sending inline whenever the queue cannot take it (no Redis),
     * so behaviour is correct either way.
     *
     * @param array $data
     * @return bool
     */
    public static function send(array $data): bool
    {
        self::boot();

        if (!self::$sending) throw new \Exception(_l('errors.mail.sending-is-false'));
        if (!count(self::$toMail)) throw new \Exception(_l('errors.mail.must-set-a-mail'));

        if (Config::get('mail.queue') && Redis::available('queue')) {
            $queued = Queue::push([\zFramework\Core\Jobs\SendMail::class, 'handle'], [
                'to'   => self::$toMail,
                'cc'   => self::$cc,
                'bcc'  => self::$bcc,
                'data' => $data,
            ]);

            self::clearTo();
            self::clearCc();
            self::clearBcc();

            if ($queued) return true;
        }

        return self::sendNow($data);
    }

    /**
     * Send immediately, bypassing the queue. This is what the queue worker runs.
     *
     * @param array $data
     * @return bool
     */
    public static function sendNow(array $data): bool
    {
        self::boot();

        if (!self::$sending) throw new \Exception(_l('errors.mail.sending-is-false'));
        if (!count(self::$toMail)) throw new \Exception(_l('errors.mail.must-set-a-mail'));

        self::$mail->Subject = Config::get('mail.subject') . (@$data['subject']);
        self::$mail->msgHTML(@$data['message']);
        self::$mail->AltBody = @$data['altbody'];

        foreach (self::$toMail as $mail) self::$mail->addAddress($mail);
        foreach (self::$cc as $mail) self::$mail->AddCC($mail);
        foreach (self::$bcc as $mail) self::$mail->AddBCC($mail);
        foreach ($data['attachements'] ?? [] as $attach) self::$mail->addAttachment($attach);

        self::$toMail = [];
        $status = self::$mail->send();

        self::$mail->clearAllRecipients();
        self::$mail->clearReplyTos();
        self::$mail->clearAttachments();

        self::clearCc();
        self::clearBcc();
        self::clearTo();

        return $status;
    }
}
