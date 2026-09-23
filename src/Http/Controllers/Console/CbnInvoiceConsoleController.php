<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Audit\AuditLog;
use App\Documents\Fees;
use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Support\ClientContext;
use App\Http\Support\Pagination;
use App\Http\Support\Query;
use App\Http\View\Renderer;
use App\Invoicing\CbnInvoiceService;
use App\Invoicing\InvoiceSettingsRepository;
use App\Office\NotFoundException;
use App\Support\ApiException;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /console/invoices — the monthly service-fee invoice to the CBN (admin-only,
 * via the route group). Pick a month, see exactly what the invoice would say
 * and anything stopping it, issue it, then record the payment when it lands.
 */
final class CbnInvoiceConsoleController extends AbstractConsoleController
{
    public function __construct(
        Renderer $view,
        private readonly CbnInvoiceService $invoices,
        private readonly InvoiceSettingsRepository $settings,
        private readonly AuditLog $audit,
    ) {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = Query::params($request);
        // Default to last month: invoices go out once the month has closed.
        $default = (new DateTimeImmutable('first day of last month', new DateTimeZone('UTC')))->format('Y-m');

        try {
            $month = CbnInvoiceService::parseMonth((string) ($q['month'] ?? $default));
        } catch (ApiException) {
            $month = CbnInvoiceService::parseMonth($default);
        }

        return $this->page($request, $response, $month);
    }

    public function issue(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $actor = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);

        try {
            $month = CbnInvoiceService::parseMonth((string) ($body['month'] ?? ''));
            $row = $this->invoices->issue($month, (int) $actor['id'], ClientContext::ip($request));
        } catch (ApiException $e) {
            $this->flash($request, 'error', $e->getMessage());

            return $this->redirect($response, '/console/invoices' . (isset($month) ? '?month=' . $month->format('Y-m') : ''));
        }

        $this->flash($request, 'success', "Invoice {$row['invoice_number']} issued for ₦" . number_format((float) $row['fee_ngn'], 2) . '.');

        return $this->redirect($response, '/console/invoices?month=' . $month->format('Y-m'));
    }

    public function download(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $file = $this->invoices->download((string) $args['uuid']);
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'Invoice not found.');

            return $this->redirect($response, '/console/invoices');
        } catch (ApiException $e) {
            $this->flash($request, 'error', $e->getMessage());

            return $this->redirect($response, '/console/invoices');
        }

        $response->getBody()->write($file['bytes']);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    public function markPaid(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $actor = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);

        return $this->act($request, $response, (string) $args['uuid'], fn (string $uuid) => $this->invoices->markPaid(
            $uuid,
            trim((string) ($body['paid_at'] ?? '')),
            is_string($body['payment_reference'] ?? null) ? $body['payment_reference'] : null,
            (int) $actor['id'],
            ClientContext::ip($request),
        ), 'Payment recorded.');
    }

    public function void(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $actor = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);

        return $this->act($request, $response, (string) $args['uuid'], fn (string $uuid) => $this->invoices->void(
            $uuid,
            (string) ($body['reason'] ?? ''),
            (int) $actor['id'],
            ClientContext::ip($request),
        ), 'Invoice voided. You can now issue a replacement for that month.');
    }

    public function saveSettings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $actor = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);
        $str = static fn (string $k, int $max): ?string => is_string($body[$k] ?? null) && trim($body[$k]) !== '' ? mb_substr(trim($body[$k]), 0, $max) : null;

        $data = [
            'number_prefix'       => $str('number_prefix', 20) ?? InvoiceSettingsRepository::DEFAULTS['number_prefix'],
            'addressee'           => $str('addressee', 1000) ?? InvoiceSettingsRepository::DEFAULTS['addressee'],
            'service_description' => $str('service_description', 2000) ?? InvoiceSettingsRepository::DEFAULTS['service_description'],
            'bank_account_name'   => $str('bank_account_name', 150),
            'bank_account_number' => $str('bank_account_number', 30),
            'bank_name'           => $str('bank_name', 150),
        ];

        if (preg_match('/^[A-Za-z0-9\-]{1,20}$/', $data['number_prefix']) !== 1) {
            $this->flash($request, 'error', 'The invoice number prefix may only use letters, digits and hyphens.');

            return $this->redirect($response, '/console/invoices#invoice-details');
        }

        $before = $this->settings->get();
        $this->settings->update($data, (int) $actor['id']);
        $this->audit->record((int) $actor['id'], 'invoice_settings.update', 'invoice_settings', 1, $before, $data, ClientContext::ip($request));
        $this->flash($request, 'success', 'Invoice details saved.');

        return $this->redirect($response, '/console/invoices#invoice-details');
    }

    /** @param callable(string):void $action */
    private function act(ServerRequestInterface $request, ResponseInterface $response, string $uuid, callable $action, string $ok): ResponseInterface
    {
        try {
            $action($uuid);
            $this->flash($request, 'success', $ok);
        } catch (NotFoundException) {
            $this->flash($request, 'error', 'Invoice not found.');
        } catch (ApiException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return $this->redirect($response, '/console/invoices');
    }

    private function page(ServerRequestInterface $request, ResponseInterface $response, DateTimeImmutable $month): ResponseInterface
    {
        $page = Pagination::fromRequest($request, 24);
        $list = $this->invoices->list($page->perPage, $page->offset(), null);

        return $this->render($request, $response, 'console/invoices/index', [
            'preview'  => $this->invoices->preview($month),
            'list'     => $page->envelope($list['rows'], $list['total']),
            'settings' => $this->settings->get(),
            'feeLabel' => Fees::percent(Fees::SERVICE_FEE_RATE),
            'today'    => (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d'),
        ]);
    }
}
