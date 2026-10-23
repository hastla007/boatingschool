<?php

namespace App\Services;

use App\Models\CourseDefinition;
use App\Models\Favorite;
use App\Models\FavoriteCollection;
use App\Models\FavoriteCollectionItem;
use App\Models\Tenant;
use App\Models\User;
use RuntimeException;

/**
 * Verwaltung der Favoriten-Collections eines Lerners innerhalb eines
 * Kurses: jeder Kurs hat genau eine, lazy angelegte, nicht löschbare
 * Default-Collection (Fallback für Favoriten ohne eigene Zuordnung), dazu
 * beliebig viele frei benannte Collections. Eine Frage kann gleichzeitig
 * in mehreren Collections liegen (siehe FavoriteCollectionItem); die
 * Zuordnung zu mindestens einer Collection erzeugt bei Bedarf automatisch
 * den zugehörigen Favorite-Datensatz, und das Entfernen aus allen
 * Collections räumt ihn wieder ab (siehe syncQuestion()).
 */
class FavoriteCollectionService
{
    public const DEFAULT_NAME = 'Favoriten';

    public function defaultFor(Tenant $tenant, User $user, CourseDefinition $course): FavoriteCollection
    {
        return FavoriteCollection::firstOrCreate([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'is_default' => true,
        ], [
            'name' => self::DEFAULT_NAME,
            'sort_order' => 0,
        ]);
    }

    public function create(Tenant $tenant, User $user, CourseDefinition $course, string $name): FavoriteCollection
    {
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException('Bitte einen Namen für die Collection angeben.');
        }

        if (FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)->where('name', $name)->exists()) {
            throw new RuntimeException('Eine Collection mit diesem Namen existiert bereits.');
        }

        $nextSortOrder = (int) FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)->max('sort_order') + 1;

        return FavoriteCollection::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'name' => $name,
            'is_default' => false,
            'sort_order' => $nextSortOrder,
        ]);
    }

    public function rename(FavoriteCollection $collection, string $name): void
    {
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException('Bitte einen Namen für die Collection angeben.');
        }

        $exists = FavoriteCollection::where('tenant_id', $collection->tenant_id)
            ->where('user_id', $collection->user_id)
            ->where('course_id', $collection->course_id)
            ->where('name', $name)
            ->where('id', '!=', $collection->id)
            ->exists();

        if ($exists) {
            throw new RuntimeException('Eine Collection mit diesem Namen existiert bereits.');
        }

        $collection->update(['name' => $name]);
    }

    public function delete(FavoriteCollection $collection): void
    {
        if ($collection->is_default) {
            throw new RuntimeException('Die Default-Collection kann nicht gelöscht werden.');
        }

        // Löscht nur die Zuordnung -- die zugrunde liegenden Favoriten
        // (favorite-Zeilen) bleiben bestehen, sind danach aber ggf. in
        // keiner Collection mehr einsortiert.
        $collection->delete();
    }

    /**
     * Setzt für eine Frage (in einem bestimmten Kontext) die vollständige
     * Menge der Collections dieses Kurses, denen sie angehören soll --
     * bereits bestehende, nicht mehr gewünschte Zuordnungen werden entfernt.
     * Ist die Menge nicht leer, wird bei Bedarf ein Favorite-Datensatz
     * angelegt (Einsortieren bedeutet Favorisieren); ist sie leer, wird ein
     * zuvor nur über Collections verwalteter Favorit wieder entfernt.
     *
     * @param  array<int, string>  $collectionIds
     */
    public function syncQuestion(Tenant $tenant, User $user, CourseDefinition $course, string $questionId, string $context, array $collectionIds): void
    {
        $ownCollectionIds = FavoriteCollection::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->pluck('id');

        $targetIds = array_values(array_intersect($collectionIds, $ownCollectionIds->all()));

        FavoriteCollectionItem::where('tenant_id', $tenant->id)
            ->where('question_id', $questionId)
            ->where('context', $context)
            ->whereIn('favorite_collection_id', $ownCollectionIds)
            ->delete();

        foreach ($targetIds as $collectionId) {
            FavoriteCollectionItem::create([
                'tenant_id' => $tenant->id,
                'favorite_collection_id' => $collectionId,
                'question_id' => $questionId,
                'context' => $context,
            ]);
        }

        if ($targetIds !== []) {
            Favorite::firstOrCreate([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'question_id' => $questionId,
                'context' => $context,
            ]);
        } else {
            Favorite::where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->where('question_id', $questionId)
                ->where('context', $context)
                ->delete();
        }
    }

    /**
     * Beim Entfernen eines Favoriten (Stern abgewählt) verliert er auch alle
     * Collection-Zuordnungen in diesem Kontext -- aber nur die EIGENEN des
     * Lerners. Bug-Review N3: fehlte hier bislang (anders als bei
     * syncQuestion() oben) die Einschränkung auf die eigenen Collections
     * ($ownCollectionIds) -- jeder Lerner konnte damit über das simple
     * Abwählen eines eigenen Favoriten versehentlich die
     * Collection-Zuordnung DERSELBEN Frage für JEDEN anderen Lerner
     * derselben Bootsschule mitlöschen.
     */
    public function detachFromAllCollections(Tenant $tenant, User $user, string $questionId, string $context): void
    {
        $ownCollectionIds = FavoriteCollection::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->pluck('id');

        FavoriteCollectionItem::where('tenant_id', $tenant->id)
            ->where('question_id', $questionId)
            ->where('context', $context)
            ->whereIn('favorite_collection_id', $ownCollectionIds)
            ->delete();
    }

    /** Neu favorisierte Fragen landen automatisch in der Default-Collection, wenn sie noch in keiner liegen. */
    public function ensureInDefault(Tenant $tenant, User $user, CourseDefinition $course, string $questionId, string $context): void
    {
        $alreadyAssigned = FavoriteCollectionItem::where('tenant_id', $tenant->id)
            ->where('question_id', $questionId)
            ->where('context', $context)
            ->whereIn('favorite_collection_id', FavoriteCollection::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)->pluck('id'))
            ->exists();

        if ($alreadyAssigned) {
            return;
        }

        $default = $this->defaultFor($tenant, $user, $course);

        FavoriteCollectionItem::firstOrCreate([
            'tenant_id' => $tenant->id,
            'favorite_collection_id' => $default->id,
            'question_id' => $questionId,
            'context' => $context,
        ]);
    }
}
