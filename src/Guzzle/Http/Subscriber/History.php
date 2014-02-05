<?php

namespace Guzzle\Http\Subscriber;

use Guzzle\Http\Event\RequestAfterSendEvent;
use Guzzle\Http\Event\RequestErrorEvent;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\ResponseInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Maintains a list of requests and responses sent using a request or client
 */
class History implements EventSubscriberInterface, \IteratorAggregate, \Countable
{
    /** @var int The maximum number of requests to maintain in the history */
    private $limit;

    /** @var array Requests and responses that have passed through the plugin */
    private $transactions = [];

    public static function getSubscribedEvents()
    {
        return [
            'request.after_send' => ['onRequestSent', 9999],
            'request.error' => ['onRequestError', 9999],
        ];
    }

    public function __construct($limit = 10)
    {
        $this->limit = $limit;
    }

    /**
     * Convert to a string that contains all request and response headers
     *
     * @return string
     */
    public function __toString()
    {
        $lines = array();
        foreach ($this->transactions as $entry) {
            $response = isset($entry['response']) ? $entry['response'] : '';
            $lines[] = '> ' . trim($entry['request']) . "\n\n< " . trim($response) . "\n";
        }

        return implode("\n", $lines);
    }

    public function onRequestSent(RequestAfterSendEvent $event)
    {
        $this->add($event->getRequest(), $event->getResponse());
    }

    public function onRequestError(RequestErrorEvent $event)
    {
        $this->add($event->getRequest(), $event->getResponse());
    }

    /**
     * Returns an Iterator that yields associative array values where each
     * associative array contains a 'request' and 'response' key.
     *
     * @return \Iterator
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->transactions);
    }

    /**
     * Get all of the requests sent through the plugin
     *
     * @return array
     */
    public function getRequests()
    {
        return array_map(function ($t) {
            return $t['request'];
        }, $this->transactions);
    }

    /**
     * Get the number of requests in the history
     *
     * @return int
     */
    public function count()
    {
        return count($this->transactions);
    }

    /**
     * Get the last request sent
     *
     * @return RequestInterface
     */
    public function getLastRequest()
    {
        return end($this->transactions)['request'];
    }

    /**
     * Get the last response in the history
     *
     * @return ResponseInterface|null
     */
    public function getLastResponse()
    {
        return end($this->transactions)['response'];
    }

    /**
     * Clears the history
     */
    public function clear()
    {
        $this->transactions = array();
    }

    /**
     * Add a request to the history
     *
     * @param RequestInterface  $request  Request to add
     * @param ResponseInterface $response Response of the request
     */
    private function add(RequestInterface $request, ResponseInterface $response = null)
    {
        $this->transactions[] = array('request' => $request, 'response' => $response);
        if (count($this->transactions) > $this->limit) {
            array_shift($this->transactions);
        }
    }
}