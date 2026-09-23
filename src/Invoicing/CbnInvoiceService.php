<?php

declare(strict_types=1);

namespace App\Invoicing;

use App\Audit\AuditLog;
use App\Documents\DocumentSigner;
use App\Documents\Fees;
use App\Documents\PdfRenderer;
use App\Http\View\Renderer;
use App\Office\NotFoundException;
use App\Settings\CompanySettingsRepository;
use App\Support\ApiException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Ramsey\Uuid\Uuid;

/**
 * The monthly PIA → CBN service-fee invoice.
 *
 *  - preview(): what the month's invoice would say, plus anything blocking it
 *    (CCIs with no exchange rate, missing bank details, an existing invoice).
 *  - issue(): only when nothing blocks it. One live invoice per month; a second
 *    one needs the first voided. The figures are frozen into the row and the
 *    PDF is signed, so later edits to a record never change an issued invoice.
 *  - markPaid() / void(): the only changes an issued invoice can undergo, and
 *    only from `issued` (a paid invoice is final).
 */
final class CbnInvoiceService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CbnInvoiceRepository $invoices,
        private readonly InvoiceSettingsRepository $settings,
        private readonly CompanySettingsRepository $company,
        private readonly InvoiceStorage $storage,
        private readonly DocumentSigner $signer,
        private readonly Renderer $renderer,
        private readonly PdfRenderer $pdf,
        private readonly AuditLog $audit,
    ) {
    }

    /** "2026-06" → first day of that month (UTC). */
    public static function parseMonth(string $month): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m', trim($month), new DateTimeZone('UTC'));
        if ($d === false || $d->format('Y-m') !== trim($month)) {
            throw new ApiException(422, 'validation_failed', 'Choose a month.', ['field' => 'month']);
        }

        return $d;
    }

    /**
     * @return array{
     *   month:string, label:string, figures:array<string,mixed>, ccis:list<array<string,mixed>>,
     *   existing:?array<string,mixed>, blockers:list<string>
     * }
     */
    public function preview(DateTimeImmutable $month): array
    {
        $start = $month->modify('first day of this month')->setTime(0, 0);
        $end = $start->modify('+1 month');

        $ccis = $this->invoices->ccisIssuedBetween($start, $end);
        $figures = InvoiceCalculator::calculate($ccis);
        $existing = $this->invoices->activeForMonth($start->format('Y-m-d'));

        $blockers = [];
        if ($existing !== null) {
            $blockers[] = "Invoice {$existing['invoice_number']} already covers this month. Void it first to issue a replacement.";
        }
        if ($ccis === []) {
            $blockers[] = 'No CCIs were issued in this month, so there is nothing to invoice.';
        }
        if ($figures['missing_rate'] !== []) {
            $blockers[] = count($figures['missing_rate']) . ' CCI(s) have no exchange rate recorded, so their naira value is unknown: '
                . implode(', ', $figures['missing_rate']) . '. Add the rate on each inspection\'s shipment details.';
        }
        if (!$this->settings->isComplete()) {
            $blockers[] = 'The bank account to be paid into is not set. Fill it in under Invoice details on this page.';
        }

        return [
            'month'    => $start->format('Y-m'),
            'label'    => strtoupper($start->format('F Y')),
            'figures'  => $figures,
            'ccis'     => $ccis,
            'existing' => $existing,
            'blockers' => $blockers,
        ];
    }

    /** @return array<string,mixed> the issued invoice row */
    public function issue(DateTimeImmutable $month, int $actorId, ?string $ip): array
    {
        $periodMonth = $month->modify('first day of this month')->format('Y-m-d');

        // Serialise issuing per month across requests: two admins pressing
        // "Issue" together must not both get past the "already invoiced" check.
        $lockName = 'cbn_invoice_' . $periodMonth;
        $got = $this->pdo->prepare('SELECT GET_LOCK(:n, 10)');
        $got->execute(['n' => $lockName]);
        if ((int) $got->fetchColumn() !== 1) {
            throw new ApiException(409, 'busy', 'Another invoice is being issued for this month. Try again in a moment.');
        }

        try {
            $preview = $this->preview($month);
            if ($preview['blockers'] !== []) {
                throw new ApiException(422, 'invoice_blocked', $preview['blockers'][0]);
            }

            $settings = $this->settings->get();
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $uuid = Uuid::uuid4()->toString();
            $figures = $preview['figures'];
            $stored = null;

            // Number allocation and the insert commit together, so a failure
            // anywhere in between never leaves a gap in the invoice numbering.
            $this->pdo->beginTransaction();
            try {
                $number = $this->invoices->allocateNumber($settings['number_prefix'], (int) $now->format('Y'));

                $html = $this->renderer->renderRaw('documents/cbn_invoice', [
                    'invoice'  => ['number' => $number, 'issued_at' => $now->format('Y-m-d'), 'label' => $preview['label']],
                    'figures'  => $figures,
                    'words'    => InvoiceCalculator::nairaInWords($figures['fee_ngn']),
                    'settings' => $settings,
                    'company'  => $this->company->get(),
                    'feeLabel' => Fees::percent($figures['fee_rate']),
                ]);
                $bytes = $this->pdf->render($html);
                $signed = $this->signer->sign($bytes);
                $stored = $this->storage->put($uuid, $bytes);

                $row = $this->invoices->insert([
                    'uuid'           => $uuid,
                    'invoice_number' => $number,
                    'period_month'   => $periodMonth,
                    'cci_count'      => $figures['cci_count'],
                    'fob_ngn'        => $figures['fob_ngn'],
                    'fee_rate'       => $figures['fee_rate'],
                    'fee_ngn'        => $figures['fee_ngn'],
                    'lines'          => $figures['lines'],
                    'file_path'      => $stored['relative_path'],
                    'content_hash'   => $signed['content_hash'],
                    'hmac_signature' => $signed['hmac_signature'],
                    'issued_by'      => $actorId,
                    'issued_at'      => $now->format('Y-m-d H:i:s'),
                ]);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($stored !== null && is_file($stored['path'])) {
                    @unlink($stored['path']);
                }
                throw $e;
            }
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(:n)')->execute(['n' => $lockName]);
        }

        $this->audit->record($actorId, 'cbn_invoice.issue', 'cbn_invoice', (int) $row['id'], null, [
            'invoice_number' => $number,
            'period'         => $preview['month'],
            'cci_count'      => $figures['cci_count'],
            'fee_ngn'        => $figures['fee_ngn'],
        ], $ip);

        return $row;
    }

    public function markPaid(string $uuid, string $paidAt, ?string $reference, int $actorId, ?string $ip): void
    {
        $row = $this->invoices->findByUuid($uuid) ?? throw new NotFoundException('Invoice');

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $paidAt, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $paidAt) {
            throw new ApiException(422, 'validation_failed', 'Enter the date the payment was received.', ['field' => 'paid_at']);
        }
        if ($date < new DateTimeImmutable(substr((string) $row['issued_at'], 0, 10), new DateTimeZone('UTC'))) {
            throw new ApiException(422, 'validation_failed', 'The payment date cannot be before the invoice was issued.', ['field' => 'paid_at']);
        }
        if ($date > new DateTimeImmutable('today', new DateTimeZone('UTC'))) {
            throw new ApiException(422, 'validation_failed', 'The payment date cannot be in the future.', ['field' => 'paid_at']);
        }

        $reference = $reference !== null && trim($reference) !== '' ? mb_substr(trim($reference), 0, 100) : null;
        if (!$this->invoices->markPaid((int) $row['id'], $paidAt, $reference)) {
            throw new ApiException(422, 'invalid_transition', 'Only an issued (unpaid) invoice can be marked paid.');
        }

        $this->audit->record($actorId, 'cbn_invoice.paid', 'cbn_invoice', (int) $row['id'], ['status' => $row['status']], [
            'status' => 'paid', 'paid_at' => $paidAt, 'reference' => $reference,
        ], $ip);
    }

    public function void(string $uuid, string $reason, int $actorId, ?string $ip): void
    {
        $row = $this->invoices->findByUuid($uuid) ?? throw new NotFoundException('Invoice');

        $reason = trim($reason);
        if ($reason === '') {
            throw new ApiException(422, 'validation_failed', 'Say why the invoice is being voided.', ['field' => 'reason']);
        }
        if (!$this->invoices->markVoid((int) $row['id'], mb_substr($reason, 0, 255))) {
            throw new ApiException(422, 'invalid_transition', 'Only an issued (unpaid) invoice can be voided.');
        }

        $this->audit->record($actorId, 'cbn_invoice.void', 'cbn_invoice', (int) $row['id'], ['status' => $row['status']], [
            'status' => 'void', 'reason' => $reason,
        ], $ip);
    }

    /** @return array{bytes:string, filename:string} — only if the stored file still matches its signature */
    public function download(string $uuid): array
    {
        $row = $this->invoices->findByUuid($uuid) ?? throw new NotFoundException('Invoice');
        $bytes = $this->storage->read((string) $row['file_path'])
            ?? throw new ApiException(410, 'file_missing', 'The invoice file is no longer available.');

        if (!$this->signer->verify($bytes, (string) $row['content_hash'], (string) $row['hmac_signature'])) {
            throw new ApiException(409, 'integrity_failed', 'The stored invoice file does not match its signature and was not served.');
        }

        return [
            'bytes'    => $bytes,
            'filename' => 'CBN-invoice-' . str_replace('/', '-', (string) $row['invoice_number']) . '.pdf',
        ];
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function list(int $limit, int $offset, ?string $status): array
    {
        return $this->invoices->paginate($limit, $offset, $status);
    }

    public function get(string $uuid): ?array
    {
        return $this->invoices->findByUuid($uuid);
    }
}
