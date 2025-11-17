<?php

namespace Icinga\Module\Perfdatagraphsinfluxdbv1\Client;

use Icinga\Application\Config;
use Icinga\Application\Logger;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

use DateInterval;
use DateTime;
use Exception;

/**
 * Influx handles calling the API and returning the data.
 */
class Influx
{
    protected const QUERY_ENDPOINT = '/query';

    /** @var $this \Icinga\Application\Modules\Module */
    protected $client = null;

    protected string $URL;
    protected string $database;
    protected string $username;
    protected string $password;
    protected int $maxDataPoints;

    public function __construct(
        string $baseURI,
        string $database,
        string $username,
        string $password,
        int $timeout = 2,
        int $maxDataPoints = 10000,
        bool $tlsVerify = true
    ) {
        $this->client = new Client([
            'timeout' => $timeout,
            'verify' => $tlsVerify
        ]);

        $this->URL = rtrim($baseURI, '/');

        $this->database = $database;
        $this->username = $username;
        $this->password = $password;
        $this->maxDataPoints = $maxDataPoints;
    }

    public function getMetrics(
        string $hostName,
        string $serviceName,
        string $checkCommand,
        string $from,
        bool $isHostCheck,
    ): Response {

        $counts = $this->getMetricCount(
            $hostName,
            $serviceName,
            $checkCommand,
            $from,
            $isHostCheck
        );

        $selector = sprintf("hostname = '%s'", $hostName);

        if (!$isHostCheck) {
            $selector .= sprintf(" AND service = '%s'", $serviceName);
        }

        $q = sprintf(
            "SELECT value, warn, crit, unit FROM \"%s\" WHERE (%s) AND time >= %ds AND time <= now() GROUP BY metric",
            $checkCommand,
            $selector,
            $from,
        );

        if ($this->maxDataPoints > 0) {
            $windowEverySeconds = $this->getAggregateWindow($from, $counts);
            if ($windowEverySeconds > 0) {
                $q = sprintf(
                    "SELECT LAST(value) AS value, LAST(warn) AS warn, LAST(crit) AS crit, LAST(unit) AS unit
                    FROM \"%s\" WHERE (%s) AND time >= %ds AND time <= now() GROUP BY time(%ss), metric",
                    $checkCommand,
                    $selector,
                    $from,
                    $windowEverySeconds,
                );
            }
        }

        $query = [
            'stream' => true,
            'headers' => [
                'Accept' => 'application/csv',
            ],
            'auth' => [
                $this->username,
                $this->password,
            ],
            'query' => [
                'db' => $this->database,
                'q' => $q,
                'epoch' => 's'
            ],
        ];

        $url = $this->URL . $this::QUERY_ENDPOINT;

        Logger::debug('Calling query API at %s with query: %s', $url, $query);

        $response = $this->client->request('POST', $url, $query);

        return $response;
    }

    public function getMetricCount(
        string $hostName,
        string $serviceName,
        string $checkCommand,
        string $from,
        bool $isHostCheck,
    ): array {

        $selector = sprintf("hostname = '%s'", $hostName);

        if (!$isHostCheck) {
            $selector .= sprintf(" AND service = '%s'", $serviceName);
        }

        $q = sprintf(
            "SELECT COUNT(value) FROM \"%s\" WHERE (%s) AND time >= %ds AND time <= now() GROUP BY metric",
            $checkCommand,
            $selector,
            $from,
        );

        $query = [
            'stream' => true,
            'headers' => [
                'Accept' => 'application/csv',
            ],
            'auth' => [
                $this->username,
                $this->password,
            ],
            'query' => [
                'db' => $this->database,
                'q' => $q,
                'epoch' => 's'
            ],
        ];

        $url = $this->URL . $this::QUERY_ENDPOINT;

        Logger::debug('Calling query API at %s with count query: %s', $url, $query);

        $response = $this->client->request('POST', $url, $query);

        $stream = new InfluxCsvParser($response->getBody(), true);

        $metricStats = [];
        foreach ($stream->each() as $record) {
            $metricname = $record->getMetricName();
            $metricStats[$metricname] = $record->getValue();
        }

        return $metricStats;
    }

