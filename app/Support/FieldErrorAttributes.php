<?php

namespace App\Support;

use Illuminate\Support\ViewErrorBag;

/**
 * Barrierefreiheit (WCAG 1.3.1/3.3.1) für Felder ohne <x-text-input>
 * (select, textarea, Datei- und Auswahlfelder): @ariaInvalid('feld') bzw.
 *
 * @ariaInvalid('feld', 'element-id', 'hinweis-id') schreibt bei einem Fehler
 * aria-invalid="true" und aria-describedby="{id}-error" -- das Ziel setzt
 * <x-input-error for="{id}">. Ohne Fehler bleibt nur ein Hinweis verknüpft.
 * Gegenstück zur Prop :invalid von components/text-input.blade.php.
 */
final class FieldErrorAttributes
{
    public static function render(mixed $errors, string $field, ?string $id = null, ?string $describedBy = null): string
    {
        $invalid = $errors instanceof ViewErrorBag && $errors->has($field);
        $ids = array_filter([$describedBy, $invalid ? ($id ?? $field).'-error' : null]);
        $attributes = ($invalid ? ' aria-invalid="true"' : '').($ids !== [] ? ' aria-describedby="'.e(implode(' ', $ids)).'"' : '');

        return ltrim($attributes);
    }
}
