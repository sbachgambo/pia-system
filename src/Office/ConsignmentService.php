<?php

declare(strict_types=1);

namespace App\Office;

use App\Http\Support\Input;
use App\Http\Support\Pagination;
use App\Support\ApiException;
use Ramsey\Uuid\Uuid;

/**
 * Business rules + validation for `consignments`.
 *
 * The NXP number is the key that identifies a transaction (client decision,
 * Sep 2026). Rules enforced here:
 *  - an export must carry one, and no two records may share it (the schema's
 *    unique index backs this up against a race);
 *  - an import must not carry one (brief §4 — NXP is an export form).
 * Existing exports saved before the rule keep working; they just have to be
 * given a number the next time they are edited.
 */
final class ConsignmentService
{
    private const DIRECTIONS = ['export', 'import'];

    public function __construct(
        private readonly ConsignmentRepository $consignments,
        private readonly ClientRepository $clients,
    ) {
    }

    /** @return array<string,mixed> */
    public function create(Input $in): array
    {
        $clientUuid = $in->requiredString('client_uuid', 36);
        $clientId = $this->clients->findIdByUuid($clientUuid)
            ?? throw new ApiException(422, 'validation_failed', 'Unknown client_uuid.', ['field' => 'client_uuid']);

        $data = $this->validateFields($in, isCreate: true);
        $this->assertNxpUnique($data['form_nxp_number'] ?? null, null);

        return Representation::consignment(
            $this->consignments->create(Uuid::uuid4()->toString(), $clientId, $data),
        );
    }

    /** @return array<string,mixed> */
    public function update(string $uuid, Input $in): array
    {
        $existing = $this->consignments->findByUuid($uuid) ?? throw new NotFoundException('Consignment');
        $id = (int) $existing['id'];

        $data = [];

        if ($in->has('client_uuid')) {
            $clientUuid = $in->requiredString('client_uuid', 36);
            $data['client_id'] = $this->clients->findIdByUuid($clientUuid)
                ?? throw new ApiException(422, 'validation_failed', 'Unknown client_uuid.', ['field' => 'client_uuid']);
        }

        $data += $this->validateFields($in, isCreate: false);

        // Direction after the update (new value if supplied, else current).
        $direction = $data['direction'] ?? (string) $existing['direction'];
        $nxp = array_key_exists('form_nxp_number', $data)
            ? $data['form_nxp_number']
            : $existing['form_nxp_number'];
        $this->assertNxpMatchesDirection($direction, $nxp);
        $this->assertNxpUnique($nxp, $id);

        return Representation::consignment($this->consignments->update($id, $data));
    }

    /** @return array<string,mixed> */
    public function get(string $uuid): array
    {
        $row = $this->consignments->findByUuid($uuid) ?? throw new NotFoundException('Consignment');

        return Representation::consignment($row);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function list(Pagination $page, array $filters): array
    {
        $result = $this->consignments->paginate($page->perPage, $page->offset(), $filters);

        return $page->envelope(
            array_map([Representation::class, 'consignment'], $result['rows']),
            $result['total'],
        );
    }

    /**
     * @return array<string,mixed> column-keyed values ready for the repository
     */
    private function validateFields(Input $in, bool $isCreate): array
    {
        $data = [];
        $req = static fn (string $k): bool => $isCreate || $in->has($k);

        if ($req('direction'))           { $data['direction'] = $in->requiredEnum('direction', self::DIRECTIONS); }
        if ($req('product_category'))    { $data['product_category'] = $in->requiredString('product_category', 150); }
        if ($req('product_description')) { $data['product_description'] = $in->requiredString('product_description', 20000); }
        if ($req('hs_code'))             { $data['hs_code'] = $in->optionalString('hs_code', 20); }
        if ($req('quantity'))            { $data['quantity'] = $this->money($in->requiredNumber('quantity', 0)); }
        if ($req('unit_of_measure'))     { $data['unit_of_measure'] = $in->requiredString('unit_of_measure', 20); }
        if ($req('declared_value'))      { $data['declared_value'] = $this->money($in->requiredNumber('declared_value', 0)); }
        if ($req('currency'))            { $data['currency'] = $in->requiredCurrency('currency'); }
        if ($req('origin_country'))      { $data['origin_country'] = $in->requiredString('origin_country', 100); }
        if ($req('destination_country')) { $data['destination_country'] = $in->requiredString('destination_country', 100); }
        if ($req('zone'))                { $data['zone'] = $in->requiredString('zone', 100); }
        if ($isCreate || $in->has('form_nxp_number')) {
            $data['form_nxp_number'] = $in->optionalString('form_nxp_number', 50);
        }

        if ($isCreate) {
            $this->assertNxpMatchesDirection($data['direction'], $data['form_nxp_number'] ?? null);
        }

        return $data;
    }

    private function assertNxpMatchesDirection(string $direction, ?string $nxp): void
    {
        $hasNxp = $nxp !== null && $nxp !== '';

        if ($direction === 'import' && $hasNxp) {
            throw new ApiException(
                422,
                'validation_failed',
                'An NXP number applies to exports only.',
                ['field' => 'form_nxp_number'],
            );
        }
        if ($direction === 'export' && !$hasNxp) {
            throw new ApiException(
                422,
                'validation_failed',
                'An export must have an NXP number.',
                ['field' => 'form_nxp_number'],
            );
        }
    }

    private function assertNxpUnique(?string $nxp, ?int $exceptId): void
    {
        if ($nxp === null || $nxp === '') {
            return;
        }

        if ($this->consignments->nxpNumberTaken($nxp, $exceptId)) {
            throw new ApiException(
                422,
                'validation_failed',
                "NXP number {$nxp} is already recorded on another NXP record.",
                ['field' => 'form_nxp_number'],
            );
        }
    }

    /** Keep 2-dp money/quantity as an exact string for the DECIMAL column. */
    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
