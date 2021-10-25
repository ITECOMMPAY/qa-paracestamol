<?php


namespace Paracestamol\Test;


use Ds\Map;
use Ds\Queue;
use GuzzleHttp\Client;
use Paracestamol\Exceptions\StatisticsResponseException;
use Paracestamol\Log\Log;
use Paracestamol\Settings\SettingsRun;
use Paracestamol\Test\CodeceptWrapper\ICodeceptWrapper;
use Psr\Http\Message\ResponseInterface;

class Statistics
{
    protected Log         $log;
    protected SettingsRun $settings;
    protected Client      $httpClient;

    protected array $nameIdCache;

    public function __construct(Log $log, SettingsRun $settings, Client $client)
    {
        $this->log = $log;
        $this->settings = $settings;
        $this->httpClient = $client;
    }

    public function sendActualDurations(Map $testNameToDuration)
    {
        $this->log->verbose('Sending tests durations to the statistics server');

        $project = $this->getProjectFromSettings();
        $env     = $this->getEnvFromSettings();

        $projectId = $this->getNameId('project', $project);
        $envId     = $this->getNameId('environment', $env);

        $testNames = $testNameToDuration->keys();

        $records = [];

        foreach ($testNames as $testName)
        {
            $records []= ['name' => $testName];
        }

        $uri = $this->settings->getStatEndpoint() . '/' . 'test';

        $this->paginatedPost($uri, $records, 'name');

        $testIdToName = $this->paginatedGet($uri, 'id', 'name')->toArray();
        $testNameToId = array_flip($testIdToName);

        $records = [];

        foreach ($testNameToDuration as $testName => $duration)
        {
            $testId = $testNameToId[$testName];

            $records []= [
                'project_id'       => $projectId,
                'environment_id'   => $envId,
                'test_id'          => $testId,
                'duration_seconds' => $duration,
            ];

            $this->log->debug("{$testName}: {$duration}");
        }

        $uri = $this->settings->getStatEndpoint() . '/' . 'duration';

        $this->paginatedPost($uri, $records);
    }

    public function fetchExpectedDurations(Queue $tests) : Queue
    {
        $this->log->verbose('Getting tests durations from the statistics server');

        $project = $this->getProjectFromSettings();
        $env     = $this->getEnvFromSettings();

        $projectId = $this->getNameId('project', $project);
        $envId     = $this->getNameId('environment', $env);


        $uri              = $this->settings->getStatEndpoint() . '/' . 'duration_median';
        $testIdToDuration = $this->paginatedGet($uri, 'test_id', 'median_duration_seconds',
                                          [
                                              'project_id' => 'eq.' . $projectId,
                                              'environment_id' => 'eq.' . $envId,
                                          ]);

        $uri              = $this->settings->getStatEndpoint() . '/' . 'test';
        $testIdToName     = $this->paginatedGet($uri, 'id', 'name');

        $testNameToDuration = new Map();

        foreach ($testIdToName as $id => $name)
        {
            if (!$testIdToDuration->hasKey($id))
            {
                continue;
            }

            $duration = $testIdToDuration->get($id);

            $this->log->debug("{$name}: {$duration}");

            $testNameToDuration->put($name, (int) $duration);
        }

        if ($testNameToDuration->isEmpty())
        {
            return $tests;
        }

        $processedTests = new Queue();

        $successfullyFetchedDurations = false;

        /** @var ICodeceptWrapper $test */
        foreach ($tests as $test)
        {
            if ($testNameToDuration->hasKey((string) $test))
            {
                $duration = $testNameToDuration->get((string) $test);
                $test->setExpectedDuration($duration);
                $successfullyFetchedDurations = true;
            }

            $processedTests->push($test);
        }

        $this->settings->setSuccessfullyFetchedDurations($successfullyFetchedDurations);

        return $processedTests;
    }

