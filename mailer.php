<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function send_app_email(string $recipient, string $subject, string $html, string $plain): bool {

    if (SMTP_PASS === '' || SMTP_PASS === 'YOUR_GMAIL_APP_PASSWORD') {
        error_log('NEXSUS SMTP is not configured.');
        return false;
    }

    foreach (['Exception.php', 'PHPMailer.php', 'SMTP.php'] as $file) {
        if (!is_file(__DIR__ . '/PHPMailer/src/' . $file)) {
            error_log('PHPMailer missing file: PHPMailer/src/' . $file);
            return false;
        }
    }

    require_once __DIR__ . '/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/src/SMTP.php';

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 15;

        $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
        $mail->addAddress($recipient);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $plain;

        $mail->send();
        return true;

    } catch (\Throwable $e) {
        error_log('NEXSUS email error: ' . $e->getMessage());
        return false;
    }
}


// Send a password reset link by email
function send_reset_email(string $email, string $link): bool {

    $mins = RESET_EXPIRY_MINUTES;

    $safeUrl = htmlspecialchars(
        $link,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    return send_app_email(
        $email,
        'Reset your NEXSUS password',
        '<p>Use this one-time link to reset your password within '
        . $mins . ' minutes:</p>'
        . '<p><a href="' . $safeUrl . '">Reset password</a></p>'
        . '<p>Ignore this email if you did not request it.</p>',
        "Reset your password within $mins minutes: $link\n"
        . "Ignore this email if you did not request it."
    );
}
