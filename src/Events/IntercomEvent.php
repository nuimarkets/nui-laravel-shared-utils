<?php

namespace NuiMarkets\LaravelSharedUtils\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IntercomEvent
{
    use Dispatchable, SerializesModels;

    public string $userId;

    public string $event;

    public array $properties;

    public ?string $tenantId;

    /**
     * Create a new event instance.
     */
    public function __construct(string $userId, string $event, array $properties = [], ?string $tenantId = null)
    {
        $this->userId = $userId;
        $this->event = $event;
        $this->properties = $properties;
        $this->tenantId = $tenantId;

    }
}
