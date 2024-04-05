<?php

namespace Noxlogic\RateLimitBundle\Tests\Service;

use Noxlogic\RateLimitBundle\Service\RateLimitInfo;
use Noxlogic\RateLimitBundle\Tests\TestCase;

class RateLimitInfoTest extends TestCase
{

    public function testRateInfoSetters(): void
    {
        $rateInfo = new RateLimitInfo();

        $rateInfo->setLimit(1234);
        $this->assertEquals(1234, $rateInfo->getLimit());

        $rateInfo->setCalls(5);
        $this->assertEquals(5, $rateInfo->getCalls());

        $rateInfo->setResetTimestamp(100000);
        $this->assertEquals(100000, $rateInfo->getResetTimestamp());

        $this->assertFalse($rateInfo->isBlocked());

        $rateInfo->setBlocked(true);
        $this->assertTrue($rateInfo->isBlocked());

        $rateInfo->setKey('test');
        $this->assertEquals('test', $rateInfo->getKey());
    }

}
