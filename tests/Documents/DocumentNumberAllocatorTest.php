<?php

declare(strict_types=1);

namespace App\Tests\Documents;

use App\Documents\DocumentNumberAllocator;
use App\Tests\Support\DatabaseTestCase;

final class DocumentNumberAllocatorTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['document_sequences'];
    }

    public function testFirstAllocationOfAYearIsNumberOne(): void
    {
        $alloc = new DocumentNumberAllocator($this->pdo);

        self::assertSame('2030-00001', $alloc->allocate(2030));
    }

    public function testAllocationsForTheSameYearAreSequentialWithNoGaps(): void
    {
        $alloc = new DocumentNumberAllocator($this->pdo);

        self::assertSame('2030-00001', $alloc->allocate(2030));
        self::assertSame('2030-00002', $alloc->allocate(2030));
        self::assertSame('2030-00003', $alloc->allocate(2030));
    }

    public function testDifferentYearsHaveIndependentSequences(): void
    {
        $alloc = new DocumentNumberAllocator($this->pdo);

        self::assertSame('2030-00001', $alloc->allocate(2030));
        self::assertSame('2031-00001', $alloc->allocate(2031)); // does not continue 2030's count
        self::assertSame('2030-00002', $alloc->allocate(2030));
    }

    public function testDigitWidthIsConfigurable(): void
    {
        $alloc = new DocumentNumberAllocator($this->pdo, digits: 3);

        self::assertSame('2030-001', $alloc->allocate(2030));
    }
}