    /**
     * status calls the Influx HTTP API to determine if Influx is reachable.
     * We use this to validate the configuration and if the API is reachable.
     *
     * @return array
     */
    public function status(): array
    {
        $q = sprintf(
            "SELECT COUNT(*) FROM %s",
            $this->database
        );

        $query = [
            'query' => [
                'db' => $this->database,
                'q' => $q,
            ],
            'auth' => [
                $this->username,
                $this->password,
            ],
            'headers' => [
                'Content-Type' => 'application/json',
            ]
        ];

        $url = $this->URL . $this::QUERY_ENDPOINT;

        try {
            $response = $this->client->request('GET', $url, $query);

            return ['output' =>  $response->getBody()->getContents()];
        } catch (ConnectException $e) {
            return ['output' => 'Connection error: ' . $e->getMessage(), 'error' => true];
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                return ['output' => 'HTTP error: ' . $e->getResponse()->getStatusCode() . ' - ' .
                                      $e->getResponse()->getReasonPhrase(), 'error' => true];
            } else {
                return ['output' => 'Request error: ' . $e->getMessage(), 'error' => true];
            }
        } catch (Exception $e) {
            return ['output' => 'General error: ' . $e->getMessage(), 'error' => true];
        }

        return ['output' => 'Unknown error', 'error' => true];
    }

   /**
     * getAggregateWindow calculates the size of the aggregate window.
     * If there is no need to aggregate it returns 0.
     *
     * @return int
     */
    protected function getAggregateWindow(string $from, array $count): int
    {
        // Since all time series are part of the same check, they have the same count
        $numOfDatapoints = array_pop($count);

        // If there are less datapoints than the max, we can just return
        if ($numOfDatapoints < $this->maxDataPoints) {
            return 0;
        }

        $now = (new DateTime())->getTimestamp();
        $from = intval($from);
        // If there are datapoints than allowed we calculate an aggregation window size
        if ($numOfDatapoints > $this->maxDataPoints) {
            return (int) round(($now - $from) / $this->maxDataPoints);
        }

        return 0;
    }

    /**
     * parseDuration parses the duration string from the frontend
     * into something we can use with the Influx API.
     *
     * @param string $duration ISO8601 Duration
     * @param string $now current time (used in testing)
     * @return string
     */
    public static function parseDuration(\DateTime $now, string $duration): string
    {
        try {
            $int = new DateInterval($duration);
        } catch (Exception $e) {
            Logger::error('Failed to parse date interval: %s', $e);
            $int = new DateInterval('PT12H');
        }

        $ts = $now->sub($int);

        return $ts->getTimestamp();
    }

    /**
     * fromConfig returns a new Influx Client from this module's configuration
     *
     * @param Config $moduleConfig configuration to load (used for testing)
     * @return $this
     */
    public static function fromConfig(Config $moduleConfig = null): Influx
    {
        $default = [
            'api_url' => 'http://localhost:8086',
            'api_timeout' => 10,
            'api_database' => '',
            'api_username' => '',
            'api_password' => '',
            'api_max_data_points' => 10000,
            'api_tls_insecure' => false,
        ];

        // Try to load the configuration
        if ($moduleConfig === null) {
            try {
                Logger::debug('Loaded Perfdata Graphs InfluxDBv1 module configuration to get Config');
                $moduleConfig = Config::module('perfdatagraphsinfluxdbv1');
            } catch (Exception $e) {
                Logger::error('Failed to load Perfdata Graphs InfluxDBv1 module configuration: %s', $e);
                return $default;
            }
        }

        $baseURI = rtrim($moduleConfig->get('influx', 'api_url', $default['api_url']), '/');
        $timeout = (int) $moduleConfig->get('influx', 'api_timeout', $default['api_timeout']);
        $maxDataPoints = (int) $moduleConfig->get('influx', 'api_max_data_points', $default['api_max_data_points']);
        $database = $moduleConfig->get('influx', 'api_database', $default['api_database']);
        $username = $moduleConfig->get('influx', 'api_username', $default['api_username']);
        $password = $moduleConfig->get('influx', 'api_password', $default['api_password']);
        // Hint: We use a "skip TLS" logic in the UI, but Guzzle uses "verify TLS"
        $tlsVerify = !(bool) $moduleConfig->get('influx', 'api_tls_insecure', $default['api_tls_insecure']);

        return new static($baseURI, $database, $username, $password, $timeout, $maxDataPoints, $tlsVerify);
    }
}
