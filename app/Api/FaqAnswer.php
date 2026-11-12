<?php

namespace App\Api;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/** Sanitizes imported WordPress FAQ markup for mobile clients. */
final class FaqAnswer
{
    private const TAGS = [
        'a', 'b', 'blockquote', 'br', 'code', 'dd', 'div', 'dl', 'dt', 'em', 'h2', 'h3', 'h4',
        'hr', 'i', 'li', 'ol', 'p', 'pre', 's', 'span', 'strong', 'table', 'tbody', 'td', 'th',
        'thead', 'tr', 'u', 'ul',
    ];

    /** @return array{html:string,text:string,links:list<array{url:string,text:string}>} */
    public static function present(string $source): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<!doctype html><html><body><div id="faq-answer">'.$source.'</div></body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = (new DOMXPath($document))->query('//*[@id="faq-answer"]')?->item(0);
        if (! $root) {
            return ['html' => nl2br(e($source)), 'text' => trim(strip_tags($source)), 'links' => []];
        }

        $html = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $html .= self::clean($child);
        }

        $safeDocument = new DOMDocument('1.0', 'UTF-8');
        $safeDocument->loadHTML('<!doctype html><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        $safeXPath = new DOMXPath($safeDocument);
        $links = [];
        foreach ($safeXPath->query('//a[@href]') ?: [] as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }
            $url = trim($anchor->getAttribute('href'));
            if (preg_match('#^(https?:|mailto:|tel:)#i', $url) === 1) {
                $links[] = ['url' => $url, 'text' => trim($anchor->textContent) ?: $url];
            }
        }

        $text = str_replace(["\r\n", "\r", "\n"], ' ', $html);
        $text = (string) preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = (string) preg_replace('#<li\b[^>]*>#i', "\n• ", $text);
        $text = (string) preg_replace('#</(p|div|h[1-6]|blockquote|ul|ol|table|tr|pre)>#i', "\n\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_map(
            static fn (string $line): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $line)),
            explode("\n", $text)
        );
        $plain = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));

        return ['html' => $html, 'text' => $plain, 'links' => $links];
    }

    private static function clean(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars($node->nodeValue ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }
        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form'], true)) {
            return '';
        }
        $children = '';
        foreach (iterator_to_array($node->childNodes) as $child) {
            $children .= self::clean($child);
        }
        if (! in_array($tag, self::TAGS, true)) {
            return $children;
        }
        if (in_array($tag, ['br', 'hr'], true)) {
            return '<'.$tag.'>';
        }

        $attributes = '';
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            if (self::safeHref($href)) {
                $attributes .= ' href="'.e($href).'"';
                if (preg_match('#^https?://#i', $href) === 1) {
                    $attributes .= ' rel="noopener noreferrer"';
                }
            }
            $title = trim($node->getAttribute('title'));
            if ($title !== '') {
                $attributes .= ' title="'.e($title).'"';
            }
        }

        return '<'.$tag.$attributes.'>'.$children.'</'.$tag.'>';
    }

    private static function safeHref(string $href): bool
    {
        if ($href === '' || preg_match('/[\x00-\x20]/', $href)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        return $scheme === '' || in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
    }
}
