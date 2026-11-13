<?php

/**
 * Erzeugt languages/bs-connector.pot aus den Übersetzungsaufrufen des Plugins
 * (__, _e, _n, _x, esc_html__, esc_html_e, esc_attr__, esc_attr_e mit der
 * Text-Domain „bs-connector“) und den Kopfzeilen der Plugin-Datei.
 *
 *   php bin/make-pot.php            (im Plugin-Ordner; schreibt die .pot)
 *
 * Nur für die Kommandozeile. Ohne WordPress-Abhängigkeit (ein Test der App
 * prüft, dass die .pot aktuell ist).
 */
if (PHP_SAPI !== 'cli') {
    exit;
}

final class BS_Pot_Extractor
{
    const DOMAIN = 'bs-connector';

    /** Funktion => Position der Argumente: [singular, plural|null, context|null, domain]. */
    const FUNCTIONS = [
        '__' => [0, null, null, 1],
        '_e' => [0, null, null, 1],
        'esc_html__' => [0, null, null, 1],
        'esc_html_e' => [0, null, null, 1],
        'esc_attr__' => [0, null, null, 1],
        'esc_attr_e' => [0, null, null, 1],
        '_x' => [0, null, 1, 2],
        '_n' => [0, 1, null, 3],
    ];

    /**
     * @return array<string, array{msgid: string, plural: ?string, context: ?string, refs: list<string>, comments: list<string>}>
     */
    public static function extract(string $plugin_dir): array
    {
        $entries = [];
        $plugin_dir = rtrim($plugin_dir, '/');
        $files = array_merge(glob($plugin_dir.'/*.php') ?: [], glob($plugin_dir.'/includes/*.php') ?: []);
        sort($files);
        foreach ($files as $file) {
            $relative = substr($file, strlen($plugin_dir) + 1);
            foreach (self::extract_file((string) file_get_contents($file)) as $found) {
                $key = ($found['context'] ?? '')."\4".$found['msgid'];
                if (! isset($entries[$key])) {
                    $entries[$key] = ['msgid' => $found['msgid'], 'plural' => $found['plural'], 'context' => $found['context'], 'refs' => [], 'comments' => []];
                }
                $entries[$key]['refs'][] = $relative.':'.$found['line'];
                if ($found['comment'] !== null && ! in_array($found['comment'], $entries[$key]['comments'], true)) {
                    $entries[$key]['comments'][] = $found['comment'];
                }
            }
        }

        return $entries;
    }

    /** @return list<array{msgid: string, plural: ?string, context: ?string, line: int, comment: ?string}> */
    public static function extract_file(string $code): array
    {
        $tokens = token_get_all($code);
        $found = [];
        $comment = null;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_COMMENT && preg_match('/translators:\s*(.+?)\s*(\*\/)?$/s', $token[1], $m)) {
                $comment = 'translators: '.trim(preg_replace('/\s+/', ' ', $m[1]));

                continue;
            }
            if ($token === ';') {
                // Ein Übersetzerhinweis gilt nur für die Anweisung, in der er steht.
                $comment = null;

                continue;
            }
            if (! is_array($token) || $token[0] !== T_STRING || ! isset(self::FUNCTIONS[$token[1]])) {
                continue;
            }
            // Methodenaufrufe (->__) und Definitionen überspringen.
            $prev = self::previous($tokens, $i);
            if ($prev !== null && (in_array($prev, ['->', '::'], true) || (is_array($prev) && in_array($prev[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)))) {
                continue;
            }
            $args = self::arguments($tokens, $i + 1);
            if ($args === null) {
                continue;
            }
            [$single, $plural, $context, $domain] = self::FUNCTIONS[$token[1]];
            if (($args[$domain] ?? null) !== self::DOMAIN || ! is_string($args[$single] ?? null)) {
                continue;
            }
            $found[] = [
                'msgid' => $args[$single],
                'plural' => $plural === null ? null : (is_string($args[$plural] ?? null) ? $args[$plural] : null),
                'context' => $context === null ? null : (is_string($args[$context] ?? null) ? $args[$context] : null),
                'line' => $token[2],
                'comment' => $comment,
            ];
            $comment = null;
        }

