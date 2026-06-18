<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

use Ramsey\Uuid\Uuid;

final class Identity
{
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function computeUuid(string $email): string
    {
        $ns = Uuid::fromString(LOOTH_IDENTITY_NAMESPACE);
        return Uuid::uuid5($ns, self::normalizeEmail($email))->toString();
    }
}
