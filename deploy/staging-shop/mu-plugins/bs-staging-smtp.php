<?php

/**
 * Plugin Name: Staging: Mailversand über Mailpit
 * Description: Nur für den Staging-Shop (deploy/staging-shop): wp_mail versendet per SMTP an Mailpit (BS_STAGING_SMTP_HOST/-PORT aus wp-config.php).
 */
if (! defined('ABSPATH') || ! defined('BS_STAGING_SMTP_HOST')) {
    return;
}

add_action('phpmailer_init', static function ($mailer): void {
    $mailer->isSMTP();
    $mailer->Host = BS_STAGING_SMTP_HOST;
    $mailer->Port = defined('BS_STAGING_SMTP_PORT') ? (int) BS_STAGING_SMTP_PORT : 1025;
    $mailer->SMTPAuth = false;
    $mailer->SMTPAutoTLS = false;
    $mailer->SMTPSecure = '';
});

// Standard-Absender wäre wordpress@127.0.0.1 -- für PHPMailer keine gültige Adresse.
add_filter('wp_mail_from', static function ($from) {
    return defined('BS_STAGING_MAIL_FROM') && is_email(BS_STAGING_MAIL_FROM) ? BS_STAGING_MAIL_FROM : $from;
});

add_action('wp_mail_failed', static function ($error): void {
    error_log('Staging-Mail fehlgeschlagen: '.$error->get_error_message());
});
