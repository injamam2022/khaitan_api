<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Mail transport for contact notifications and other outbound mail.
 * Prefer SMTP (Zoho) via .env — PHP mail() is unreliable on most hosts.
 *
 * Set in khaitan_api/.env:
 *   email.protocol, email.SMTPHost, email.SMTPUser, email.SMTPPass,
 *   email.SMTPPort, email.SMTPCrypto
 */
class Email extends BaseConfig
{
    public string $fromEmail  = '';
    public string $fromName   = '';
    public string $recipients = '';

    /**
     * The "user agent"
     */
    public string $userAgent = 'CodeIgniter';

    /**
     * The mail sending protocol: mail, sendmail, smtp
     */
    public string $protocol = 'mail';

    /**
     * The server path to Sendmail.
     */
    public string $mailPath = '/usr/sbin/sendmail';

    /**
     * SMTP Server Hostname
     */
    public string $SMTPHost = '';

    /**
     * SMTP Username
     */
    public string $SMTPUser = '';

    /**
     * SMTP Password
     */
    public string $SMTPPass = '';

    /**
     * SMTP Port
     */
    public int $SMTPPort = 587;

    /**
     * SMTP Timeout (in seconds)
     */
    public int $SMTPTimeout = 15;

    /**
     * Enable persistent SMTP connections
     */
    public bool $SMTPKeepAlive = false;

    /**
     * SMTP Encryption: '', 'tls' (port 587) or 'ssl' (port 465).
     *
     * @var string
     */
    public string $SMTPCrypto = 'tls';

    /**
     * Enable word-wrap
     */
    public bool $wordWrap = true;

    /**
     * Character count to wrap at
     */
    public int $wrapChars = 76;

    /**
     * Type of mail, either 'text' or 'html'
     */
    public string $mailType = 'text';

    /**
     * Character set (utf-8, iso-8859-1, etc.)
     */
    public string $charset = 'UTF-8';

    /**
     * Whether to validate the email address
     */
    public bool $validate = false;

    /**
     * Email Priority. 1 = highest. 5 = lowest. 3 = normal
     */
    public int $priority = 3;

    /**
     * Newline character. (Use “\r\n” to comply with RFC 822)
     */
    public string $CRLF = "\r\n";

    /**
     * Newline character. (Use “\r\n” to comply with RFC 822)
     */
    public string $newline = "\r\n";

    /**
     * Enable BCC Batch Mode.
     */
    public bool $BCCBatchMode = false;

    /**
     * Number of emails in each BCC batch
     */
    public int $BCCBatchSize = 200;

    /**
     * Enable notify message from server
     */
    public bool $DSN = false;

    public function __construct()
    {
        parent::__construct();

        $protocol = $this->readEnv(['email.protocol', 'EMAIL_PROTOCOL']);
        if ($protocol !== '') {
            $this->protocol = strtolower($protocol);
        }

        $host = $this->readEnv(['email.SMTPHost', 'email.smtpHost', 'EMAIL_SMTP_HOST']);
        if ($host !== '') {
            $this->SMTPHost = $host;
        }

        $user = $this->readEnv(['email.SMTPUser', 'email.smtpUser', 'EMAIL_SMTP_USER']);
        if ($user !== '') {
            $this->SMTPUser = $user;
        }

        $pass = $this->readEnv(['email.SMTPPass', 'email.smtpPass', 'EMAIL_SMTP_PASS']);
        if ($pass !== '') {
            $this->SMTPPass = $pass;
        }

        $port = $this->readEnv(['email.SMTPPort', 'email.smtpPort', 'EMAIL_SMTP_PORT']);
        if ($port !== '' && ctype_digit($port)) {
            $this->SMTPPort = (int) $port;
        }

        $crypto = $this->readEnv(['email.SMTPCrypto', 'email.smtpCrypto', 'EMAIL_SMTP_CRYPTO']);
        if ($crypto !== '') {
            $this->SMTPCrypto = strtolower($crypto);
        }

        // If SMTP host + user are set, force smtp even if protocol was left blank.
        if ($this->SMTPHost !== '' && $this->SMTPUser !== '' && $this->protocol === 'mail') {
            $this->protocol = 'smtp';
        }
    }

    /**
     * @param list<string> $keys
     */
    private function readEnv(array $keys): string
    {
        foreach ($keys as $key) {
            $val = env($key, '');
            if (is_string($val) && trim($val) !== '') {
                return trim($val);
            }
            $fromGetenv = getenv($key);
            if (is_string($fromGetenv) && trim($fromGetenv) !== '') {
                return trim($fromGetenv);
            }
            if (isset($_ENV[$key]) && is_string($_ENV[$key]) && trim($_ENV[$key]) !== '') {
                return trim($_ENV[$key]);
            }
        }

        return '';
    }
}
