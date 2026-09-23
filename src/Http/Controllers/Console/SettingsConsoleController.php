<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\View\Renderer;
use App\Mail\MailerInterface;
use App\Mail\MailException;
use App\Audit\AuditLog;
use App\Http\Support\ClientContext;
use App\Security\TwoFactorService;
use App\Settings\CompanySettingsRepository;
use App\Settings\OperationalSettingsRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * /console/settings — admin-only (gated at the route level by
 * ConsoleRoleMiddleware, see AppFactory). Two independent sections on one
 * page: the document letterhead (Phase 6 follow-up) and operational/workflow
 * config — kept as two repositories/forms rather than one, since they change
 * for different reasons and by different people in practice.
 */
final class SettingsConsoleController extends AbstractConsoleController
{
    public function __construct(
        Renderer $view,
        private readonly CompanySettingsRepository $company,
        private readonly OperationalSettingsRepository $operational,
        private readonly MailerInterface $mailer,
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLog $audit,
    ) {
        parent::__construct($view);
    }

    /** Every render of this page also carries the (read-only) email status panel. */
    protected function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $template,
        array $data = [],
        int $status = 200,
    ): ResponseInterface {
        return parent::render(
            $request,
            $response,
            $template,
            $data + [
                'mail' => ['enabled' => $this->mailer->isEnabled(), 'describe' => $this->mailer->describe()],
                'security' => [
                    'require2fa'  => $this->operational->get()['require_2fa_admins'],
                    'meEnrolled'  => $this->twoFactor->isEnabled((int) ((array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE))['id']),
                ],
            ],
            $status,
        );
    }

    public function updateSecurity(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);
        $want = ((array) $request->getParsedBody())['require_2fa_admins'] ?? '0';
        $want = in_array($want, ['1', 1, true, 'on'], true);

        // Never let an admin switch on a rule they don't yet satisfy themselves —
        // it would just lock them into the enrolment page.
        if ($want && !$this->twoFactor->isEnabled((int) $user['id'])) {
            $this->flash($request, 'error', 'Set up two-factor on your own account first (My account), then require it for everyone.');

            return $this->redirect($response, '/console/settings');
        }

        $this->operational->setRequireTwoFactor($want, (int) $user['id']);
        $this->audit->record((int) $user['id'], $want ? 'settings.require_2fa_on' : 'settings.require_2fa_off', 'company_settings', 1, null, ['require_2fa_admins' => $want], ClientContext::ip($request));
        $this->flash($request, 'success', $want
            ? 'Two-factor is now required for admins and super admins. Anyone not yet enrolled will be asked to set it up at their next click.'
            : 'Two-factor is no longer required (people who already turned it on keep it).');

        return $this->redirect($response, '/console/settings');
    }

    public function sendTestEmail(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);

        if (!$this->mailer->isEnabled()) {
            $this->flash($request, 'error', 'Email is not configured on this server.');
            return $this->redirect($response, '/console/settings');
        }

        try {
            $this->mailer->send(
                (string) $user['email'],
                (string) ($user['full_name'] ?? ''),
                'Test email from ADWOL PIA',
                "This is a test message from the ADWOL PIA console.\n\nIf you can read it, outgoing email is working.\n",
            );
        } catch (MailException $e) {
            $this->flash($request, 'error', $e->getMessage());
            return $this->redirect($response, '/console/settings');
        }

        $this->flash($request, 'success', 'Test email sent to ' . $user['email'] . '. Check your inbox (and spam folder).');

        return $this->redirect($response, '/console/settings');
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'console/settings/edit', [
            'company'     => $this->company->getWithMeta(),
            'operational' => $this->operational->getWithMeta(),
            'errors'      => [],
            'opErrors'    => [],
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            return $this->render($request, $response, 'console/settings/edit', [
                'company' => [
                    'name' => $name,
                    'address' => (string) ($body['address'] ?? ''),
                    'representative_title' => (string) ($body['representative_title'] ?? ''),
                    'updated_at' => null, 'updated_by_name' => null,
                ],
                'operational' => $this->operational->getWithMeta(),
                'errors' => ['name' => 'Company name is required.'],
                'opErrors' => [],
            ], status: 422);
        }

        $user = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);

        $this->company->update([
            'name'                  => $name,
            'address'               => trim((string) ($body['address'] ?? '')) ?: null,
            'representative_title'  => trim((string) ($body['representative_title'] ?? '')) ?: 'Authorised Representative',
        ], (int) $user['id']);

        $this->flash($request, 'success', 'Company branding updated. New documents will use it immediately.');

        return $this->redirect($response, '/console/settings');
    }

    public function updateOperational(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $grace = filter_var($body['compliance_grace_hours'] ?? null, FILTER_VALIDATE_INT);

        if ($grace === false || $grace < 1 || $grace > 720) {
            return $this->render($request, $response, 'console/settings/edit', [
                'company'     => $this->company->getWithMeta(),
                'operational' => [
                    'compliance_grace_hours' => (string) ($body['compliance_grace_hours'] ?? ''),
                    'updated_at' => null, 'updated_by_name' => null,
                ],
                'errors'   => [],
                'opErrors' => ['compliance_grace_hours' => 'Enter a whole number of hours between 1 and 720.'],
            ], status: 422);
        }

        $user = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);
        $this->operational->update($grace, (int) $user['id']);

        $this->flash($request, 'success', 'Operational settings updated. Takes effect on the next compliance run.');

        return $this->redirect($response, '/console/settings');
    }
}
