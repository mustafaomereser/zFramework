<?php

namespace zFramework\Core\Facades;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

class Mail
{
    static $mail;

    static $toMail = [];
    static $cc     = [];
    static $bcc    = [];

    private static $security = [
        'tls' => PHPMailer::ENCRYPTION_STARTTLS,
        'ssl' => PHPMailer::ENCRYPTION_SMTPS
    ];
    /**
     * Initalize settings.
     */
    public static function init()
    {
        $mailConfig = Config::get('mail');
        if (!$mailConfig['sending']) throw new \Exception(_l('errors.mail.sending-is-false'));

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
        self::$toMail[] = [];
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
        self::$cc[] = [];
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
        self::$bcc[] = [];
        return new self();
    }


    /**
     * Send Mail
     * @param array $data
     * @return bool
     */
    public static function send(array $data): bool
    {
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
