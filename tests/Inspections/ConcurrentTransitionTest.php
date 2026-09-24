<?php

declare(strict_types=1);

namespace App\Tests\Inspections;

use App\Audit\AuditLog;
use App\Inspections\AttachmentRepository;
use App\Inspections\FindingRepository;
use App\Inspections\InspectionRepository;
use App\Inspections\ReviewService;
use App\Office\InspectionRequestRepository;
use App\Support\ApiException;
use App\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Status changes used to read the current status, check it, then write with
 * `WHERE id = ?`. Two submissions arriving together — an impatient double
 * click, a retried request, two officers on the same record — could therefore
 * both pass the check, and the second would quietly overwrite the first.
 *
 * Each write now carries the status the caller validated, so the loser of the
 * race is refused instead of silently winning.
 */
final class ConcurrentTransitionTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['audit_log', 'sync_log', 'inspection_attachments', 'inspection_findings', 'inspections',
                'inspection_requests', 'consignments', 'clients', 'users'];
    }

    private function reviewService(): ReviewService
    {
        return new ReviewService(
            $this->pdo,
            new InspectionRepository($this->pdo),
            new FindingRepository($this->pdo),
            new AttachmentRepository($this->pdo),
            new AuditLog($this->pdo),
        );
    }

    /** @return array{office:int, uuid:string, id:int, request_id:int} */
    private function synced(): array
    {
        $inspector = $this->makeUser(['role' => 'inspector']);
        $office    = $this->makeUser(['role' => 'office_reviewer']);
        $client    = $this->makeClientRow();
        $cons      = $this->makeConsignmentRow($client['id']);
        $req       = $this->makeInspectionRequestRow($cons['id'], $office['id'], ['status' => 'scheduled']);
        $insp      = $this->makeScheduledInspectionRow($req['id'], $inspector['id'], [
            'status' => 'synced', 'synced_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return ['office' => $office['id'], 'uuid' => $insp['uuid'], 'id' => $insp['id'], 'request_id' => $req['id']];
    }

    public function testFinalizingAnInspectionThatIsAlreadyFinalizedIsRefused(): void
    {
        $s = $this->synced();
        $repo = new InspectionRepository($this->pdo);
        $when = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $repo->applyFinalize($s['id'], $s['office'], $when, ['synced', 'amended']);

        // The second caller read 'synced' a moment earlier and only now writes.
        try {
            $repo->applyFinalize($s['id'], $s['office'], $when, ['synced', 'amended']);
            self::fail('expected the second finalize to be refused');
        } catch (ApiException $e) {
            self::assertSame(409, $e->getStatusCode());
            self::assertSame('concurrent_update', $e->getErrorCode());
            self::assertSame('finalized', $e->getDetails()['status']);
        }
    }

    public function testRejectCannotOverwriteAFinalizedInspection(): void
    {
        $s = $this->synced();
        $repo = new InspectionRepository($this->pdo);

        $repo->applyFinalize($s['id'], $s['office'], new DateTimeImmutable('now', new DateTimeZone('UTC')), ['synced', 'amended']);

        try {
            $repo->applyReject($s['id'], ['synced', 'amended']);
            self::fail('expected the reject to be refused');
        } catch (ApiException) {
            // The certificate-bearing state survives, which is the point.
            self::assertSame(
                'finalized',
                (string) $this->pdo->query("SELECT status FROM inspections WHERE id = {$s['id']}")->fetchColumn(),
            );
        }
    }

    public function testAmendCannotOverwriteAFinalizedInspection(): void
    {
        $s = $this->synced();
        $repo = new InspectionRepository($this->pdo);

        $repo->applyFinalize($s['id'], $s['office'], new DateTimeImmutable('now', new DateTimeZone('UTC')), ['synced', 'amended']);

        $this->expectException(ApiException::class);
        $repo->applyAmend($s['id'], 'port', 'Tampered detail', ['synced', 'amended']);
    }

    public function testTheOrdinaryReviewPathStillWorks(): void
    {
        $s = $this->synced();

        self::assertSame('finalized', $this->reviewService()->finalize($s['uuid'], $s['office'], null)['status']);
    }

    public function testAnInspectionRequestCannotBeMovedTwiceFromTheSameStatus(): void
    {
        $s = $this->synced();
        $requests = new InspectionRequestRepository($this->pdo);

        $requests->updateStatus($s['request_id'], 'completed', 'scheduled');

        try {
            // A second officer, whose page still showed 'scheduled', cancels.
            $requests->updateStatus($s['request_id'], 'cancelled', 'scheduled');
            self::fail('expected the stale transition to be refused');
        } catch (ApiException $e) {
            self::assertSame('concurrent_update', $e->getErrorCode());
        }

        self::assertSame(
            'completed',
            (string) $this->pdo->query("SELECT status FROM inspection_requests WHERE id = {$s['request_id']}")->fetchColumn(),
        );
    }

    public function testUpdateStatusWithoutAnExpectedStatusStillWorks(): void
    {
        $s = $this->synced();
        $requests = new InspectionRequestRepository($this->pdo);

        // Callers that have no status to assert (an unconditional correction)
        // keep the old behaviour rather than being forced to invent one.
        self::assertSame('cancelled', $requests->updateStatus($s['request_id'], 'cancelled')['status']);
    }
}
