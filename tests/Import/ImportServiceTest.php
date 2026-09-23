<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Audit\AuditLog;
use App\Auth\PasswordHasher;
use App\Auth\RefreshTokenService;
use App\Documents\ShipmentDetailsRepository;
use App\Documents\TradeDetailsRepository;
use App\Import\CsvReader;
use App\Import\ImportService;
use App\Office\ClientRepository;
use App\Office\ConsignmentRepository;
use App\Office\ConsignmentService;
use App\Office\ClientService;
use App\Auth\UserRepository;
use App\Inspections\InspectionRepository;
use App\Inspections\SchedulingService;
use App\Office\InspectionRequestRepository;
use App\Office\InspectionRequestService;
use App\Users\UserAdminRepository;
use App\Users\UserAdminService;
use App\Support\ApiException;
use App\Tests\Support\DatabaseTestCase;

/**
 * Bulk import. The two properties that matter: a preview writes nothing, and a
 * commit is all-or-nothing — so what the reviewer approved is exactly what
 * lands, or nothing does.
 */
final class ImportServiceTest extends DatabaseTestCase
{
    private ImportService $imports;
    private int $actorId;

    protected function dirtyTables(): array
    {
        return ['audit_log', 'inspection_shipment_details', 'consignment_trade_details', 'documents', 'inspections',
            'inspection_requests', 'consignments', 'clients', 'refresh_tokens', 'users'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->imports = new ImportService(
            $this->pdo,
            new ClientService(new ClientRepository($this->pdo)),
            new ConsignmentService(new ConsignmentRepository($this->pdo), new ClientRepository($this->pdo)),
            new AuditLog($this->pdo),
            new InspectionRequestService(new InspectionRequestRepository($this->pdo), new ConsignmentRepository($this->pdo), 72),
            new TradeDetailsRepository($this->pdo),
            new ShipmentDetailsRepository($this->pdo),
            new UserAdminService(
                new UserAdminRepository($this->pdo),
                new PasswordHasher(['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]),
                new RefreshTokenService($this->pdo, 7),
            ),
            new SchedulingService(new InspectionRepository($this->pdo), new InspectionRequestRepository($this->pdo), new UserRepository($this->pdo)),
        );
        $this->actorId = $this->makeUser(['role' => 'admin'])['id'];
    }

    private function clientCsv(string ...$rows): string
    {
        return implode("\n", ['name,type,address,contact_name,contact_phone,contact_email,rc_number', ...$rows]) . "\n";
    }

    private function countClients(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    }

    public function testPreviewValidatesEveryRowButWritesNothing(): void
    {
        $csv = $this->clientCsv(
            'Tiger Foods,exporter,12 Marina,Ada Obi,+2348010000000,ada@tiger.test,RC1',
            'Ilera Ltd,importer,3 Broad St,Bo Ade,+2348020000000,bo@ilera.test,',
        );

        $result = $this->imports->preview('clients', $csv);

        self::assertSame(2, $result['ok']);
        self::assertSame(0, $result['failed']);
        self::assertSame(0, $this->countClients(), 'a preview must leave the database untouched');
    }

    public function testCommitWritesEveryRowAndIsAudited(): void
    {
        $csv = $this->clientCsv(
            'Tiger Foods,exporter,12 Marina,Ada Obi,+2348010000000,ada@tiger.test,RC1',
            'Ilera Ltd,importer,3 Broad St,Bo Ade,+2348020000000,bo@ilera.test,',
        );

        $result = $this->imports->commit('clients', $csv, $this->actorId, '203.0.113.1');

        self::assertSame(2, $result['ok']);
        self::assertSame(2, $this->countClients());

        $audit = $this->pdo->query("SELECT actor_id, after_state FROM audit_log WHERE action = 'import.clients'")->fetch();
        self::assertNotFalse($audit, 'a bulk load is exactly the kind of event an admin wants to find later');
        self::assertSame($this->actorId, (int) $audit['actor_id']);
    }

    public function testOneBadRowStopsTheWholeImport(): void
    {
        $csv = $this->clientCsv(
            'Tiger Foods,exporter,12 Marina,Ada Obi,+2348010000000,ada@tiger.test,RC1',
            'Broken Ltd,exporter,3 Broad St,Bo Ade,+2348020000000,not-an-email,',
            'Ilera Ltd,importer,3 Broad St,Cy Eze,+2348030000000,cy@ilera.test,',
        );

        $result = $this->imports->commit('clients', $csv, $this->actorId, null);

        self::assertSame(1, $result['failed']);
        self::assertSame(2, $result['ok'], 'the good rows are still reported, so the file can be fixed');
        self::assertSame(0, $this->countClients(), 'a partial import is worse than none: nothing is written');
        self::assertStringContainsString('email', strtolower((string) $result['rows'][1]['error']));
        self::assertSame(3, $result['rows'][1]['line'], 'the line number must point at the spreadsheet row');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
    }

    public function testTheSameRulesAsTheSingleRecordFormApply(): void
    {
        // NXP on an import is refused by ConsignmentService, and the importer
        // goes through that same service rather than its own copy of the rules.
        $this->imports->commit('clients', $this->clientCsv('Tiger Foods,exporter,12 Marina,Ada Obi,+2348010000000,ada@tiger.test,RC1'), $this->actorId, null);

        $csv = "nxp_number,client,direction,product_category,product_description,quantity,unit_of_measure,declared_value,currency,origin_country,destination_country,zone\n"
             . "NXP-1,Tiger Foods,import,Rice,Parboiled rice,5,MT,9000,USD,India,Nigeria,Lagos\n";

        $result = $this->imports->preview('nxp', $csv);

        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('exports only', (string) $result['rows'][0]['error']);
    }

    public function testNxpRecordsAreLinkedToClientsByName(): void
    {
        $this->imports->commit('clients', $this->clientCsv('Tiger Foods,exporter,12 Marina,Ada Obi,+2348010000000,ada@tiger.test,RC1'), $this->actorId, null);

        $csv = "nxp_number,client,direction,product_category,product_description,quantity,unit_of_measure,declared_value,currency,origin_country,destination_country,zone\n"
             . "AA0000001,tiger foods,export,Cocoa,Dried beans,25,MT,75000,usd,Nigeria,Netherlands,Lagos\n"
             . "AA0000002,Nobody Ltd,export,Cocoa,Dried beans,25,MT,75000,USD,Nigeria,Netherlands,Lagos\n";

        $result = $this->imports->preview('nxp', $csv);

        self::assertNull($result['rows'][0]['error'], 'the client name is matched ignoring case');
        self::assertStringContainsString('Nobody Ltd', (string) $result['rows'][1]['error']);
    }

    public function testHeadersAreMatchedLooselyAndMissingOnesAreNamed(): void
    {
        $loose = "Name,Type,Address,Contact Name,Contact Phone,Contact Email\n"
               . "Tiger Foods,exporter,12 Marina,Ada Obi,+2348010000000,ada@tiger.test\n";

        self::assertSame(1, $this->imports->preview('clients', $loose)['ok']);

        try {
            $this->imports->preview('clients', "name,type\nTiger Foods,exporter\n");
            self::fail('a file missing required columns should be refused');
        } catch (ApiException $e) {
            self::assertStringContainsString('contact_email', $e->getMessage());
        }
    }

    public function testARowWithTheWrongNumberOfCellsIsReportedNotFatal(): void
    {
        $csv = $this->clientCsv(
            'Tiger Foods,exporter,12 Marina,Ada Obi,+2348010000000,ada@tiger.test,RC1',
            'Short Ltd,exporter,3 Broad St',
        );

        $result = $this->imports->preview('clients', $csv);

        self::assertSame(1, $result['ok']);
        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('3 values', (string) $result['rows'][1]['error']);
    }

    public function testTheTemplateIsAFileTheImporterAccepts(): void
    {
        foreach (ImportService::TYPES as $type) {
            $template = ImportService::templateFor($type);

            if ($type === 'nxp') {
                // The template's example names the template client, so load that first.
                $this->imports->commit('clients', ImportService::templateFor('clients'), $this->actorId, null);
            }

            if ($type === 'inspection_requests') {
                // ...and the later templates point at the template's NXP record.
                $this->imports->commit('nxp', ImportService::templateFor('nxp'), $this->actorId, null);
            }
            if ($type === 'assignments') {
                // An open request on the record and the inspector the template names.
                $this->imports->commit('inspection_requests', ImportService::templateFor('inspection_requests'), $this->actorId, null);
                $this->makeUser(['role' => 'inspector', 'email' => 'inspector@adwol.test']);
            }
            if ($type === 'shipment_details') {
                $this->inspectionOn('AA1234567');
            }

            $result = $this->imports->preview($type, $template, $this->actorId);
            self::assertSame(0, $result['failed'], "the {$type} template must import cleanly: " . json_encode($result['rows']));
        }
    }

    public function testFileLimitsAreEnforced(): void
    {
        $this->expectException(ApiException::class);
        CsvReader::parse(str_repeat('x', CsvReader::MAX_BYTES + 1));
    }

    public function testNxpRulesApplyToImportsTooMissingAndDuplicateNumbers(): void
    {
        $this->imports->commit('clients', $this->clientCsv('Tiger Foods,exporter,12 Marina,Ada Obi,+2348010000000,ada@tiger.test,RC1'), $this->actorId, null);

        $csv = "nxp_number,client,direction,product_category,product_description,quantity,unit_of_measure,declared_value,currency,origin_country,destination_country,zone\n"
             . "AB1,Tiger Foods,export,Cocoa,Dried beans,25,MT,75000,USD,Nigeria,Netherlands,Lagos\n"
             . ",Tiger Foods,export,Cocoa,Dried beans,25,MT,75000,USD,Nigeria,Netherlands,Lagos\n"
             . "ab1,Tiger Foods,export,Sesame,Hulled,10,MT,9000,USD,Nigeria,Turkey,Kano\n"
             . ",Tiger Foods,import,Rice,Parboiled,5,MT,9000,USD,India,Nigeria,Lagos\n";

        $result = $this->imports->preview('nxp', $csv);

        self::assertNull($result['rows'][0]['error']);
        self::assertStringContainsString('must have an NXP number', (string) $result['rows'][1]['error']);
        self::assertStringContainsString('already recorded', (string) $result['rows'][2]['error'], 'a duplicate within the same file is caught too');
        self::assertNull($result['rows'][3]['error'], 'an import needs no NXP number');
    }

    public function testASheetUsingTheOldFormNxpNumberHeaderStillImports(): void
    {
        $this->imports->commit('clients', $this->clientCsv('Tiger Foods,exporter,12 Marina,Ada Obi,+2348010000000,ada@tiger.test,RC1'), $this->actorId, null);

        $csv = "client,direction,product_category,product_description,quantity,unit_of_measure,declared_value,currency,origin_country,destination_country,zone,form_nxp_number\n"
             . "Tiger Foods,export,Cocoa,Dried beans,25,MT,75000,USD,Nigeria,Netherlands,Lagos,OLD-HEADER-1\n";

        $result = $this->imports->commit('nxp', $csv, $this->actorId, null);

        self::assertSame(1, $result['ok']);
        self::assertSame('OLD-HEADER-1', $this->pdo->query('SELECT form_nxp_number FROM consignments')->fetchColumn());
    }

    /**
     * An exporter with one NXP record per number given.
     *
     * @return array<string,int> number => consignment id
     */
    private function nxpRecords(string ...$numbers): array
    {
        $client = $this->makeClientRow(['name' => 'Tiger Foods']);
        $out = [];
        foreach ($numbers as $n) {
            $out[$n] = $this->makeConsignmentRow($client['id'], ['form_nxp_number' => $n])['id'];
        }

        return $out;
    }

    /** A scheduled inspection on the NXP record with this number; returns its id. */
    private function inspectionOn(string $nxp): int
    {
        $id = (int) $this->pdo->query('SELECT id FROM consignments WHERE form_nxp_number = ' . $this->pdo->quote($nxp))->fetchColumn();
        $req = $this->makeInspectionRequestRow($id, $this->actorId, ['status' => 'scheduled']);
        $inspector = $this->makeUser(['role' => 'inspector'])['id'];

        return $this->makeScheduledInspectionRow($req['id'], $inspector)['id'];
    }

    public function testInspectionRequestsAreRaisedForNxpRecordsByNumberOrReference(): void
    {
        $ids = $this->nxpRecords('RQ-1', 'RQ-2');
        $importUuid = $this->makeConsignmentRow($this->makeClientRow()['id'], ['direction' => 'import'])['uuid'];

        $csv = "nxp_number,record_ref,requested_at\n"
             . "RQ-1,,2026-10-01 09:00\n"
             . ",{$importUuid},\n"
             . "NOPE-9,,\n"
             . "rq-1,,\n";

        $result = $this->imports->preview('inspection_requests', $csv, $this->actorId);

        self::assertNull($result['rows'][0]['error']);
        self::assertNull($result['rows'][1]['error'], 'an import (no NXP number) is found by its record reference');
        self::assertStringContainsString('NOPE-9', (string) $result['rows'][2]['error']);
        self::assertStringContainsString('more than once', (string) $result['rows'][3]['error'], 'one request per record per file');

        $ok = $this->imports->commit('inspection_requests', "nxp_number\nRQ-2\n", $this->actorId, null);
        self::assertSame(1, $ok['ok']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inspection_requests WHERE consignment_id = ' . $ids['RQ-2'])->fetchColumn());

        $again = $this->imports->preview('inspection_requests', "nxp_number\nRQ-2\n", $this->actorId);
        self::assertStringContainsString('already has an open', (string) $again['rows'][0]['error']);
    }

    public function testTradeDetailsOnlyChangeTheCellsThatAreFilledIn(): void
    {
        $ids = $this->nxpRecords('TD-1');
        (new TradeDetailsRepository($this->pdo))->upsert($ids['TD-1'], ['importer_name' => 'Cacao BV', 'invoice_number' => 'INV-1']);

        $csv = "nxp_number,invoice_number,basis_of_sale,freight_charges,invoice_date,importer_name\n"
             . "TD-1,INV-2,fob,\"1,200.50\",21/09/2026,\n";
        $result = $this->imports->commit('trade_details', $csv, $this->actorId, null);

        self::assertSame(1, $result['ok'], (string) json_encode($result['rows']));
        $row = (new TradeDetailsRepository($this->pdo))->forConsignment($ids['TD-1']);
        self::assertSame('Cacao BV', $row['importer_name'], 'a blank cell keeps what was there');
        self::assertSame('INV-2', $row['invoice_number']);
        self::assertSame('FOB', $row['basis_of_sale']);
        self::assertSame('1200.50', $row['freight_charges'], 'thousands separators are accepted');
        self::assertSame('2026-09-21', $row['invoice_date'], 'day-first dates are accepted');
    }

    public function testDetailCellsAreCheckedBeforeAnythingIsWritten(): void
    {
        $this->nxpRecords('TD-2', 'TD-3', 'TD-4', 'TD-5');

        $result = $this->imports->preview('trade_details', "nxp_number,basis_of_sale,invoice_date,freight_charges\nTD-2,EXW,,\nTD-3,,31/02/2026,\nTD-4,,,lots\nTD-5,,,\n");

        self::assertStringContainsString('FOB, CFR, CIF', (string) $result['rows'][0]['error']);
        self::assertStringContainsString('Must be a date', (string) $result['rows'][1]['error'], '31 February is not a date');
        self::assertStringContainsString('Must be a number', (string) $result['rows'][2]['error']);
        self::assertStringContainsString('Nothing to update', (string) $result['rows'][3]['error']);
        self::assertSame(4, $result['failed']);
    }

    public function testShipmentDetailsLoadExchangeRatesByCciOrNxpNumber(): void
    {
        $this->nxpRecords('SD-1', 'SD-2', 'SD-3');
        $first = $this->inspectionOn('SD-1');
        $second = $this->inspectionOn('SD-2');
        $this->makeDocumentRow($second, ['document_number' => 'CCI-SD-2']);

        $csv = "nxp_number,cci_number,exchange_rate,shipment_date\n"
             . "SD-1,,1550.25,2026-09-01\n"
             . ",CCI-SD-2,\"1,600\",\n"
             . "SD-3,,1500,\n"
             . "SD-1,,0,\n";
        $preview = $this->imports->preview('shipment_details', $csv);

        self::assertNull($preview['rows'][0]['error']);
        self::assertNull($preview['rows'][1]['error'], 'a CCI number finds the inspection it was issued on');
        self::assertStringContainsString('no inspection yet', (string) $preview['rows'][2]['error']);
        self::assertNotNull($preview['rows'][3]['error']);

        $result = $this->imports->commit('shipment_details', "nxp_number,cci_number,exchange_rate\nSD-1,,1550.25\n,CCI-SD-2,\"1,600\"\n", $this->actorId, null);
        self::assertSame(2, $result['ok'], (string) json_encode($result['rows']));
        $repo = new ShipmentDetailsRepository($this->pdo);
        self::assertSame('1550.2500', $repo->forInspection($first)['exchange_rate']);
        self::assertSame('1600.0000', $repo->forInspection($second)['exchange_rate']);
    }

    public function testUsersAreCreatedWithTheSameRulesAsTheUserForm(): void
    {
        $csv = "full_name,email,role,zone\n"
             . "Chidi Okeke,Chidi@ADWOL.test,inspector,Lagos\n"
             . "Ngozi Board,ngozi@adwol.test,Board of Directors,\n"
             . "Bad Role,bad@adwol.test,janitor,\n"
             . "Dupe,chidi@adwol.test,inspector,\n";

        $preview = $this->imports->preview('users', $csv);
        self::assertNull($preview['rows'][0]['error']);
        self::assertNull($preview['rows'][1]['error'], '"Board of Directors" is accepted for the board role');
        self::assertNotNull($preview['rows'][2]['error']);
        self::assertStringContainsString('already exists', (string) $preview['rows'][3]['error']);

        $result = $this->imports->commit('users', "full_name,email,role\nNgozi Board,ngozi@adwol.test,board\n", $this->actorId, null);
        self::assertSame(1, $result['ok']);
        $row = $this->pdo->query("SELECT role, password_hash FROM users WHERE email = 'ngozi@adwol.test'")->fetch();
        self::assertSame('board', $row['role']);
        self::assertNotSame('', (string) $row['password_hash'], 'a random password is set when the sheet has none');
    }

    public function testInspectorsAreAssignedToOpenRequestsInBulk(): void
    {
        $ids = $this->nxpRecords('AS-1', 'AS-2', 'AS-3');
        $this->makeInspectionRequestRow($ids['AS-1'], $this->actorId);
        $this->makeInspectionRequestRow($ids['AS-3'], $this->actorId);
        $this->makeUser(['role' => 'inspector', 'email' => 'field@adwol.test']);
        $this->makeUser(['role' => 'office_reviewer', 'email' => 'desk@adwol.test']);

        $csv = "nxp_number,inspector_email,scheduled_at,location_type,location_detail\n"
             . "AS-1,Field@ADWOL.test,2026-10-02 10:00,warehouse,Ikeja\n"
             . "AS-2,field@adwol.test,2026-10-02 10:00,port,Apapa\n"
             . "AS-3,desk@adwol.test,2026-10-02 10:00,port,Apapa\n";
        $preview = $this->imports->preview('assignments', $csv);

        self::assertNull($preview['rows'][0]['error'], (string) json_encode($preview['rows']));
        self::assertStringContainsString('no open inspection request', (string) $preview['rows'][1]['error']);
        self::assertStringContainsString('not an inspector', (string) $preview['rows'][2]['error']);

        $result = $this->imports->commit('assignments', "nxp_number,inspector_email,scheduled_at,location_type,location_detail\nAS-1,field@adwol.test,2026-10-02 10:00,warehouse,Ikeja\n", $this->actorId, null);
        self::assertSame(1, $result['ok']);
        self::assertSame('scheduled', $this->pdo->query('SELECT status FROM inspection_requests WHERE consignment_id = ' . $ids['AS-1'])->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inspections')->fetchColumn());

        $again = $this->imports->preview('assignments', "nxp_number,inspector_email,scheduled_at,location_type,location_detail\nAS-1,field@adwol.test,2026-10-03 10:00,port,Apapa\n");
        self::assertStringContainsString('already assigned', (string) $again['rows'][0]['error']);
    }
}
