<?php

declare(strict_types=1);

namespace App\Returns;

use App\Http\Support\Pagination;
use App\Office\NotFoundException;
use App\Returns\Builders\CbnReturnBuilder;
use App\Returns\Builders\GenericReturnBuilder;
use App\Returns\Builders\NbsReturnBuilder;
use App\Returns\Builders\ReturnBuilderInterface;
use App\Support\ApiException;
use DateTimeImmutable;
use DateTimeZone;
use Ramsey\Uuid\Uuid;

/**
 * Orchestrates statutory return generation (brief §4/§5/§8, D3).
 *
 * CBN and NBS have a real builder matched to the client's own sample layouts
 * (DEV_NOTES, Phase 7). NEPC/MOF/CUSTOMS fall back to GenericReturnBuilder —
 * a reasonable core register, not a guess at agency-specific columns — until
 * a real layout is confirmed (Q6, still partially open).
 */
final class StatutoryReturnService
{
    private const AGENCIES = ['CBN', 'NEPC', 'NBS', 'MOF', 'CUSTOMS'];

    public function __construct(
        private readonly ReturnRowAssembler $assembler,
        private readonly StatutoryReturnRepository $returns,
        private readonly StatutoryReturnStorage $storage,
    ) {
    }

    /** @return array<string,mixed> */
    public function generate(string $agency, DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd, ?int $actorId): array
    {
        if (!in_array($agency, self::AGENCIES, true)) {
            throw new ApiException(422, 'validation_failed', 'Unknown agency.', ['field' => 'agency']);
        }
        if ($periodEnd < $periodStart) {
            throw new ApiException(422, 'validation_failed', '`period_end` must not be before `period_start`.', ['field' => 'period_end']);
        }

        $rows = $this->assembler->rowsForPeriod($periodStart, $periodEnd->modify('+1 day'));
        $csv = $this->builderFor($agency)->build($rows);

        $uuid = Uuid::uuid4()->toString();
        $stored = $this->storage->put($uuid, $csv, 'csv');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $row = $this->returns->insert(
            $uuid,
            $agency,
            $periodStart,
            $periodEnd,
            $stored['relative_path'],
            'csv',
            count($rows),
            $actorId,
            $now,
        );

        return Representation::statutoryReturn($row);
    }

    /**
     * @param array{agency?:string, status?:string} $filters
     * @return array<string,mixed>
     */
    public function list(Pagination $page, array $filters): array
    {
        $result = $this->returns->paginate($page->perPage, $page->offset(), $filters);

        return $page->envelope(array_map([Representation::class, 'statutoryReturn'], $result['rows']), $result['total']);
    }

    /** @return array<string,mixed> */
    public function submit(string $uuid, int $actorId): array
    {
        $row = $this->returns->findByUuid($uuid) ?? throw new NotFoundException('Statutory return');

        if ($row['status'] === 'submitted') {
            throw new ApiException(422, 'already_submitted', 'This return is already marked submitted.');
        }

        $updated = $this->returns->markSubmitted((int) $row['id'], $actorId, new DateTimeImmutable('now', new DateTimeZone('UTC')));

        return Representation::statutoryReturn($updated);
    }

    /** @return array{bytes:string, filename:string} */
    public function download(string $uuid): array
    {
        $row = $this->returns->findByUuid($uuid) ?? throw new NotFoundException('Statutory return');
        $bytes = $this->storage->read((string) $row['file_path'])
            ?? throw new ApiException(410, 'file_missing', 'The return file is no longer available.');

        $filename = sprintf('%s_%s_%s.csv', $row['agency'], $row['period_start'], $row['period_end']);

        return ['bytes' => $bytes, 'filename' => $filename];
    }

    private function builderFor(string $agency): ReturnBuilderInterface
    {
        return match ($agency) {
            'CBN' => new CbnReturnBuilder(),
            'NBS' => new NbsReturnBuilder(),
            default => new GenericReturnBuilder(),
        };
    }
}
