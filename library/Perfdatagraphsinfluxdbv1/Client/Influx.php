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

    protected \GuzzleHttp\Client $client;
    protected string $URL;
    protected string $database;
    protected array $auth;
    protected string $hostnameTag;
    protected string $servicenameTag;
    protected int $maxDataPoints;

    public function __construct(
        string $baseURI,
        string $database,
        string $hostnameTag,
        string $servicenameTag,
        int $timeout = 10,
        int $maxDataPoints = 10000,
        bool $tlsVerify = true,
        array $auth = [],
    ) {
        $this->client = new Client([
            'timeout' => $timeout,
            'verify' => $tlsVerify
        ]);

        $this->URL = rtrim($baseURI, '/');

        $this->database = $database;
        $this->auth = $auth;
        $this->hostnameTag = $hostnameTag;
        $this->servicenameTag = $servicenameTag;
        $this->maxDataPoints = $maxDataPoints;
    }

    protected function getAuth(): array
    {
        $method = $this->auth['method'] ?? 'none';

        $authOptions = [];

        if ($method === 'basic') {
            $authOptions['auth'] = [
                $this->auth['username'] ?? '',
                $this->auth['password'] ?? ''
            ];
        }

        if ($method === 'token') {
            $t = $this->auth['tokentype'] ?? 'Bearer';
            $v = $this->auth['tokenvalue'] ?? '';
            $authOptions['headers'] = [
                    'Authorization' =>  $t .' '. $v,
            ];
        }

        $mtls = $this->auth['mtls'] ?? false;

        if ($mtls === false) {
            return $authOptions;
        }

        if ($mtls) {
            $authOptions['cert'] = $this->auth['mtls_cert'] ?? '';
            $authOptions['ssl_key'] = $this->auth['mtls_key'] ?? '';
            if (($this->auth['mtls_ca'] ?? '') !== '') {
                $authOptions['verify'] = $this->auth['mtls_ca'] ?? '';
            }
        }

        return $authOptions;
    }

    protected function generateSelect(bool $isHostCheck, string $hostName, string $serviceName): string
    {
        $selector = sprintf("%s = '%s'", $this->hostnameTag, addslashes($hostName));

        if (!$isHostCheck) {
            $selector .= sprintf(" AND %s = '%s'", $this->servicenameTag, addslashes($serviceName));
        } else {
            $selector .= sprintf(" AND %s = ''", $this->servicenameTag);
        }

        return $selector;
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

        $selector = $this->generateSelect($isHostCheck, $hostName, $serviceName);

        $q = sprintf(
            "SELECT value, warn, crit, unit FROM \"%s\" WHERE (%s) AND time >= %ds AND time <= now() GROUP BY metric",
            addslashes($checkCommand),
            $selector,
            $from,
        );

        if ($this->maxDataPoints > 0) {
            $windowEverySeconds = $this->getAggregateWindow($from, $counts);
            if ($windowEverySeconds > 0) {
                $q = sprintf(
                    "SELECT LAST(value) AS value, LAST(warn) AS warn, LAST(crit) AS crit, LAST(unit) AS unit
                    FROM \"%s\" WHERE (%s) AND time >= %ds AND time <= now() GROUP BY time(%ss), metric",
                    addslashes($checkCommand),
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
            'query' => [
                'db' => $this->database,
                'q' => $q,
                'epoch' => 's'
            ],
        ];

        $url = $this->URL . $this::QUERY_ENDPOINT;

        $query = array_merge($query, $this->getAuth());

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
        $selector = $this->generateSelect($isHostCheck, $hostName, $serviceName);

        $q = sprintf(
            "SELECT COUNT(value) FROM \"%s\" WHERE (%s) AND time >= %ds AND time <= now() GROUP BY metric",
            addslashes($checkCommand),
            $selector,
            $from,
        );

        $query = [
            'stream' => true,
            'headers' => [
                'Accept' => 'application/csv',
            ],
            'query' => [
                'db' => $this->database,
                'q' => $q,
                'epoch' => 's'
            ],
        ];

        $url = $this->URL . $this::QUERY_ENDPOINT;

        $query = array_merge($query, $this->getAuth());

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
            'headers' => [
                'Content-Type' => 'application/json',
            ]
        ];

        $url = $this->URL . $this::QUERY_ENDPOINT;

        $query = array_merge($query, $this->getAuth());

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
     * @param string $from timestamp in seconds
     * @param array $count count of datapoints
     * @return int size of the aggregation window in seconds
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
            $interval = new DateInterval($duration);
        } catch (Exception $e) {
            Logger::error('Failed to parse date interval: %s', $e);
            $interval = new DateInterval('PT12H');
        }

        return (clone $now)->sub($interval)->getTimestamp();
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
            'api_max_data_points' => 10000,
            'api_tls_insecure' => false,
            'writer_host_name_template_tag' => 'hostname',
            'writer_service_name_template_tag' => 'service',
            'api_auth_method' => 'none',
            'api_auth_tokentype' => 'Bearer',
            'api_auth_tokenvalue' => '',
            'api_auth_username' => '',
            'api_auth_password' => '',
            'api_auth_mtls' => false,
            'api_auth_mtls_cert' => '',
            'api_auth_mtls_key' => '',
            'api_auth_mtls_ca' => '',
        ];

        // Try to load the configuration
        if ($moduleConfig === null) {
            try {
                Logger::debug('Loaded Perfdata Graphs InfluxDBv1 module configuration to get Config');
                $moduleConfig = Config::module('perfdatagraphsinfluxdbv1');
            } catch (Exception $e) {
                Logger::error('Failed to load Perfdata Graphs InfluxDBv1 module configuration: %s', $e);
                return new static(
                    baseURI: $default['api_url'],
                    timeout: $default['api_timeout'],
                    tlsVerify: true,
                    maxDataPoints: $default['max_data_points'],
                    hostnameTag: $default['writer_host_name_template_tag'],
                    servicenameTag: $default['writer_service_name_template_tag'],
                    auth: [],
                );
            }
        }

        $baseURI = rtrim($moduleConfig->get('influx', 'api_url', $default['api_url']), '/');
        $timeout = (int) $moduleConfig->get('influx', 'api_timeout', $default['api_timeout']);
        $maxDataPoints = (int) $moduleConfig->get('influx', 'api_max_data_points', $default['api_max_data_points']);
        $database = $moduleConfig->get('influx', 'api_database', $default['api_database']);
        $hostnameTag = $moduleConfig->get('influx', 'writer_host_name_template_tag', $default['writer_host_name_template_tag']);
        $servicenameTag = $moduleConfig->get('influx', 'writer_service_name_template_tag', $default['writer_service_name_template_tag']);
        // Auth values
        $authMethod = $moduleConfig->get('influx', 'api_auth_method', $default['api_auth_method']);
        $authTokenType = $moduleConfig->get('influx', 'api_auth_tokentype', $default['api_auth_tokentype']);
        $authTokenValue = $moduleConfig->get('influx', 'api_auth_tokenvalue', $default['api_auth_tokenvalue']);
        $authUsername = $moduleConfig->get('influx', 'api_auth_username', $default['api_auth_username']);
        $authPassword = $moduleConfig->get('influx', 'api_auth_password', $default['api_auth_password']);
        // mTLS values
        $authMTLS = $moduleConfig->get('influx', 'api_auth_mtls', $default['api_auth_mtls']);
        $authMTLSCert = $moduleConfig->get('influx', 'api_auth_mtls_cert', $default['api_auth_mtls_cert']);
        $authMTLSKey = $moduleConfig->get('influx', 'api_auth_mtls_key', $default['api_auth_mtls_key']);
        $authMTLSCA = $moduleConfig->get('influx', 'api_auth_mtls_ca', $default['api_auth_mtls_ca']);
        // Hint: We use a "skip TLS" logic in the UI, but Guzzle uses "verify TLS"
        $tlsVerify = !(bool) $moduleConfig->get('influx', 'api_tls_insecure', $default['api_tls_insecure']);

        $auth = [
            'method' => mb_strtolower($authMethod),
            'tokentype' => $authTokenType,
            'tokenvalue' => $authTokenValue,
            'username' => $authUsername,
            'password' => $authPassword,
            'mtls' => $authMTLS,
            'mtls_cert' => $authMTLSCert,
            'mtls_key' => $authMTLSKey,
            'mtls_ca' => $authMTLSCA,
        ];

        return new static(
            baseURI: $baseURI,
            database: $database,
            hostnameTag: $hostnameTag,
            servicenameTag: $servicenameTag,
            timeout: $timeout,
            maxDataPoints: $maxDataPoints,
            tlsVerify: $tlsVerify,
            auth: $auth,
        );
    }
}
