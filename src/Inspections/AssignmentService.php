<?php

declare(strict_types=1);

namespace App\Inspections;

use PDO;

/**
 * Builds the payload for GET /api/inspections/assigned — the inspector's
 * working set for offline caching (brief §5/§6 `reference_cache`).
 *
 * Each inspection carries its consignment context and any findings/attachments
 * already on file, plus a `checklist` block. The checklist is a placeholder
 * until Q7 (product-category-specific checklist content) is confirmed — today
 * it is an empty template the device renders as free-form rows.
 */
final class AssignmentService
{
    public function __construct(
        private readonly InspectionRepository $inspections,
        private readonly FindingRepository $findings,
        private readonly AttachmentRepository $attachments,
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function forInspector(int $inspectorId): array
    {
        $rows = $this->inspections->listAssignedTo($inspectorId);

        $items = array_map(function (array $row): array {
            $id = (int) $row['id'];
            $payload = Representation::inspection(
                $row,
                $this->findings->forInspection($id),
                $this->attachments->forInspection($id),
            );
            $payload['consignment'] = $this->consignmentContext((string) $row['consignment_uuid']);
            $payload['checklist']   = $this->checklistFor($payload['consignment']['product_category'] ?? null);

            return $payload;
        }, $rows);

        return [
            'server_time' => gmdate('c'),
            'inspections' => $items,
        ];
    }

    /** @return array<string,mixed> */
    private function consignmentContext(string $consignmentUuid): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.uuid, c.direction, c.product_category, c.product_description, c.hs_code, c.quantity,
                    c.unit_of_measure, c.declared_value, c.currency, c.origin_country, c.destination_country, c.zone,
                    c.form_nxp_number, cl.name AS client_name
               FROM consignments c JOIN clients cl ON cl.id = c.client_id
              WHERE c.uuid = :u LIMIT 1'
        );
        $stmt->execute(['u' => $consignmentUuid]);
        $row = $stmt->fetch();

        return $row === false ? [] : [
            'uuid'                => (string) $row['uuid'],
            'nxp_number'          => $row['form_nxp_number'] !== null ? (string) $row['form_nxp_number'] : null,
            'client_name'         => (string) $row['client_name'],
            'direction'           => (string) $row['direction'],
            'product_category'    => (string) $row['product_category'],
            'product_description' => (string) $row['product_description'],
            'hs_code'             => $row['hs_code'] !== null ? (string) $row['hs_code'] : null,
            'quantity'            => (string) $row['quantity'],
            'unit_of_measure'     => (string) $row['unit_of_measure'],
            'declared_value'      => (string) $row['declared_value'],
            'currency'            => (string) $row['currency'],
            'origin_country'      => (string) $row['origin_country'],
            'destination_country' => (string) $row['destination_country'],
            'zone'                => (string) $row['zone'],
        ];
    }

    /**
     * Placeholder checklist template (Q7). Returns an empty, free-form
     * structure; once real per-category checklists exist this method looks
     * them up and the sync path validates against them.
     *
     * @return array<string,mixed>
     */
    private function checklistFor(?string $productCategory): array
    {
        return [
            'product_category' => $productCategory,
            'version'          => 'freeform-1',
            'items'            => [], // device shows free-form finding rows
        ];
    }
}
