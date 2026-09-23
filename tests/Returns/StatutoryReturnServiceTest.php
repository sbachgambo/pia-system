<?php

declare(strict_types=1);

namespace App\Tests\Returns;

use App\Office\NotFoundException;
use App\Returns\ReturnRowAssembler;
use App\Returns\StatutoryReturnRepository;
use App\Returns\StatutoryReturnService;
use App\Returns\StatutoryReturnStorage;
use App\Support\ApiException;
use App\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;

final class StatutoryReturnServiceTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['statutory_returns', 'documents', 'inspection_shipment_details', 'consignment_trade_details',
                'inspections', 'inspection_requests', 'consignments', 'clients', 'users'];
    }

    private function service(): StatutoryReturnService
    {
        $tmp = sys_get_temp_dir() . '/pia_test_returns_' . getmypid();

        return new StatutoryReturnService(
            new ReturnRowAssembler($this->pdo),
            new StatutoryReturnRepository($this->pdo),
            new StatutoryReturnStorage($tmp),
        );
    }

    /**
     * Inserts a finalized inspection + an already-`issued` CCI document,
     * bypassing mPDF (unit-testing the return builder, not PDF rendering —
     * DocumentServiceTest already covers that mPDF actually runs).
     */
    private function issuedDocument(DateTimeImmutable $issuedAt, array $consignmentOverrides = []): string
    {
        $inspector = $this->makeUser(['role' => 'inspector']);
        $office = $this->makeUser(['role' => 'office_reviewer']);
        $client = $this->makeClientRow(['name' => 'Tiger Foods Limited']);
        $cons = $this->makeConsignmentRow($client['id'], $consignmentOverrides + [
            'declared_value' => '10000.00', 'currency' => 'USD', 'quantity' => '100.00',
        ]);
        $req = $this->makeInspectionRequestRow($cons['id'], $office['id'], ['status' => 'scheduled']);
        $insp = $this->makeScheduledInspectionRow($req['id'], $inspector['id'], [
            'status' => 'finalized', 'synced_at' => gmdate('Y-m-d H:i:s'), 'started_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->pdo->prepare('UPDATE inspections SET finalized_at = UTC_TIMESTAMP(), finalized_by = :by WHERE id = :id')
            ->execute(['by' => $office['id'], 'id' => $insp['id']]);

        $docUuid = Uuid::uuid4()->toString();
        $this->pdo->prepare(
            "INSERT INTO documents (uuid, inspection_id, issued_by, type, document_number, file_path,
                content_hash, hmac_signature, issued_at, status, created_at, updated_at)
             VALUES (:uuid, :insp, :by, 'CCI', :num, 'documents/x.pdf', :hash, :sig, :issued, 'issued',
                     UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([
            'uuid'   => $docUuid,
            'insp'   => $insp['id'],
            'by'     => $office['id'],
            'num'    => '2026-' . substr(str_replace('-', '', $docUuid), 0, 5),
            'hash'   => str_repeat('a', 64),
            'sig'    => str_repeat('b', 64),
            'issued' => $issuedAt->format('Y-m-d H:i:s'),
        ]);

        return $docUuid;
    }

    public function testGenerateProducesACsvCoveringOnlyTheRequestedPeriod(): void
    {
        $this->issuedDocument(new DateTimeImmutable('2026-01-15'));
        $this->issuedDocument(new DateTimeImmutable('2026-02-15')); // outside the January period below

        $doc = $this->service()->generate('CBN', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), null);

        self::assertSame('CBN', $doc['agency']);
        self::assertSame(1, $doc['row_count']);
        self::assertSame('generated', $doc['status']);
        self::assertSame('csv', $doc['format']);
    }

    public function testGeneratePeriodEndIsInclusive(): void
    {
        $this->issuedDocument(new DateTimeImmutable('2026-01-31 23:59:00'));

        $doc = $this->service()->generate('CBN', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), null);

        self::assertSame(1, $doc['row_count']);
    }

    public function testDownloadReturnsTheGeneratedCsvBytesWithHeaders(): void
    {
        $this->issuedDocument(new DateTimeImmutable('2026-01-15'));
        $svc = $this->service();

        $doc = $svc->generate('CBN', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), null);
        $file = $svc->download($doc['uuid']);

        self::assertStringContainsString('CCI No.', $file['bytes']);
        self::assertStringContainsString('Tiger Foods Limited', $file['bytes']);
        self::assertStringContainsString('CBN_2026-01-01_2026-01-31.csv', $file['filename']);
    }

    public function testNbsAndGenericBuildersProduceDifferentColumnSets(): void
    {
        $this->issuedDocument(new DateTimeImmutable('2026-01-15'));
        $svc = $this->service();

        $nbs = $svc->download($svc->generate('NBS', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), null)['uuid']);
        $nepc = $svc->download($svc->generate('NEPC', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), null)['uuid']);

        self::assertStringContainsString('NEPC Number', $nbs['bytes']);
        self::assertStringNotContainsString('NEPC Number', $nepc['bytes']); // generic builder, no NBS-specific columns
    }

    public function testGenerateRejectsAnUnknownAgency(): void
    {
        try {
            $this->service()->generate('IRS', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), null);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('validation_failed', $e->getErrorCode());
        }
    }

    public function testGenerateRejectsAnInvertedPeriod(): void
    {
        try {
            $this->service()->generate('CBN', new DateTimeImmutable('2026-01-31'), new DateTimeImmutable('2026-01-01'), null);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('validation_failed', $e->getErrorCode());
        }
    }

    public function testSubmitMarksSubmittedAndCannotBeSubmittedTwice(): void
    {
        $this->issuedDocument(new DateTimeImmutable('2026-01-15'));
        $officeUser = $this->makeUser(['role' => 'admin']);
        $svc = $this->service();

        $doc = $svc->generate('CBN', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), null);
        self::assertNull($doc['submitted_at']);

        $submitted = $svc->submit($doc['uuid'], $officeUser['id']);
        self::assertSame('submitted', $submitted['status']);
        self::assertNotNull($submitted['submitted_at']);

        try {
            $svc->submit($doc['uuid'], $officeUser['id']);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('already_submitted', $e->getErrorCode());
        }
    }

    public function testSubmitUnknownUuidIs404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service()->submit('00000000-0000-0000-0000-000000000000', 1);
    }

    public function testAVoidedDocumentIsExcludedFromTheReturn(): void
    {
        $uuid = $this->issuedDocument(new DateTimeImmutable('2026-01-15'));
        $this->pdo->prepare("UPDATE documents SET status = 'void' WHERE uuid = :u")->execute(['u' => $uuid]);

        $doc = $this->service()->generate('CBN', new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), null);

        self::assertSame(0, $doc['row_count']);
    }
}
