<?php

namespace Noxlogic\RateLimitBundle\Events;

final class RateLimitEvents
{
    /**
     * This event is dispatched when generating a key is doing
     */
    public const GENERATE_KEY = 'ratelimit.generate.key';

    /**
     * This event is dispatched after a block happened
     */
    public const BLOCK_AFTER = 'ratelimit.block.after';

    /**
     * This event is dispatched before response is sent
     */
    public const RESPONSE_SENDING_BEFORE = 'ratelimit.response.sending.before';

    public const CHECKED_RATE_LIMIT = 'ratelimit.checked.ratelimit';
}
