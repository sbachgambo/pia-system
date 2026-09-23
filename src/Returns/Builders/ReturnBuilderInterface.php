<?php

declare(strict_types=1);

namespace App\Returns\Builders;

/** One builder per agency layout — turns assembled register rows into a CSV. */
interface ReturnBuilderInterface
{
    /** @param list<array<string,mixed>> $rows from ReturnRowAssembler::rowsForPeriod() */
    public function build(array $rows): string;
}
