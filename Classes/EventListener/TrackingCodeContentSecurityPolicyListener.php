<?php

declare(strict_types=1);

namespace T3G\Analytics\EventListener;

use T3G\Analytics\Configuration\ApiConfiguration;
use T3G\Analytics\Service\TrackingCodeResolver;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Event\PolicyMutatedEvent;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\HashValue;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Policy;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceKeyword;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;

/**
 * Allows the tracking code in the frontend CSP of sites with active tracking.
 *
 * TrackingCodeMiddleware adds the tracking code to the HTML response after
 * rendering, so the inline loader cannot use the nonce of the response. A hash
 * of the script content does not change between requests and also works for
 * responses from the page cache. main.js comes from the dashboard host and loads its chunks,
 * styles and images from there and sends all requests to that host.
 *
 * The listener only extends directives that are in effect in the policy. It does
 * not create new directives, because a new directive would block all other sources
 * of its type, for example all images of the site when the policy has no img-src
 * and no default-src.
 */
final readonly class TrackingCodeContentSecurityPolicyListener
{
    /**
     * The browser checks each of these resource types against its directive. When the
     * directive is missing, it uses the fallback directive, for example default-src.
     * When neither is set, the browser allows all sources of the type.
     * @see https://www.w3.org/TR/CSP3/#directive-fallback-list
     */
    private const INHERITING_DIRECTIVES = [Directive::ScriptSrc, Directive::StyleSrc, Directive::ImgSrc, Directive::ConnectSrc];

    /**
     * The browser uses these directives only when the policy sets them. Otherwise it
     * uses script-src and style-src, which the listener extends.
     */
    private const OWN_DIRECTIVES = [Directive::ScriptSrcElem, Directive::StyleSrcElem];

    public function __construct(
        private ApiConfiguration $apiConfiguration,
        private TrackingCodeResolver $trackingCodeResolver,
    ) {
    }

    public function __invoke(PolicyMutatedEvent $event): void
    {
        if (!$event->scope->type->isFrontend()) {
            return;
        }

        if ($event->request === null) {
            return;
        }
        $trackingCode = $this->trackingCodeResolver->resolve($event->request);
        if ($trackingCode === null) {
            return;
        }

        // additionalFrameSrc can contain http:// origins, for example a local dashboard in development.
        // An attacker in the network can change a script that comes over http://. The frontend policy
        // allows scripts from these origins on public pages, so it accepts only https:// origins.
        $origins = array_map(
            static fn (string $origin): UriValue => new UriValue($origin),
            array_filter(
                $this->apiConfiguration->getDashboardOrigins(),
                static fn (string $origin): bool => str_starts_with($origin, 'https://')
            )
        );
        $hashes = array_map(
            // The browser hashes the script text after it converted CRLF and CR to LF.
            static fn (string $script): HashValue => HashValue::hash(str_replace(["\r\n", "\r"], "\n", $script)),
            $this->extractInlineScripts($trackingCode)
        );

        $policy = $event->getCurrentPolicy();
        foreach ([...self::INHERITING_DIRECTIVES, ...self::OWN_DIRECTIVES] as $directive) {
            if (!$this->isInEffect($policy, $directive)) {
                continue;
            }
            $sources = $origins;
            if ($this->isScriptDirective($directive) && !$this->allowsUnsafeInline($policy, $directive)) {
                // A hash makes the browser ignore 'unsafe-inline', so only add hashes without it.
                $sources = [...$sources, ...$hashes];
            }
            $policy = $policy->extend($directive, ...$sources);
        }
        $event->setCurrentPolicy($policy);
    }

    private function isInEffect(Policy $policy, Directive $directive): bool
    {
        if ($policy->has($directive)) {
            return true;
        }
        if (in_array($directive, self::OWN_DIRECTIVES, true)) {
            return false;
        }
        foreach ($directive->getAncestors() as $ancestor) {
            if ($policy->has($ancestor)) {
                return true;
            }
        }
        return false;
    }

    private function isScriptDirective(Directive $directive): bool
    {
        return $directive === Directive::ScriptSrc || $directive === Directive::ScriptSrcElem;
    }

    private function allowsUnsafeInline(Policy $policy, Directive $directive): bool
    {
        return $policy->inherit($directive)->containsDirective($directive, SourceKeyword::unsafeInline);
    }

    /**
     * @return list<string>
     */
    private function extractInlineScripts(string $html): array
    {
        if (!preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $scripts = [];
        foreach ($matches as [, $attributes, $content]) {
            if ($content !== '' && !preg_match('#(?<![\w-])src\s*=#i', $attributes)) {
                $scripts[] = $content;
            }
        }
        return $scripts;
    }
}
