<?php

namespace App\Support;

/**
 * Wortweiser Vergleich zweier Texte für die Revisions-Vorschau im
 * Superadmin: liefert escaptes HTML mit <del> (entfernt) und <ins>
 * (hinzugefügt).
 */
class TextDiff
{
    /** Ab dieser Länge (Wörter je Seite) nur noch „alt ersetzt durch neu“. */
    private const MAX_TOKENS = 1500;

    public static function html(?string $old, ?string $new): string
    {
        $old = (string) $old;
        $new = (string) $new;
        if ($old === $new) {
            return e($new);
        }

        $a = self::tokens($old);
        $b = self::tokens($new);
        if (count($a) > self::MAX_TOKENS || count($b) > self::MAX_TOKENS) {
            return self::wrap('del', $old).self::wrap('ins', $new);
        }

        // Längste gemeinsame Teilfolge (dynamische Programmierung).
        $n = count($a);
        $m = count($b);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $out = '';
        $del = '';
        $ins = '';
        $flush = function () use (&$out, &$del, &$ins) {
            $out .= self::wrap('del', $del).self::wrap('ins', $ins);
            $del = $ins = '';
        };
        for ($i = 0, $j = 0; $i < $n || $j < $m;) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $flush();
                $out .= e($a[$i]);
                $i++;
                $j++;
            } elseif ($j < $m && ($i >= $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) {
                $ins .= $b[$j++];
            } else {
                $del .= $a[$i++];
            }
        }
        $flush();

        return $out;
    }

    /** @return list<string> Wörter und Zwischenräume/Satzzeichen als eigene Teile */
    private static function tokens(string $text): array
    {
        return preg_split('/(\s+|[.,;:!?()„“"«»\/-])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function wrap(string $tag, string $text): string
    {
        return $text === '' ? '' : "<{$tag}>".e($text)."</{$tag}>";
    }
}
