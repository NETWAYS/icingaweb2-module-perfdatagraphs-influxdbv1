<?php

namespace Icinga\Module\Perfdatagraphsinfluxdbv1\Client;

/**
 * InfluxRecord represents a single CSV line
 */
class InfluxRecord
{
    protected string $seriesname;
    protected string $metricname;
    protected int $timestamp;
    protected ?float $value;
    protected ?float $warning;
    protected ?float $critical;
    protected ?string $unit;

    public function __construct(
        string $seriesname,
        string $metricname,
        int $timestamp,
        ?float $value,
        ?float $warn,
        ?float $crit,
        ?string $unit,
    ) {
        $this->seriesname = $seriesname;
        $this->metricname = $metricname;
        $this->timestamp = $timestamp;
        $this->value = $value;
        $this->warning = $warn;
        $this->critical = $crit;
        $this->unit = $unit;
    }

    public function getSeriesName(): string
    {
        return $this->seriesname;
    }

    public function getMetricName(): string
    {
        return $this->metricname;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getValue(): ?float
    {
        return $this->value;
    }

    public function getWarning(): ?float
    {
        return $this->warning;
    }

    public function getCritical(): ?float
    {
        return $this->critical;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }
}
