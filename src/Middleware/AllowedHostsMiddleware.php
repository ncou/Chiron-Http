<?php

declare(strict_types=1);

namespace Chiron\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Chiron\Http\Helper\Uri;
use Chiron\Core\Config\SettingsConfig;
use Chiron\Http\Config\HttpConfig;
use Chiron\Http\Exception\DisallowedHostException;
use Chiron\Http\Exception\SuspiciousOperationException;

/**
 * Allowed Hosts verification.
 * To avoid host header injection attacks, you should provide a list of allowed hosts.
 */
final class AllowedHostsMiddleware implements MiddlewareInterface
{
	/** @var array */
	private $allowedHosts;

    /**
     * @param HttpConfig $httpConfig
     * @param SettingsConfig   $settingsConfig
     */
    public function __construct(HttpConfig $httpConfig, SettingsConfig $settingsConfig)
    {
        $this->allowedHosts = $httpConfig->getAllowedHosts();

        // Allow variants of localhost if ALLOWED_HOSTS list is empty and DEBUG is enabled.
        if ($settingsConfig->isDebug() && $this->allowedHosts === []) {
            // localhost and subdomain / IPv4 / IPv6 (brackets for URI use)
            $this->allowedHosts = ['.localhost', '127.0.0.1', '[::1]'];
        }
    }

    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface   $handler
     *
     * @throws DisallowedHostException In case the Host header is not present in the whitelist.
     *
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Retrieve the hostname (without the trailing dot).
        $host = $this->getHost($request);

        // Check the 'Host' name value with the whitelist.
        if (! $this->isAllowedHost($host)) {
            // Represents an http 400 error code (bad request).
        	throw new DisallowedHostException($host);
        }

        return $handler->handle($request);
    }

    /**
     * Returns the lowercase host name (no port).
     *
     * If no host is present, this method return an empty string.
     * The host is already normalized to lowercase, as per RFC 3986 Section 3.1.
     * Trailing dot is removed (presents in an 'absolute domain name').
     *
     * @see http://www.dns-sd.org/trailingdotsindomainnames.html
     *
     * @param ServerRequestInterface $request
     *
     * @return string
     */
    // TODO : ajouter un test pour virer le trailing dot : https://github.com/django/django/blob/a948d9df394aafded78d72b1daa785a0abfeab48/tests/requests/tests.py#L814
    // TODO : gérer les domains unicode (via le punny code) => https://github.com/ncou/Chiron-Middlewares/blob/master/src/Chiron/Http/Middleware/ReferralSpamMiddleware.php#L72
    private function getHost(ServerRequestInterface $request): string
    {
        $host = $request->getUri()->getHost();

        // Remove a trailing dot (if present) from the domain.
        if (substr($host, -1) === '.') { // TODO : utiliser la classe Suppor\Str::class pour la méthode : Str::endsWith($host, '.')
            $host = substr($host, 0, -1);
        }

        return $host;
    }

    /**
     * Validate the given host for this site.
     *
     * Check that the host matches a host or host pattern in the given list of 'allowed_hosts'.
     *
     * - Any pattern beginning with a period matches a domain and all its subdomains
     * (e.g. '.example.com' matches 'example.com' and any subdomain),
     * - the pattern '*' matches anything,
     * - and anything else must match exactly.
     *
     * @param string $host
     *
     * @return bool 'True' for a valid host, 'False' otherwise.
     */
    private function isAllowedHost(string $host): bool
    {
        // TODO : gérer les domains unicode (via le punny code) => https://github.com/ncou/Chiron-Middlewares/blob/master/src/Chiron/Http/Middleware/ReferralSpamMiddleware.php#L72
    	foreach ($this->allowedHosts as $pattern) {
    		if ($pattern === '*' || Uri::isSameDomain($host, $pattern)) {
    			return true;
    		}
    	}

    	return false;
    }
}
