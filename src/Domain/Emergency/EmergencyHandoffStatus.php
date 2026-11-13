<?php
declare(strict_types=1);

namespace Latra\Domain\Emergency;

enum EmergencyHandoffStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    public static function fromResult(array $result): self
    {
        if (empty($result['ok'])) {
            return self::Failed;
        }

        return array_key_exists('queued', $result) ? self::Queued : self::Sent;
    }
}
