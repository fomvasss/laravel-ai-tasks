<?php

namespace Fomvasss\AiTasks\Traits;

trait RoutesDrivers
{
    protected ?array $preferredDrivers = null; // example. ['openai'] or ['gemini','openai']

    /**
     * Set priority drivers for this task object.
     *
     * @param array|string $drivers
     * @return $this
     */
    public function viaDrivers(array|string $drivers): static
    {
        $this->preferredDrivers = is_string($drivers) ? [$drivers] : array_values($drivers);
        
        return $this;
    }

    /**
     * Get preferred drivers for this task object.
     *
     * @return array|null
     */
    public function preferredDrivers(): ?array
    {
        return $this->preferredDrivers;
    }
}