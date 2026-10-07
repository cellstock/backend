<?php

namespace App\Enums;

enum MarketplaceConnectionStatus: string
{
    case Pending = 'pending';
    case Testing = 'testing';
    case Connected = 'connected';
    case Failed = 'failed';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Testing => 'Testing',
            self::Connected => 'Connected',
            self::Failed => 'Connection Failed',
            self::Disabled => 'Disabled',
        };
    }

    public function isConnected(): bool
    {
        return $this === self::Connected;
    }
}