<?php

namespace NuiMarkets\LaravelSharedUtils\Contracts;

interface HeaderResolverInterface
{
    /**
     * Resolve header value. Called only if header not already present in the request.
     */
    public function resolve(): ?string;
}