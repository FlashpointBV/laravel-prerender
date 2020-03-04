<?php

namespace Flashpoint\LaravelPrerender;

use Closure;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\HttpFoundation\Response;

class PrerenderMiddleware
{
    /**
     * The Guzzle Client that sends GET requests to the prerender server
     *
     * @var Guzzle
     */
    private $client;

    /**
     * This token will be provided via the X-Prerender-Token header.
     *
     * @var string
     */
    private $prerenderToken;

    /**
     * List of crawler user agents that will be
     *
     * @var array
     */
    private $crawlerUserAgents;

    /**
     * URI whitelist for prerendering pages only on this list
     *
     * @var array
     */
    private $whitelist;

    /**
     * URI blacklist for prerendering pages that are not on the list
     *
     * @var array
     */
    private $blacklist;

    /**
     * Base URI to make the prerender requests
     *
     * @var string
     */
    private $prerenderUri;

    /**
     * Return soft 3xx and 404 HTTP codes
     *
     * @var string
     */
    private $returnSoftHttpCodes;

    /**
     * Creates a new PrerenderMiddleware instance
     *
     * @param  Guzzle $client
     */
    public function __construct(Guzzle $client)
    {
        $this->returnSoftHttpCodes = config('prerender.prerender_soft_http_codes');

        if ($this->returnSoftHttpCodes) {
            $this->client = $client;
        } else {
            // Workaround to avoid following redirects
            $config = $client->getConfig();
            $config['allow_redirects'] = false;
            $this->client = new Guzzle($config);
        }

        $this->prerenderUri = config('prerender.prerender_url');
        $this->crawlerUserAgents = config('prerender.crawler_user_agents');
        $this->prerenderToken = config('prerender.prerender_token');
        $this->whitelist = config('prerender.whitelist');
        $this->blacklist = config('prerender.blacklist');
    }

    /**
     * Handles a request and prerender if it should, otherwise call the next middleware.
     *
     * @param  mixed $request
     * @param  Closure $next
     * @return Response
     */
    public function handle($request, Closure $next)
    {
        if ($this->shouldShowPrerenderedPage($request)) {
            $prerenderedResponse = $this->getPrerenderedPageResponse($request);

            if ($prerenderedResponse) {
                $statusCode = $prerenderedResponse->getStatusCode();

                if ( ! $this->returnSoftHttpCodes && $statusCode >= 300 && $statusCode < 400) {
                    return redirect()->to($prerenderedResponse->getHeaders()['Location'][0], $statusCode);
                }

                return $this->buildSymfonyResponseFromGuzzleResponse($prerenderedResponse);
            }
        }

        return $next($request);
    }

    /**
     * Returns whether the request must be prerendered.
     *
     * @param  mixed $request
     * @return bool
     */
    private function shouldShowPrerenderedPage($request)
    {
        $userAgent = strtolower($request->server->get('HTTP_USER_AGENT'));
        $bufferAgent = $request->server->get('X-BUFFERBOT');
        $requestUri = $request->getRequestUri();
        $referer = $request->headers->get('Referer');

        $isRequestingPrerenderedPage = false;

        if ( ! $userAgent) {
            return false;
        }

        if ( ! $request->isMethod('GET')) {
            return false;
        }

        // prerender if _escaped_fragment_ is in the query string
        if ($request->query->has('_escaped_fragment_')) {
            $isRequestingPrerenderedPage = true;
        }

        // prerender if a crawler is detected
        foreach ($this->crawlerUserAgents as $crawlerUserAgent) {
            if (Str::contains($userAgent, strtolower($crawlerUserAgent))) {
                $isRequestingPrerenderedPage = true;
            }
        }

        if ($bufferAgent) {
            $isRequestingPrerenderedPage = true;
        }

        if ( ! $isRequestingPrerenderedPage) {
            return false;
        }

        // only check whitelist if it is not empty
        if ($this->whitelist && ! $this->isListed($requestUri, $this->whitelist)) {
            return false;
        }

        // only check blacklist if it is not empty
        if ($this->blacklist) {
            $uris[] = $requestUri;

            // we also check for a blacklisted referer
            if ($referer) {
                $uris[] = $referer;
            }

            if ($this->isListed($uris, $this->blacklist)) {
                return false;
            }
        }

        // Okay! Prerender please.
        return true;
    }

    /**
     * Prerender the page and return the Guzzle Response
     *
     * @param  mixed $request
     * @return ResponseInterface|null
     */
    private function getPrerenderedPageResponse($request)
    {
        $headers = [
            'User-Agent' => $request->server->get('HTTP_USER_AGENT'),
        ];

        if ($this->prerenderToken) {
            $headers['X-Prerender-Token'] = $this->prerenderToken;
        }

        $protocol = $request->isSecure() ? 'https' : 'http';

        try {
            // Return the Guzzle Response
            $host = $request->getHost();
            $path = $request->Path();

            // Fix "//" 404 error
            if ($path === '/') {
                $path = '';
            }

            return $this->client->get($this->prerenderUri . '/' . urlencode($protocol . '://' . $host . '/' . $path), compact('headers'));
        } catch (RequestException $exception) {
            if (
                ! $this->returnSoftHttpCodes
                && ! $exception->getResponse()
                && $exception->getResponse()->getStatusCode() === 404
            ) {
                abort(404);
            }

            // In case of an exception, we only throw the exception if we are in debug mode. Otherwise,
            // we return null and the handle() method will just pass the request to the next middleware
            // and we do not show a prerendered page.
            if (config('app.debug')) {
                throw $exception;
            }
        }

        return null;
    }

    /**
     * Convert a Guzzle Response to a Symfony Response
     *
     * @param  ResponseInterface $prerenderedResponse
     * @return Response
     */
    private function buildSymfonyResponseFromGuzzleResponse(ResponseInterface $prerenderedResponse)
    {
        return (new HttpFoundationFactory)->createResponse($prerenderedResponse);
    }

    /**
     * Check whether one or more needles are in the given list
     *
     * @param  string|string[] $needles
     * @param  array $list
     * @return bool
     */
    private function isListed($needles, $list)
    {
        $needles = is_array($needles) ? $needles : [$needles];

        foreach ($list as $pattern) {
            foreach ($needles as $needle) {
                if (Str::is($pattern, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }
}
