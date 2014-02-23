<?php

namespace GuzzleHttp\Service\Guzzle\Subscriber;

use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Service\Guzzle\GuzzleClientInterface;
use GuzzleHttp\Service\Guzzle\GuzzleCommandInterface;
use GuzzleHttp\Service\Guzzle\RequestLocation\BodyLocation;
use GuzzleHttp\Service\Guzzle\RequestLocation\HeaderLocation;
use GuzzleHttp\Service\Guzzle\RequestLocation\JsonLocation;
use GuzzleHttp\Service\Guzzle\RequestLocation\PostFieldLocation;
use GuzzleHttp\Service\Guzzle\RequestLocation\PostFileLocation;
use GuzzleHttp\Service\Guzzle\RequestLocation\QueryLocation;
use GuzzleHttp\Service\Guzzle\RequestLocation\XmlLocation;
use GuzzleHttp\Service\PrepareEvent;
use GuzzleHttp\Service\Guzzle\Description\Parameter;
use GuzzleHttp\Service\Guzzle\RequestLocation\RequestLocationInterface;

/**
 * Subscriber used to create HTTP requests for commands based on a service
 * description.
 */
class PrepareRequest implements SubscriberInterface
{
    /** @var RequestLocationInterface[] */
    private $requestLocations;

    public static function getSubscribedEvents()
    {
        return ['prepare' => ['onPrepare']];
    }

    /**
     * @param RequestLocationInterface[] $requestLocations Extra request locations
     */
    public function __construct(array $requestLocations = [])
    {
        static $defaultRequestLocations;
        if (!$defaultRequestLocations) {
            $defaultRequestLocations = [
                'body'      => new BodyLocation(),
                'query'     => new QueryLocation(),
                'header'    => new HeaderLocation(),
                'json'      => new JsonLocation(),
                'xml'       => new XmlLocation(),
                'postField' => new PostFieldLocation(),
                'postFile'  => new PostFileLocation()
            ];
        }

        $this->requestLocations = $requestLocations + $defaultRequestLocations;
    }

    public function onPrepare(PrepareEvent $event)
    {
        /* @var GuzzleCommandInterface $command */
        $command = $event->getCommand();
        /* @var GuzzleClientInterface $client */
        $client = $event->getClient();
        $request = $this->createRequest($command, $client);
        $this->prepareRequest($command, $client, $request);
        $event->setRequest($request);
    }

    /**
     * Prepares a request for sending using location visitors
     *
     * @param GuzzleCommandInterface $command Command to prepare
     * @param GuzzleClientInterface  $client  Client that owns the command
     * @param RequestInterface       $request Request being created
     * @throws \RuntimeException If a location cannot be handled
     */
    protected function prepareRequest(
        GuzzleCommandInterface $command,
        GuzzleClientInterface $client,
        RequestInterface $request
    ) {
        $visitedLocations = [];
        $context = ['client' => $client, 'command' => $command];
        foreach ($command->getOperation()->getParams() as $name => $param) {
            /* @var Parameter $param */
            $location = $param->getLocation();
            // Skip parameters that have not been set or are URI location
            if ($location == 'uri' || !$command->hasParam($name)) {
                continue;
            }
            if (!isset($this->requestLocations[$location])) {
                throw new \RuntimeException("No location registered for $location");
            }
            $visitedLocations[$location] = true;
            $this->requestLocations[$location]->visit(
                $request,
                $param,
                $command[$name],
                $context
            );
        }

        foreach (array_keys($visitedLocations) as $location) {
            $this->requestLocations[$location]->after($request, $context);
        }
    }

    /**
     * Create a request for the command and operation
     *
     * @param GuzzleCommandInterface $command Command being executed
     * @param GuzzleClientInterface  $client  Client used to execute the command
     *
     * @return RequestInterface
     * @throws \RuntimeException
     */
    protected function createRequest(
        GuzzleCommandInterface $command,
        GuzzleClientInterface $client
    ) {
        $operation = $command->getOperation();

        // If the command does not specify a template, then assume the base URL
        // of the client
        if (null === ($uri = $operation->getUri())) {
            return $client->getHttpClient()->createRequest(
                $operation->getHttpMethod(),
                $client->getDescription()->getBaseUrl(),
                $command['request_options'] ?: []
            );
        }

        return $this->createCommandWithUri($command, $client);
    }

    /**
     * Create a request for an operation with a uri merged onto a base URI
     */
    private function createCommandWithUri(
        GuzzleCommandInterface $command,
        GuzzleClientInterface $client
    ) {
        // Get the path values and use the client config settings
        $variables = [];
        $operation = $command->getOperation();
        foreach ($operation->getParams() as $name => $arg) {
            /* @var Parameter $arg */
            if ($arg->getLocation() == 'uri') {
                if (isset($command[$name])) {
                    $variables[$name] = $arg->filter($command[$name]);
                    if (!is_array($variables[$name])) {
                        $variables[$name] = (string) $variables[$name];
                    }
                }
            }
        }

        return $client->getHttpClient()->createRequest(
            $operation->getHttpMethod(),
            [$client->getDescription()->getBaseUrl()->combine($operation->getUri()), $variables],
            $command['request_options'] ?: []
        );
    }
}