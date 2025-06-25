<?php

namespace NuiMarkets\LaravelSharedUtils\Contracts;

interface MachineTokenServiceInterface
{
    /**
     * Get the machine token for API authentication
     *
     * @throws \Exception if token retrieval fails
     */
    public function getToken(): string;
}
