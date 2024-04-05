<?php

namespace Noxlogic\RateLimitBundle\Tests\Attribute;

use Noxlogic\RateLimitBundle\Attribute\RateLimit;
use Noxlogic\RateLimitBundle\Tests\TestCase;

class RateLimitTest extends TestCase
{
    public function testConstruction(): void
    {
        $attribute = new RateLimit();

        $this->assertEquals(-1, $attribute->limit);
        $this->assertEmpty($attribute->methods);
        $this->assertEquals(3600, $attribute->period);
        $this->assertEquals(0, $attribute->blockPeriod);
    }

    public function testConstructionWithValues(): void
    {
        $attribute = new RateLimit(
            [],
            1234,
            1000,
            null,
            7200
        );
        $this->assertEquals(1234, $attribute->limit);
        $this->assertEquals(1000, $attribute->period);
        $this->assertEquals(7200, $attribute->blockPeriod);

        $attribute = new RateLimit(
            ['POST'],
            1234,
            1000,
            null,
            7200
        );
        $this->assertEquals(1234, $attribute->limit);
        $this->assertEquals(1000, $attribute->period);
        $this->assertEquals(['POST'], $attribute->methods);
        $this->assertEquals(7200, $attribute->blockPeriod);
    }

    public function testConstructionWithMethods(): void
    {
        $attribute = new RateLimit(
            ['POST', 'GET'],
            1234,
            1000
        );
        $this->assertCount(2, $attribute->methods);
    }

    public function testConstructWithStringAsMethods(): void
    {
        $attribute = new RateLimit(
            'POST',
            1234,
            1000
        );
        $this->assertEquals(['POST'], $attribute->methods);
    }
}
