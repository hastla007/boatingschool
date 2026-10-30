<?php

namespace App\Notifications\Concerns;

/**
 * Bug-Review (Phishing-Link via Prüfungstermin-Kommentar in Admin-E-Mail
 * einschleusbar): MailMessage::line() rendert über Laravels Markdown-
 * Mailvorlage -- ein Lerner konnte in einem frei befüllbaren Feld echte
 * Markdown-Linksyntax ("[Text](https://...)") oder eine bloße URL
 * hinterlegen, die in der an die Bootsschule verschickten E-Mail als
 * klickbarer Link dargestellt wurde. Backslash-Escaping aller
 * CommonMark-Sonderzeichen neutralisiert das: der Wert erscheint weiterhin
 * wortgetreu, aber ausschließlich als Fließtext.
 *
 * Bug-Review (Markdown-Injection über Name/Lieferadresse): ursprünglich
 * privat in PraxisExamAppointmentResponded und nur auf response_comment
 * angewandt -- Name und Lieferadresse des Lerners sind aber ebenso frei
 * wählbar und landen in denselben Admin-Mails. Als Trait für jede
 * Notification, die nutzergelieferte Werte in eine Markdown-Zeile setzt.
 */
trait EscapesMarkdown
{
    protected static function escapeMarkdown(?string $text): string
    {
        return preg_replace('/([\\\\`*_{}\[\]()#+\-.!|>~])/', '\\\\$1', (string) $text);
    }
}
