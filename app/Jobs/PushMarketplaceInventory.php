<?php

namespace App\Jobs;

use App\Models\MarketplacePushPreference;
use App\Services\Inventory\MarketplacePushService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PushMarketplaceInventory implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $preferenceId,
        public readonly bool $force = false,
    ) {
        $this->onQueue('marketplaces');
    }

    public function uniqueId(): string
    {
        return 'marketplace-push-'.$this->preferenceId.($this->force ? '-force' : '');
    }

    public function handle(MarketplacePushService $pushes): void
    {
        $preference = MarketplacePushPreference::query()
            ->with('user')
            ->find($this->preferenceId);

        if ($preference === null) {
            return;
        }

        if (! $this->force && ! $pushes->shouldPush($preference)) {
            return;
        }

        $pushes->pushForPreference($preference);
    }

    public function failed(?Throwable $exception): void
    {
        report($exception);
    }
}
