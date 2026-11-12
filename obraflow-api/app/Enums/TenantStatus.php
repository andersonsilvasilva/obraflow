<?php

namespace App\Enums;

enum TenantStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';
    case Canceled = 'canceled';

    /** Suspenso e cancelado bloqueiam todo acesso a dados de negócio. */
    public function allowsAccess(): bool
    {
        return in_array($this, [self::Trial, self::Active], true);
    }
}
