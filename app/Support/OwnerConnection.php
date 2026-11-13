<?php

namespace App\Support;

/**
 * Führt einen Block auf der Owner-Verbindung "pgsql_admin" aus (umgeht RLS)
 * und stellt die vorherige Standardverbindung danach in jedem Fall wieder
 * her -- auch bei Exceptions und unter langlebigen Workern (Octane), siehe
 * Bug-Review M6 in UseAdminDatabaseConnection.
 *
 * Nur für die in docs/architektur/owner-verbindung.md beschriebenen Fälle;
 * jede Datei, die die Owner-Verbindung nutzt, steht auf der Allowlist in
 * tests/Unit/OwnerConnectionUsageTest.php.
 */
final class OwnerConnection
{
    public const NAME = 'pgsql_admin';

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        $original = config('database.default');
        config(['database.default' => self::NAME]);

        try {
            return $callback();
        } finally {
            config(['database.default' => $original]);
        }
    }
}
