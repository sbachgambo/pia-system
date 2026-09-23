<?php

declare(strict_types=1);

namespace App\Tests\Inspections;

use App\Auth\UserRepository;
use App\Http\Support\Input;
use App\Inspections\InspectionRepository;
use App\Inspections\SchedulingService;
use App\Office\InspectionRequestRepository;
use App\Office\NotFoundException;
use App\Support\ApiException;
use App\Tests\Support\DatabaseTestCase;

final class SchedulingServiceTest extends DatabaseTestCase
{
    protected function dirtyTables(): array
    {
        return ['inspections', 'inspection_requests', 'consignments', 'clients', 'users'];
    }

    private function service(): SchedulingService
    {
        return new SchedulingService(
            new InspectionRepository($this->pdo),
            new InspectionRequestRepository($this->pdo),
            new UserRepository($this->pdo),
        );
    }

    /** @return array{request_uuid:string, inspector_uuid:string} */
    private function scenario(string $requestStatus = 'pending'): array
    {
        $office    = $this->makeUser(['role' => 'office_reviewer']);
        $inspector = $this->makeUser(['role' => 'inspector']);
        $client    = $this->makeClientRow();
        $cons      = $this->makeConsignmentRow($client['id']);
        $req       = $this->makeInspectionRequestRow($cons['id'], $office['id'], ['status' => $requestStatus]);

        return ['request_uuid' => $req['uuid'], 'inspector_uuid' => $inspector['uuid']];
    }

    private function input(string $inspectorUuid, array $overrides = []): Input
    {
        return Input::fromArray(array_merge([
            'inspector_uuid'  => $inspectorUuid,
            'location_type'   => 'warehouse',
            'location_detail' => 'Bay 12',
        ], $overrides));
    }

    public function testScheduleCreatesInspectionAndAdvancesTheRequest(): void
    {
        $s = $this->scenario('pending');

        $inspection = $this->service()->schedule($s['request_uuid'], $this->input($s['inspector_uuid']));

        self::assertSame('scheduled', $inspection['status']);
        self::assertSame($s['inspector_uuid'], $inspection['inspector_uuid']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $inspection['uuid']);
        self::assertArrayNotHasKey('id', $inspection);

        $reqStatus = $this->pdo->query('SELECT status FROM inspection_requests LIMIT 1')->fetchColumn();
        self::assertSame('scheduled', $reqStatus);
    }

    public function testUnknownRequestIs404(): void
    {
        $s = $this->scenario();
        $this->expectException(NotFoundException::class);
        $this->service()->schedule('00000000-0000-0000-0000-000000000000', $this->input($s['inspector_uuid']));
    }

    public function testCompletedRequestCannotBeScheduled(): void
    {
        $s = $this->scenario('completed');
        $this->expectException(ApiException::class);
        $this->service()->schedule($s['request_uuid'], $this->input($s['inspector_uuid']));
    }

    public function testInspectorMustExistAndHaveTheInspectorRole(): void
    {
        $s = $this->scenario();
        $notInspector = $this->makeUser(['role' => 'admin']);

        try {
            $this->service()->schedule($s['request_uuid'], $this->input($notInspector['uuid']));
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('inspector_uuid', $e->getDetails()['field']);
        }
    }

    public function testBadLocationTypeIsRejected(): void
    {
        $s = $this->scenario();
        $this->expectException(ApiException::class);
        $this->service()->schedule($s['request_uuid'], $this->input($s['inspector_uuid'], ['location_type' => 'moon']));
    }
}
