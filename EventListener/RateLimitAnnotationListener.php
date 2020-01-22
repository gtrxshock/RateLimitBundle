<?php

namespace Noxlogic\RateLimitBundle\EventListener;

use Noxlogic\RateLimitBundle\Annotation\RateLimit;
use Noxlogic\RateLimitBundle\Events\BlockEvent;
use Noxlogic\RateLimitBundle\Events\CheckedRateLimitEvent;
use Noxlogic\RateLimitBundle\Events\GenerateKeyEvent;
use Noxlogic\RateLimitBundle\Events\GetResponseEvent;
use Noxlogic\RateLimitBundle\Events\RateLimitEvents;
use Noxlogic\RateLimitBundle\Service\RateLimitService;
use Noxlogic\RateLimitBundle\Util\PathLimitProcessor;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;

class RateLimitAnnotationListener extends BaseListener
{

    /**
     * @var eventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var \Noxlogic\RateLimitBundle\Service\RateLimitService
     */
    protected $rateLimitService;

    /**
     * @var \Noxlogic\RateLimitBundle\Util\PathLimitProcessor
     */
    protected $pathLimitProcessor;

    /**
     * @param RateLimitService                    $rateLimitService
     */
    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        RateLimitService $rateLimitService,
        PathLimitProcessor $pathLimitProcessor
    ) {
        //todo:use an event dispatcher passed into onKernelController
        $this->eventDispatcher = $eventDispatcher;
        $this->rateLimitService = $rateLimitService;
        $this->pathLimitProcessor = $pathLimitProcessor;
    }

    /**
     * @param FilterControllerEvent $event
     */
    public function onKernelController(FilterControllerEvent $event)
    {
        // Skip if the bundle isn't enabled (for instance in test environment)
        if( ! $this->getParameter('enabled', true)) {
            return;
        }

        // Skip if we aren't the main request
        if ($event->getRequestType() != HttpKernelInterface::MASTER_REQUEST) {
            return;
        }

        // Find the best match
        $annotations = $event->getRequest()->attributes->get('_x-rate-limit', array());
        $rateLimit = $this->findBestMethodMatch($event->getRequest(), $annotations);

        // Another treatment before applying RateLimit ?
        $checkedRateLimitEvent = new CheckedRateLimitEvent($event->getRequest(), $rateLimit);
        $this->eventDispatcher->dispatch($checkedRateLimitEvent, RateLimitEvents::CHECKED_RATE_LIMIT);
        $rateLimit = $checkedRateLimitEvent->getRateLimit();

        // No matching annotation found
        if (! $rateLimit) {
            return;
        }

        $key = $this->getKey($event, $rateLimit, $annotations);

        // Ratelimit the call
        $rateLimitInfo = $this->rateLimitService->limitRate($key);
        if (! $rateLimitInfo) {
            // Create new rate limit entry for this call
            $rateLimitInfo = $this->rateLimitService->createRate($key, $rateLimit->getLimit(), $rateLimit->getPeriod());
            if (! $rateLimitInfo) {
                // @codeCoverageIgnoreStart
                return;
                // @codeCoverageIgnoreEnd
            }
        }


        // Store the current rating info in the request attributes
        $request = $event->getRequest();
        $request->attributes->set('rate_limit_info', $rateLimitInfo);

        // Reset the rate limits
        if(!$rateLimitInfo->isBlocked() && time() >= $rateLimitInfo->getResetTimestamp()) {
            $this->rateLimitService->resetRate($key);
            $rateLimitInfo = $this->rateLimitService->createRate($key, $rateLimit->getLimit(), $rateLimit->getPeriod());
            if (! $rateLimitInfo) {
                // @codeCoverageIgnoreStart
                return;
                // @codeCoverageIgnoreEnd
            }
        }

        // When we exceeded our limit, return a custom error response
        if (!$rateLimitInfo->isBlocked() && $rateLimitInfo->getCalls() > $rateLimitInfo->getLimit()) {
            $this->rateLimitService->setBlock(
                $rateLimitInfo,
                $rateLimit->getBlockPeriod() > 0 ? $rateLimit->getBlockPeriod() : $rateLimit->getPeriod()
            );
            $this->eventDispatcher->dispatch(new BlockEvent($rateLimitInfo, $request), RateLimitEvents::BLOCK_AFTER);
        }

        if ($rateLimitInfo->isBlocked()) {
            // Throw an exception if configured.
            if ($this->getParameter('rate_response_exception')) {
                $class = $this->getParameter('rate_response_exception');
                throw new $class($this->getParameter('rate_response_message'), $this->getParameter('rate_response_code'));
            }

            $response = new Response(
                $this->getParameter('rate_response_message'),
                $this->getParameter('rate_response_code')
            );

            $eventResponse = new GetResponseEvent($request, $rateLimitInfo);
            $this->eventDispatcher->dispatch($eventResponse, RateLimitEvents::RESPONSE_SENDING_BEFORE);
            if ($eventResponse->hasResponse()) {
                $response = $eventResponse->getResponse();
            }

            $event->setController(function () use ($response) {
                // @codeCoverageIgnoreStart
                return $response;
                // @codeCoverageIgnoreEnd
            });
            $event->stopPropagation();
        }

    }


    /**
     * @param RateLimit[] $annotations
     */
    protected function findBestMethodMatch(Request $request, array $annotations)
    {
        // Empty array, check the path limits
        if (count($annotations) == 0) {
            return $this->pathLimitProcessor->getRateLimit($request);
        }

        $best_match = null;
        foreach ($annotations as $annotation) {
            // cast methods to array, even method holds a string
            $methods = is_array($annotation->getMethods()) ? $annotation->getMethods() : array($annotation->getMethods());

            if (in_array($request->getMethod(), $methods)) {
                $best_match = $annotation;
            }

            // Only match "default" annotation when we don't have a best match
            if (count($annotation->getMethods()) == 0 && $best_match == null) {
                $best_match = $annotation;
            }
        }

        return $best_match;
    }

    private function getKey(FilterControllerEvent $event, RateLimit $rateLimit, array $annotations)
    {
        // Let listeners manipulate the key
        $keyEvent = new GenerateKeyEvent($event->getRequest());

        $rateLimitMethods = join('.', $rateLimit->getMethods());
        $keyEvent->addToKey($rateLimitMethods);

        $rateLimitAlias = count($annotations) === 0
            ? str_replace('/', '.', $this->pathLimitProcessor->getMatchedPath($event->getRequest()))
            : $this->getAliasForRequest($event);
        $keyEvent->addToKey($rateLimitAlias);

        $this->eventDispatcher->dispatch($keyEvent, RateLimitEvents::GENERATE_KEY);

        return $keyEvent->getKey();
    }

    private function getAliasForRequest(FilterControllerEvent $event)
    {
        if (($route = $event->getRequest()->attributes->get('_route'))) {
            return $route;
        }

        $controller = $event->getController();

        if (is_string($controller) && false !== strpos($controller, '::')) {
            $controller = explode('::', $controller);
        }

        if (is_array($controller)) {
            return str_replace('\\', '.', is_string($controller[0]) ? $controller[0] : get_class($controller[0])) . '.' . $controller[1];
        }

        if ($controller instanceof \Closure) {
            return 'closure';
        }

        if (is_object($controller)) {
            return str_replace('\\', '.', get_class($controller[0]));
        }

        return 'other';
    }
}
