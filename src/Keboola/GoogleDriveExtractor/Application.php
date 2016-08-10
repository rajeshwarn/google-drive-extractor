<?php

/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 10/08/16
 * Time: 15:45
 */

namespace Keboola\GoogleDriveExtractor;

use GuzzleHttp\Exception\RequestException;
use Keboola\Google\ClientBundle\Google\RestApi;
use Monolog\Handler\NullHandler;
use Pimple\Container;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

class Application
{
    private $container;

    public function __construct($config)
    {
        $container = new Container();
        $container['action'] = isset($config['action'])?$config['action']:'run';
        $container['parameters'] = $this->validateParamteters($config['parameters']);
        $container['logger'] = function ($c) {
            $logger = new Logger(APP_NAME);
            if ($c['action'] !== 'run') {
                $logger->setHandlers([new NullHandler(Logger::INFO)]);
            }
            return $logger;
        };
        if (empty($config['authorization'])) {
            throw new UserException('Missing authorization data');
        }
        $tokenData = json_decode($config['authorization']['oauth_api']['credentials']['#data'], true);
        $container['google_client'] = function () use ($config, $tokenData) {
            return new RestApi(
                $config['authorization']['oauth_api']['credentials']['appKey'],
                $config['authorization']['oauth_api']['credentials']['#appSecret'],
                $tokenData['access_token'],
                $tokenData['refresh_token']
            );
        };
        $container['google_drive_client'] = function ($c) {
            return new Client($c['google_client']);
        };
        $container['output'] = function ($c) {
            return new Output($c['parameters']['data_dir'], $c['parameters']['outputBucket']);
        };
        $container['extractor'] = function ($c) {
            return new Extractor(
                $c['google_analytics_client'],
                $c['output'],
                $c['logger']
            );
        };

        $this->container = $container;
    }

    public function run()
    {
        $actionMethod = $this->container['action'] . 'Action';
        if (!method_exists($this, $actionMethod)) {
            throw new UserException(sprintf("Action '%s' does not exist.", $this['action']));
        }

        try {
            return $this->$actionMethod();
        } catch (RequestException $e) {
            if ($e->getCode() == 401) {
                throw new UserException("Expired or wrong credentials, please reauthorize.", $e);
            }
            if ($e->getCode() == 403) {
                if (strtolower($e->getResponse()->getReasonPhrase()) == 'forbidden') {
                    $this->container['logger']->warning("You don't have access to Google Analytics resource. Probably you don't have access to profile, or profile doesn't exists anymore.");
                    return [];
                } else {
                    throw new UserException("Reason: " . $e->getResponse()->getReasonPhrase(), $e);
                }
            }
            if ($e->getCode() == 400) {
                throw new UserException($e->getMessage());
            }
            if ($e->getCode() == 503) {
                throw new UserException("Google API error: " . $e->getMessage(), $e);
            }
            throw new ApplicationException($e->getResponse()->getBody(), 500, $e);
        }
    }

    private function runAction()
    {
        $extracted = [];
        $profiles = $this->container['parameters']['profiles'];
        $queries = array_filter($this->container['parameters']['queries'], function ($query) {
            return $query['enabled'];
        });

        /** @var Output $output */
        $output = $this->container['output'];
        $csv = $output->createCsvFile('profiles');
        $output->createManifest('profiles', ['id'], true);
        $output->writeProfiles($csv, $profiles);

        /** @var Extractor $extractor */
        $extractor = $this->container['extractor'];
        foreach ($profiles as $profile) {
            $extracted[] = $extractor->run($queries, $profile);
        }

        return [
            'status' => 'ok',
            'extracted' => $extracted
        ];
    }

    private function sampleAction()
    {
        $profile = $this->container['parameters']['profiles'][0];
        $query = $this->container['parameters']['queries'][0];

        if (empty($query['query']['viewId'])) {
            $query['query']['viewId'] = $profile['id'];
        }

        /** @var Extractor $extractor */
        $extractor = $this->container['extractor'];
        return $extractor->getSampleReport($query);
    }

    private function segmentsAction()
    {
        /** @var Extractor $extractor */
        $extractor = $this->container['extractor'];
        return $extractor->getSegments();
    }

    private function validateParamteters($parameters)
    {
        try {
            $processor = new Processor();
            return $processor->processConfiguration(
                new ConfigDefinition(),
                [$parameters]
            );
        } catch (InvalidConfigurationException $e) {
            throw new UserException($e->getMessage(), 0, $e);
        }
    }
}
