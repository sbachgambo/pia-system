<?php

declare(strict_types=1);

namespace App\Tests\Office;

use App\Http\Support\Input;
use App\Http\Support\Pagination;
use App\Office\ConsignmentRepository;
use App\Office\InspectionRequestRepository;
use App\Office\InspectionRequestService;
use App\Support\ApiException;
use App\Tests\Support\DatabaseTestCase;
use DateTimeImmutable;
use Slim\Psr7\Factory\ServerRequestFactory;

final class InspectionRequestServiceTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['inspection_requests', 'consignments', 'clients', 'users'];
    }

    private function service(int $windowHours = 72): InspectionRequestService
    {
        return new InspectionRequestService(
            new InspectionRequestRepository($this->pdo),
            new ConsignmentRepository($this->pdo),
            $windowHours,
        );
    }

    /** @return array{user:int, consignment:string} */
    private function context(): array
    {
        $user = $this->makeUser(['role' => 'office_reviewer']);
        $client = $this->makeClientRow();
        $consignment = $this->makeConsignmentRow($client['id']);

        return ['user' => $user['id'], 'consignment' => $consignment['uuid']];
    }

    public function testCreateComputesNoticeDeadlineFromRequestedAtPlusWindow(): void
    {
        $ctx = $this->context();
        $requestedAt = '2026-09-10T09:00:00+00:00';

        $ir = $this->service(72)->create(
            Input::fromArray(['consignment_uuid' => $ctx['consignment'], 'requested_at' => $requestedAt]),
            $ctx['user'],
        );

        self::assertSame('2026-09-10T09:00:00+00:00', $ir['requested_at']);
        self::assertSame('2026-09-13T09:00:00+00:00', $ir['notice_deadline']); // +72h
        self::assertSame('pending', $ir['status']);
        self::assertSame($ctx['consignment'], $ir['consignment_uuid']);
    }

    public function testCreateDefaultsRequestedAtToNow(): void
    {
        $ctx = $this->context();
        $before = new DateTimeImmutable('-1 minute');

        $ir = $this->service()->create(Input::fromArray(['consignment_uuid' => $ctx['consignment']]), $ctx['user']);

        self::assertGreaterThanOrEqual($before, new DateTimeImmutable($ir['requested_at']));
    }

    public function testUnknownConsignmentIs422(): void
    {
        $ctx = $this->context();

        try {
            $this->service()->create(
                Input::fromArray(['consignment_uuid' => '00000000-0000-0000-0000-000000000000']),
                $ctx['user'],
            );
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertSame('consignment_uuid', $e->getDetails()['field']);
        }
    }

    public function testValidTransitionsFollowTheStateMachine(): void
    {
        $ctx = $this->context();
        $svc = $this->service();
        $ir = $svc->create(Input::fromArray(['consignment_uuid' => $ctx['consignment']]), $ctx['user']);

        $scheduled = $svc->transition($ir['uuid'], Input::fromArray(['status' => 'scheduled']));
        self::assertSame('scheduled', $scheduled['status']);

        $completed = $svc->transition($ir['uuid'], Input::fromArray(['status' => 'completed']));
        self::assertSame('completed', $completed['status']);
    }

    public function testIllegalTransitionIsRejected(): void
    {
        $ctx = $this->context();
        $svc = $this->service();
        $ir = $svc->create(Input::fromArray(['consignment_uuid' => $ctx['consignment']]), $ctx['user']);

        // pending -> completed is not allowed (must go via scheduled)
        try {
            $svc->transition($ir['uuid'], Input::fromArray(['status' => 'completed']));
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('invalid_transition', $e->getErrorCode());
            self::assertSame('pending', $e->getDetails()['from']);
        }
    }

    public function testTransitionToSameStatusIsIdempotentNoop(): void
    {
        $ctx = $this->context();
        $svc = $this->service();
        $ir = $svc->create(Input::fromArray(['consignment_uuid' => $ctx['consignment']]), $ctx['user']);

        $same = $svc->transition($ir['uuid'], Input::fromArray(['status' => 'pending']));
        self::assertSame('pending', $same['status']);
    }

    public function testRescheduleRecomputesDeadlineAndCancelledCannotReschedule(): void
    {
        $ctx = $this->context();
        $svc = $this->service(48);
        $ir = $svc->create(Input::fromArray(['consignment_uuid' => $ctx['consignment']]), $ctx['user']);

        $moved = $svc->reschedule($ir['uuid'], Input::fromArray(['requested_at' => '2026-10-01T00:00:00+00:00']));
        self::assertSame('2026-10-01T00:00:00+00:00', $moved['requested_at']);
        self::assertSame('2026-10-03T00:00:00+00:00', $moved['notice_deadline']); // +48h

        $svc->transition($ir['uuid'], Input::fromArray(['status' => 'cancelled']));

        $this->expectException(ApiException::class);
        $svc->reschedule($ir['uuid'], Input::fromArray(['requested_at' => '2026-11-01T00:00:00+00:00']));
    }

    public function testOverdueFilterReturnsOnlyOpenPastDeadline(): void
    {
        $ctx = $this->context();
        $svc = $this->service(72);

        // One overdue (requested 10 days ago), one fresh.
        $svc->create(Input::fromArray([
            'consignment_uuid' => $ctx['consignment'],
            'requested_at'     => (new DateTimeImmutable('-10 days'))->format(DATE_ATOM),
        ]), $ctx['user']);
        $svc->create(Input::fromArray(['consignment_uuid' => $ctx['consignment']]), $ctx['user']);

        $page = Pagination::fromRequest((new ServerRequestFactory())->createServerRequest('GET', '/x'));
        $overdue = $svc->list($page, ['overdue' => true]);

        self::assertSame(1, $overdue['pagination']['total']);
    }
}
