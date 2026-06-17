<?php

declare(strict_types=1);

namespace Fomvasss\AiTasks\Traits;

trait RoutesDrivers
{
    protected ?array $preferredDrivers = null;

    public function viaDrivers(array|string $drivers): static
    {
        $this->preferredDrivers = is_string($drivers) ? [$drivers] : array_values($drivers);

        return $this;
    }

    public function preferredDrivers(): ?array
    {
        return $this->preferredDrivers;
    }
}
