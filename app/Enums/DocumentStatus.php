<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Sent = 'sent';
    case Failed = 'failed';
    case Uncertain = 'uncertain';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Processing, self::Failed], true),
            self::Processing => in_array($next, [self::Sent, self::Failed, self::Uncertain], true),
            self::Failed => in_array($next, [self::Pending, self::Processing], true),
            self::Sent, self::Uncertain => false,
        };
    }
}
