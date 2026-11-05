<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Einträge einer Liste per „nach oben/unten“ umsortieren (sort_order lückenlos neu vergeben). */
class SortOrder
{
    /** @param  Collection<int, Model>  $items  in aktueller Reihenfolge */
    public static function move(Collection $items, Model $item, string $direction): void
    {
        $items = $items->values();
        $index = $items->search(fn (Model $m) => $m->getKey() === $item->getKey());
        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if ($index === false || $target < 0 || $target >= $items->count()) {
            return;
        }

        $ordered = $items->all();
        [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];

        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $position => $model) {
                if ((int) $model->sort_order !== $position + 1) {
                    $model->update(['sort_order' => $position + 1]);
                }
            }
        });
    }
}