        return $found;
    }

    private static function previous(array $tokens, int $i)
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }

            return $tokens[$j];
        }

        return null;
    }

    /**
     * Argumente eines Aufrufs: Zeichenketten-Literale als Wert, alles andere
     * als false. null, wenn keine Klammer folgt.
     *
     * @return list<string|false>|null
     */
    private static function arguments(array $tokens, int $i): ?array
    {
        $count = count($tokens);
        while ($i < $count && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT], true)) {
            $i++;
        }
        if (($tokens[$i] ?? null) !== '(') {
            return null;
        }
        $args = [];
        $current = null;
        $simple = true;
        $depth = 0;
        for ($i++; $i < $count; $i++) {
            $token = $tokens[$i];
            if (in_array($token, ['(', '[', '{'], true) || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                $simple = false;

                continue;
            }
            if (in_array($token, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    $args[] = $simple && $current !== null ? $current : false;

                    return $args;
                }
                $depth--;

                continue;
            }
            if ($depth > 0) {
                continue;
            }
            if ($token === ',') {
                $args[] = $simple && $current !== null ? $current : false;
                $current = null;
                $simple = true;

                continue;
            }
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT], true)) {
                continue;
            }
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && $current === null) {
                $current = self::unquote($token[1]);

                continue;
            }
            $simple = false;
        }

        return null;
    }

    /** PHP-Zeichenketten-Literal in seinen Wert umwandeln. */
    public static function unquote(string $literal): string
    {
        $quote = $literal[0];
        $body = substr($literal, 1, -1);
        if ($quote === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
        }

        return stripcslashes(str_replace('\\$', '$', $body));
    }

    /** Zeichenkette für eine PO-Datei. */
    public static function po_string(string $value): string
    {
        $escaped = addcslashes($value, "\\\"\t");
        if (strpos($value, "\n") === false) {
            return '"'.$escaped.'"';
        }
        $lines = explode("\n", $escaped);
        $out = '""';
        foreach ($lines as $index => $line) {
            $last = $index === count($lines) - 1;
            if ($last && $line === '') {
                break;
            }
            $out .= "\n\"".$line.($last ? '' : '\\n').'"';
        }

        return $out;
    }

    /** Kopfzeilen der Plugin-Datei, die WordPress übersetzt. */
    public static function headers(string $main_file): array
    {
        $code = (string) file_get_contents($main_file);
        $entries = [];
        foreach (['Plugin Name', 'Description'] as $header) {
            if (preg_match('/^\s*\*\s*'.preg_quote($header, '/').':\s*(.+)$/m', $code, $m)) {
                $entries[] = ['msgid' => trim($m[1]), 'comment' => $header.' of the plugin'];
            }
        }

        return $entries;
    }

    public static function render(string $plugin_dir): string
    {
        $version = '';
        $main = rtrim($plugin_dir, '/').'/boatingschool-connector.php';
        if (preg_match('/^\s*\*\s*Version:\s*(.+)$/m', (string) file_get_contents($main), $m)) {
            $version = trim($m[1]);
        }
        $out = "# Übersetzungsvorlage für den Bootsschul-Plattform Connector.\n"
            ."# Erzeugt mit bin/make-pot.php -- nicht von Hand bearbeiten.\n"
            ."msgid \"\"\n"
            ."msgstr \"\"\n"
            .'"Project-Id-Version: Bootsschul-Plattform Connector '.$version.'\\n"'."\n"
            ."\"MIME-Version: 1.0\\n\"\n"
            ."\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
            ."\"Content-Transfer-Encoding: 8bit\\n\"\n"
            ."\"Language: de_DE\\n\"\n"
            ."\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n"
            ."\"X-Domain: bs-connector\\n\"\n"
            ."\"X-Generator: bin/make-pot.php\\n\"\n";

        foreach (self::headers($main) as $header) {
            $out .= "\n#. ".$header['comment']."\n#: boatingschool-connector.php\nmsgid ".self::po_string($header['msgid'])."\nmsgstr \"\"\n";
        }
        foreach (self::extract($plugin_dir) as $entry) {
            $out .= "\n";
            foreach ($entry['comments'] as $comment) {
                $out .= '#. '.$comment."\n";
            }
            $out .= '#: '.implode(' ', $entry['refs'])."\n";
            if (strpos($entry['msgid'], '%') !== false) {
                $out .= "#, php-format\n";
            }
            if ($entry['context'] !== null) {
                $out .= 'msgctxt '.self::po_string($entry['context'])."\n";
            }
            $out .= 'msgid '.self::po_string($entry['msgid'])."\n";
            if ($entry['plural'] !== null) {
                $out .= 'msgid_plural '.self::po_string($entry['plural'])."\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n";
            } else {
                $out .= "msgstr \"\"\n";
            }
        }

        return $out;
    }
}

if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $bs_plugin_dir = dirname(__DIR__);
    $bs_target = $bs_plugin_dir.'/languages/bs-connector.pot';
    file_put_contents($bs_target, BS_Pot_Extractor::render($bs_plugin_dir));
    fwrite(STDOUT, 'Geschrieben: languages/bs-connector.pot ('.count(BS_Pot_Extractor::extract($bs_plugin_dir))." Texte)\n");
}
