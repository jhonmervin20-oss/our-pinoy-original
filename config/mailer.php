<?php
/**
 * config/mailer.php
 *
 * Requires: composer require phpmailer/phpmailer
 * Reads SMTP settings from .env (see config/env.php).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class Mailer
{
    /**
     * SMTP transport + sender, applied identically to every outgoing message.
     * This was copied verbatim in each send method; a third copy for the
     * reservation e-ticket would have made it three places to keep in step.
     */
    private static function configure(PHPMailer $mail, string $toEmail, string $toName): void
    {
        $mail->isSMTP();
        $mail->Host       = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('MAIL_USERNAME') ?: '';
        $mail->Password   = getenv('MAIL_PASSWORD') ?: '';
        $mail->SMTPSecure = getenv('MAIL_ENCRYPTION') ?: PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)(getenv('MAIL_PORT') ?: 587);
        $mail->CharSet    = 'UTF-8';

        // The peso sign and the e-ticket's box-drawing characters are outside
        // 7-bit ASCII; without this they arrive as mojibake in some clients.
        $mail->Encoding = PHPMailer::ENCODING_BASE64;

        $fromAddress = getenv('MAIL_FROM_ADDRESS') ?: $mail->Username;
        $fromName    = getenv('MAIL_FROM_NAME') ?: (getenv('APP_NAME') ?: 'OPO!');
        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
    }

    /**
     * Send a plain HTML email.
     *
     * @return bool True on success, false on failure (check error_log for details).
     */
    public static function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        $mail = new PHPMailer(true);

        try {
            self::configure($mail, $toEmail, $toName);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            error_log('Mailer error: ' . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Send an HTML email with a single binary attachment (e.g. a PDF
     * generated in-memory via Dompdf) -- separate from send() so every
     * existing caller of the plain version is untouched.
     */
    public static function sendWithAttachment(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $attachmentContent,
        string $attachmentName,
        string $attachmentMime = 'application/pdf'
    ): bool {
        $mail = new PHPMailer(true);

        try {
            self::configure($mail, $toEmail, $toName);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);

            $mail->addStringAttachment($attachmentContent, $attachmentName, 'base64', $attachmentMime);

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            error_log('Mailer error: ' . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Send an HTML email carrying inline (embedded) images.
     *
     * Inline images are the only reliable way to put a QR code in an email.
     * A <canvas> cannot be used -- mail clients run no JavaScript -- and a
     * remote <img src="https://..."> is blocked by default in Gmail, Outlook
     * and Apple Mail until the reader clicks "display images", which would
     * leave a guest at the door with an empty box where their ticket is.
     * An embedded image ships inside the message and renders immediately.
     *
     * $inlineImages maps a CID to ['content' => raw bytes, 'name' => filename,
     * 'mime' => type]; reference one from the HTML as <img src="cid:thatKey">.
     *
     * $altBody is explicit here rather than strip_tags()'d: running a table
     * layout through strip_tags leaves a column of loose words, so a message
     * this structured needs a plain-text version written for the purpose.
     */
    public static function sendRich(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        array $inlineImages = [],
        ?string $altBody = null,
        array $attachments = []
    ): bool {
        $mail = new PHPMailer(true);

        try {
            self::configure($mail, $toEmail, $toName);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $altBody ?? strip_tags($htmlBody);

            foreach ($inlineImages as $cid => $img) {
                $mail->addStringEmbeddedImage(
                    $img['content'],
                    (string)$cid,
                    $img['name'] ?? ((string)$cid . '.png'),
                    'base64',
                    $img['mime'] ?? 'image/png'
                );
            }
            foreach ($attachments as $att) {
                $mail->addStringAttachment(
                    $att['content'],
                    $att['name'],
                    'base64',
                    $att['mime'] ?? 'application/octet-stream'
                );
            }

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            error_log('Mailer error: ' . $mail->ErrorInfo);
            return false;
        }
    }
}
