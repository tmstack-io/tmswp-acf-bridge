<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Exception;

final class AcfNotActiveException extends AcfBridgeException
{
    public static function forCall(string $callable): self
    {
        return new self("{$callable} requires Advanced Custom Fields (ACF) 6.2+ to be installed and active. Install/activate the ACF plugin, or remove tmswp/acf-bridge usages.");
    }
}