    protected function getProjectFromSettings() : string
    {
        $projectName = $this->settings->getProjectName();

        if (empty($projectName))
        {
            $projectName = '_';
        }

        return $projectName;
    }

    protected function getEnvFromSettings() : string
    {
        $env = $this->settings->getEnvAsString();

        if (empty($env))
        {
            $env = '_';
        }

        return $env;
    }

    protected function getNameId(string $table, string $name, bool $autocreate = true) : int
    {
        if (isset($this->nameIdCache[$table][$name]))
        {
            return $this->nameIdCache[$table][$name];
        }

        $uri = $this->settings->getStatEndpoint() . '/' . $table;

        $response = $this->httpClient->get($uri,
                                           [
                                               'query' => [
                                                    'select' => 'id',
                                                    'name'   => 'eq.' . $name,
                                                ],
                                               'http_errors' => false,
                                           ]);

        if ($response->getStatusCode() !== 200)
        {
            throw new StatisticsResponseException($this->printErrorResponse($response));
        }

        $contents = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

        if (isset($contents[0]['id']))
        {
            $this->nameIdCache[$table][$name] = (int) $contents[0]['id'];
            return $this->nameIdCache[$table][$name];
        }

        if ($autocreate === false)
        {
            throw new StatisticsResponseException("No record with {$table}.name = $name");
        }

        $response = $this->httpClient->post($uri,
                                            [
                                                'json' => [
                                                    'name' => $name,
                                                ],
                                                'headers' => [
                                                    'Prefer' => 'return=representation',
                                                ],
                                                'http_errors' => false,
                                            ]);

        if ($response->getStatusCode() === 409) // Already created by a different process
        {
            return $this->getNameId($table, $name, false);
        }

        if ($response->getStatusCode() !== 201)
        {
            throw new StatisticsResponseException($this->printErrorResponse($response));
        }

        $contents = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

        if (!isset($contents[0]['id']))
        {
            throw new StatisticsResponseException($this->printErrorResponse($response));
        }

        $this->nameIdCache[$table][$name] = (int) $contents[0]['id'];
        return $this->nameIdCache[$table][$name];
    }

    protected function paginatedPost(string $uri, array $records, string $uniqueColumn = '')
    {
        $chunks = array_chunk($records, $this->settings->getBulkRowsCount());

        $additionalParams = [];

        if (!empty($uniqueColumn))
        {
            $additionalParams = [
                'headers' => [
                    'Prefer' => 'resolution=ignore-duplicates',
                ],
                'query' => [
                    'on_conflict' => $uniqueColumn
                ],
            ];
        }

        foreach ($chunks as $chunk)
        {
            $response = $this->httpClient->post($uri,
                                                [
                                                    'json' => $chunk,
                                                    'http_errors' => false,
                                                ] + $additionalParams);

            if ($response->getStatusCode() !== 201)
            {
                throw new StatisticsResponseException($this->printErrorResponse($response));
            }
        }
    }

    protected function paginatedGet(string $uri, string $keyColumn, string $valueColumn, array $queryParams = []) : Map
    {
        $result = new Map();

        $limit = $this->settings->getBulkRowsCount();
        $offset = 0;

        while (true)
        {
            $response = $this->httpClient->get(
                $uri,
                [
                    'query'       => [
                                         'select' => "{$keyColumn},{$valueColumn}",
                                         'limit'  => $limit,
                                         'offset' => $offset,
                                     ] + $queryParams,
                    'http_errors' => false,
                ]
            );

            if ($response->getStatusCode() !== 200)
            {
                throw new StatisticsResponseException($this->printErrorResponse($response));
            }

            $contents = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            if (empty($contents))
            {
                break;
            }

            foreach ($contents as $record)
            {
                $result->put($record[$keyColumn], $record[$valueColumn]);
            }

            $offset += $limit;
        }

        return $result;
    }

    protected function printErrorResponse(ResponseInterface $response) : string
    {
        return $response->getStatusCode() . ' ' . $response->getBody()->getContents();
    }
}
