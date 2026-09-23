<?php

declare(strict_types=1);

namespace App\Tests\Documents;

use App\Documents\DocumentRepository;
use App\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;

/**
 * One live certificate per inspection: a new one voids the one it replaces,
 * so a corrected CCI (or a switch to an NNCI) is never invoiced twice.
 */
final class DocumentRepositoryTest extends DatabaseTestCase
{
    private DocumentRepository $docs;
    private int $inspectionId;
    private int $otherInspectionId;

    protected function dirtyTables(): array
    {
        return ['documents', 'inspections', 'inspection_requests', 'consignments', 'clients', 'users'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->docs = new DocumentRepository($this->pdo);
        $staff = $this->makeUser(['role' => 'office_reviewer'])['id'];
        $inspector = $this->makeUser(['role' => 'inspector'])['id'];
        $client = $this->makeClientRow()['id'];
        foreach (['inspectionId', 'otherInspectionId'] as $i => $prop) {
            $cons = $this->makeConsignmentRow($client, ['form_nxp_number' => 'DOC-REPO-' . $i]);
            $req = $this->makeInspectionRequestRow($cons['id'], $staff);
            $this->{$prop} = $this->makeScheduledInspectionRow($req['id'], $inspector, ['status' => 'finalized'])['id'];
        }
    }

    private function issue(int $inspectionId, string $type, string $number): array
    {
        return $this->docs->insert(Uuid::uuid4()->toString(), $inspectionId, null, $type, $number, 'documents/x.pdf',
            str_repeat('a', 64), str_repeat('b', 64), new DateTimeImmutable('2026-09-22 10:00:00'));
    }

    /** @return array<string,string> number => status */
    private function statuses(): array
    {
        return $this->pdo->query('SELECT document_number, status FROM documents ORDER BY id')->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    public function testANewCertificateReplacesTheCurrentOne(): void
    {
        $this->issue($this->inspectionId, 'CCI', 'R-1');
        $this->issue($this->otherInspectionId, 'CCI', 'R-OTHER');
        $second = $this->issue($this->inspectionId, 'CCI', 'R-2');
        $this->issue($this->inspectionId, 'NNCI', 'R-3');

        self::assertSame('issued', $second['status'], 'the one just made is live when made');
        self::assertSame(
            ['R-1' => 'void', 'R-OTHER' => 'issued', 'R-2' => 'void', 'R-3' => 'issued'],
            $this->statuses(),
            'only the newest per inspection stays issued; other inspections are untouched',
        );
    }
}
