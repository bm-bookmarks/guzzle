<?php

namespace Guzzle\Http\Subscriber;

use Guzzle\Http\Event\RequestAfterSendEvent;
use Guzzle\Http\Exception\TooManyRedirectsException;
use Guzzle\Http\Exception\CouldNotRewindStreamException;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\ResponseInterface;
use Guzzle\Url\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plugin to implement HTTP redirects. Can redirect like a web browser or using strict RFC 2616 compliance
 */
class Redirect implements EventSubscriberInterface
{
    const STRICT_REDIRECTS = 'strict_redirects';
    const MAX_REDIRECTS = 'max_redirects';

    public static function getSubscribedEvents()
    {
        return ['request.after_send' => 'onRequestSent'];
    }

    /**
     * Called when a request receives a redirect response
     *
     * @param RequestAfterSendEvent $event Event emitted
     * @throws TooManyRedirectsException
     */
    public function onRequestSent(RequestAfterSendEvent $event)
    {
        $request = $event->getRequest();
        $redirectCount = 0;
        $redirectResponse = $response = $event->getResponse();
        $max = $request->getConfig()->get(self::MAX_REDIRECTS) ?: 5;

        while (substr($redirectResponse->getStatusCode(), 0, 1) == '3' && $redirectResponse->hasHeader('Location')) {
            if (++$redirectCount > $max) {
                throw new TooManyRedirectsException("Will not follow more than {$redirectCount} redirects", $request);
            }
            $redirectRequest = $this->createRedirectRequest($request, $redirectResponse);
            $redirectResponse = $event->getClient()->send($redirectRequest);
        }

        if ($redirectResponse !== $response) {
            $event->intercept($redirectResponse);
        }
    }

    /**
     * Create a redirect request for a specific request object
     *
     * Takes into account strict RFC compliant redirection (e.g. redirect POST with POST) vs doing what most clients do
     * (e.g. redirect POST with GET).
     *
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     *
     * @return RequestInterface Returns a new redirect request
     * @throws CouldNotRewindStreamException If the body needs to be rewound but cannot
     */
    private function createRedirectRequest(RequestInterface $request, ResponseInterface $response)
    {
        $strict = $request->getConfig()[self::STRICT_REDIRECTS];

        // Use a GET request if this is an entity enclosing request and we are not forcing RFC compliance, but rather
        // emulating what all browsers would do. Be sure to disable redirects on the clone.
        $redirectRequest = clone $request;
        $redirectRequest->getEventDispatcher()->removeSubscriber($this);
        if ($request->getBody() && !$strict && $response->getStatusCode() <= 302) {
            $redirectRequest->setMethod('GET');
            $redirectRequest->setBody(null);
        }

        $this->setRedirectUrl($redirectRequest, $response);
        $this->rewindEntityBody($redirectRequest);

        return $redirectRequest;
    }

    /**
     * Set the appropriate URL on the request based on the location header
     *
     * @param RequestInterface  $redirectRequest
     * @param ResponseInterface $response
     */
    private function setRedirectUrl(RequestInterface $redirectRequest, ResponseInterface $response)
    {
        $location = $response->getHeader('Location');
        $location = Url::fromString($location);

        // If the location is not absolute, then combine it with the original URL
        if (!$location->isAbsolute()) {
            $originalUrl = Url::fromString($redirectRequest->getUrl());
            // Remove query string parameters and just take what is present on the redirect Location header
            $originalUrl->getQuery()->clear();
            $location = $originalUrl->combine((string) $location);
        }

        $redirectRequest->setUrl($location);
    }

    /**
     * Rewind the entity body of the request if needed
     *
     * @param RequestInterface $redirectRequest
     * @throws CouldNotRewindStreamException
     */
    private function rewindEntityBody(RequestInterface $redirectRequest)
    {
        // Rewind the entity body of the request if needed
        if ($redirectRequest->getBody()) {
            $body = $redirectRequest->getBody();
            // Only rewind the body if some of it has been read already, and throw an exception if the rewind fails
            if ($body->tell() && !$body->seek(0)) {
                throw new CouldNotRewindStreamException(
                    'Unable to rewind the non-seekable entity body of the request after redirecting',
                    $redirectRequest
                );
            }
        }
    }
}