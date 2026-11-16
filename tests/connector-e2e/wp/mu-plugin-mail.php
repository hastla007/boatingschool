<?php

/*
| Must-Use-Plugin nur für den Connector-E2E-Test: wp_mail versendet nichts,
| sondern hängt jede Mail an BS_E2E_MAIL_LOG an (aus wp-config.php; daraus
| liest run.sh den Bestätigungslink). Es gibt im Test keinen Mailserver.
*/

add_filter('pre_wp_mail', static function ($null, $atts) {
    $to = is_array($atts['to']) ? implode(', ', $atts['to']) : (string) $atts['to'];
    $message = is_string($atts['message']) ? $atts['message'] : print_r($atts['message'], true);
    $file = defined('BS_E2E_MAIL_LOG') ? BS_E2E_MAIL_LOG : WP_CONTENT_DIR.'/bs-e2e-mail.log';
    file_put_contents($file, sprintf("===== %s\nTo: %s\nSubject: %s\n\n%s\n\n", gmdate('Y-m-d H:i:s'), $to, $atts['subject'], $message), FILE_APPEND);

    return true;
}, 10, 2);
