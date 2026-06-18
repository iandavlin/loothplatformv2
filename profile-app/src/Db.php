<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

use PDO;

final class Db
{
    private static ?PDO $pg = null;

    public static function pg(): PDO
    {
        if (self::$pg === null) {
            self::$pg = new PDO(LG_PROFILE_APP_PG_DSN, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pg;
    }
}
