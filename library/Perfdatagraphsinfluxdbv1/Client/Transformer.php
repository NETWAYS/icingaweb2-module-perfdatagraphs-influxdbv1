<?php

namespace Icinga\Module\Perfdatagraphsinfluxdbv1\Client;

use Icinga\Module\Perfdatagraphs\Model\PerfdataResponse;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSet;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSeries;

use GuzzleHttp\Psr7\Response;

/**
 * Transformer handles all data transformation.
 */
class Transformer
{
    /**
     * isIncluded checks if the given metricname is in the given list
     *
     * @param string $metricname name of the metric to find
     * @param array $includeMetrics metrics to include
     * @return bool
     */
    public static function isIncluded($metricname, array $includeMetrics = []): bool
    {
        // All are included if not set
        if (count($includeMetrics) === 0) {
            return true;
        }
        foreach ($includeMetrics as $pattern) {
            if (fnmatch($pattern, $metricname)) {
                return true;
            }
        }
        return false;
    }

    /**
     * isExcluded checks if the given metricname is in the given list
     *
     * @param string $metricname name of the metric to find
     * @param array $excludeMetrics metrics to exlude from the response
     * @return bool
     */
    public static function isExcluded($metricname, array $excludeMetrics = []): bool
    {
        // None are exlucded if not set
        if (count($excludeMetrics) === 0) {
            return false;
        }

        return in_array($metricname, $excludeMetrics);
    }

    /**
     * transform takes the InfluxDB response and transforms it into the
     * output format we need.
     *
     * @param GuzzleHttp\Psr7\Response $response the data to transform
     * @param array $includeMetrics metrics to include in the response
     * @param array $excludeMetrics metrics to exlude from the response
     * @return PerfdataResponse
     */
    public static function transform(
        Response $response,
        array $includeMetrics = [],
        array $excludeMetrics = [],
    ): PerfdataResponse {
        $pfr = new PerfdataResponse();

        if (empty($response)) {
            return $pfr;
        }

        $stream = new InfluxCsvParser($response->getBody(), true);

        $timestamps = [];
        // Create PerfdataSeries and add to PerfdataSet
        $valueseries = [];
        $warningseries = [];
        $criticalseries = [];
        $units = [];

        foreach ($stream->each() as $record) {
            $metricname = $record->getMetricName();

            if (!self::isIncluded($metricname, $includeMetrics)) {
                continue;
            }

            if (self::isExcluded($metricname, $excludeMetrics)) {
                continue;
            }

            if (!isset($valueseries[$metricname])) {
                $valueseries[$metricname] = [];
            };

            if (!isset($timestamps[$metricname])) {
                $timestamps[$metricname] = [];
            }

            if (!isset($units[$metricname])) {
                $units[$metricname] = [];
            };

            $units[$metricname] = $record->getUnit();
            $timestamps[$metricname][] = $record->getTimestamp();
            $valueseries[$metricname][] = $value = $record->getValue();

            if (!isset($warningseries[$metricname])) {
                $warningseries[$metricname] = [];
            }
            $warningseries[$metricname][] = $record->getWarning();

            if (!isset($criticalseries[$metricname])) {
                $criticalseries[$metricname] = [];
            }
            $criticalseries[$metricname][] = $record->getCritical();
        }

        // Add the data to the PerfdataResponse.
        // We could probably do this in the previous loop, I did a bit of
        // profiling and it did not make much of a difference. But I'm
        // not a PHP Pro, so if someone wants to try it feel free.
        foreach (array_keys($valueseries) as $metric) {
            $s = new PerfdataSet($metric, $units[$metric] ?? '');

            $s->setTimestamps($timestamps[$metric]);

            if (array_key_exists($metric, $valueseries)) {
                $values = new PerfdataSeries('value', $valueseries[$metric]);
                $s->addSeries($values);
            }

            if (array_key_exists($metric, $warningseries) && !empty($warningseries)) {
                $warnings = new PerfdataSeries('warning', $warningseries[$metric]);
                $s->addSeries($warnings);
            }

            if (array_key_exists($metric, $criticalseries) && !empty($criticalseries)) {
                $criticals = new PerfdataSeries('critical', $criticalseries[$metric]);
                $s->addSeries($criticals);
            }

            $pfr->addDataset($s);
        }

        return $pfr;
    }
}
