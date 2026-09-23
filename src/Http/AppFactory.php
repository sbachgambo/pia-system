<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\PinController;
use App\Http\Controllers\Auth\RefreshController;
use App\Http\Controllers\Console\AccountConsoleController;
use App\Http\Controllers\Console\AuditLogConsoleController;
use App\Http\Controllers\Console\TwoFactorChallengeController;
use App\Http\Controllers\Console\BackupConsoleController;
use App\Http\Controllers\Console\CalendarConsoleController;
use App\Http\Controllers\Console\NotificationsConsoleController;
use App\Http\Controllers\Console\PasswordResetConsoleController;
use App\Http\Controllers\Console\RecordAttachmentConsoleController;
use App\Http\Middleware\AttentionCountMiddleware;
use App\Http\Middleware\BoardReadOnlyMiddleware;
use App\Http\Controllers\Console\ClientConsoleController;
use App\Http\Controllers\Console\ArchiveConsoleController;
use App\Http\Controllers\Console\CbnInvoiceConsoleController;
use App\Http\Controllers\Console\ExportConsoleController;
use App\Http\Controllers\Console\ImportConsoleController;
use App\Http\Controllers\Console\IncomeConsoleController;
use App\Http\Controllers\Console\SearchConsoleController;
use App\Http\Controllers\Console\SecurityConsoleController;
use App\Http\Controllers\Console\UserConsoleController;
use App\Http\Controllers\Console\ConsignmentConsoleController;
use App\Http\Controllers\Console\ConsoleAuthController;
use App\Http\Controllers\Console\DashboardController;
use App\Http\Controllers\Console\InspectionRequestConsoleController;
use App\Http\Controllers\Compliance\ComplianceController;
use App\Http\Controllers\Console\ComplianceConsoleController;
use App\Http\Controllers\Console\ReviewConsoleController;
use App\Http\Controllers\Console\SettingsConsoleController;
use App\Http\Controllers\Console\StatutoryReturnConsoleController;
use App\Http\Controllers\Documents\DocumentController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\Inspections\AssignedController;
use App\Http\Controllers\Inspections\AttachmentController;
use App\Http\Controllers\Inspections\ReviewController;
use App\Http\Controllers\Inspections\SyncController;
use App\Http\Controllers\Office\ClientController;
use App\Http\Controllers\Office\ConsignmentController;
use App\Http\Controllers\Office\InspectionRequestController;
use App\Http\Controllers\Office\SchedulingController;
use App\Http\Controllers\Returns\StatutoryReturnController;
use App\Http\Handlers\JsonErrorHandler;
use App\Http\Middleware\AuthRateLimitMiddleware;
use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Middleware\ConsoleRoleMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\JwtAuthMiddleware;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SessionMiddleware;
use DI\Container;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Routing\RouteCollectorProxy;

/**
 * Assembles the Slim application: container, timezone, middleware stack,
 * error handling, and route table.
 *
 * Middleware execution order (outermost -> innermost):
 *   RequestId  ->  Error  ->  Routing  ->  BodyParsing  ->  route
 *
 * Slim runs middleware LIFO, so they are add()ed in reverse of that order.
 * RequestId is outermost so (a) every response — including error envelopes —
 * carries the X-Request-Id header, and (b) the id attribute is already set on
 * the request the error handler receives. Error sits just inside it so it
 * still catches routing/body-parsing/route exceptions.
 */
final class AppFactory
{
    /** @param array<string,mixed>|null $settings Test override for config/settings.php. */
    public static function create(?array $settings = null): App
    {
        $container = ContainerFactory::create($settings);
        $settings  = $container->get('settings');

        date_default_timezone_set($settings['app']['timezone'] ?? 'UTC');

        self::registerControllers($container);

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();

        self::registerRoutes($app);

        // --- Middleware: add()ed in reverse of execution order (LIFO) -------
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        $errorMiddleware = $app->addErrorMiddleware(
            displayErrorDetails: (bool) $settings['app']['debug'],
            logErrors: true,
            logErrorDetails: (bool) $settings['app']['debug'],
            logger: $container->get(LoggerInterface::class),
        );
        $errorMiddleware->setDefaultErrorHandler(
            new JsonErrorHandler(
                $container->get(LoggerInterface::class),
                (bool) $settings['app']['debug'],
            ),
        );

        $app->add(RequestIdMiddleware::class); // outermost

        return $app;
    }

    private static function registerControllers(Container $container): void
    {
        $container->set(
            HealthController::class,
            static fn (Container $c): HealthController => new HealthController(
                $c->get(PDO::class),
                (string) $c->get('settings')['app']['env'],
            ),
        );
    }

    private static function registerRoutes(App $app): void
    {
        // Liveness/readiness probe — unauthenticated by design.
        $app->get('/health', HealthController::class);

        // --- Auth (brief §5) ---------------------------------------------
        // The whole group is rate-limited (§7). login + refresh are public;
        // everything else requires a valid access token.
        $app->group('/api/auth', function (RouteCollectorProxy $group): void {
            $group->post('/login', LoginController::class);
            $group->post('/refresh', RefreshController::class);
        })->add(AuthRateLimitMiddleware::class);

        // JWT-protected API (everything except /api/auth/{login,refresh}).
        $app->group('/api', function (RouteCollectorProxy $group): void {
            $group->get('/me', MeController::class);
            // Offline unlock PIN (D4 / Phase 8, DEV-11). Setting one re-checks
            // the account password inside PinService.
            $group->post('/me/pin', PinController::class . ':set');
            $group->delete('/me/pin', PinController::class . ':clear');
            // Attachment download (brief §5): any authenticated user; the
            // service enforces "assigned inspector or office" (DEV-3).
            $group->get('/attachments/{id:[0-9]+}', AttachmentController::class . ':download');
        })->add(JwtAuthMiddleware::class);

        // --- Field / PWA sync API (Phase 4, brief §5) -------------------
        // JWT + `inspector` role. Idempotent on the client-generated uuid (§3).
        $app->group('/api/inspections', function (RouteCollectorProxy $group): void {
            $group->get('/assigned', AssignedController::class);
            $group->post('/sync', SyncController::class);
            $group->post('/attachments/{clientUuid}', AttachmentController::class . ':upload');
        })
            ->add(new RoleMiddleware(['inspector']))
            ->add(JwtAuthMiddleware::class);

        // --- Office review of synced inspections (Phase 5, brief §5 + D1) --
        // Same /api/inspections prefix, but office roles; the per-inspection
        // review actions. `{uuid}` is regex-pinned so it never shadows the
        // static /assigned and /sync routes above.
        $app->group('/api/inspections', function (RouteCollectorProxy $group): void {
            $group->get('/{uuid:[0-9a-fA-F-]{36}}', ReviewController::class . ':show');
            $group->post('/{uuid:[0-9a-fA-F-]{36}}/amend', ReviewController::class . ':amend');
            $group->post('/{uuid:[0-9a-fA-F-]{36}}/finalize', ReviewController::class . ':finalize');
            $group->post('/{uuid:[0-9a-fA-F-]{36}}/reject', ReviewController::class . ':reject');
            // Phase 6 (D2): generate/list the CCI/NNCI for a finalized inspection.
            // Not in the §5 table beyond the download route below (DEV-13).
            $group->post('/{uuid:[0-9a-fA-F-]{36}}/documents', DocumentController::class . ':generate');
            $group->get('/{uuid:[0-9a-fA-F-]{36}}/documents', DocumentController::class . ':index');
        })
            ->add(new RoleMiddleware(['office_reviewer', 'admin', 'super_admin']))
            ->add(JwtAuthMiddleware::class);

        // --- Documents (Phase 6, brief §5: GET /api/documents/{uuid}) -----
        $app->group('/api/documents', function (RouteCollectorProxy $group): void {
            $group->get('/{uuid:[0-9a-fA-F-]{36}}', DocumentController::class . ':download');
        })
            ->add(new RoleMiddleware(['office_reviewer', 'admin', 'super_admin']))
            ->add(JwtAuthMiddleware::class);

        // --- Compliance tracking + statutory returns (Phase 7) -----------
        // Admin/super_admin only: inspector performance + regulator filings,
        // a step up in sensitivity from routine office_reviewer work.
        $app->group('/api/compliance', function (RouteCollectorProxy $group): void {
            $group->get('', ComplianceController::class . ':index');
            $group->post('/evaluate', ComplianceController::class . ':evaluate');
        })
            ->add(new RoleMiddleware(['admin', 'super_admin']))
            ->add(JwtAuthMiddleware::class);

        $app->group('/api/statutory-returns', function (RouteCollectorProxy $group): void {
            $group->post('/generate', StatutoryReturnController::class . ':generate'); // brief §5
            $group->get('', StatutoryReturnController::class . ':index');
            $group->post('/{uuid:[0-9a-fA-F-]{36}}/submit', StatutoryReturnController::class . ':submit');
            $group->get('/{uuid:[0-9a-fA-F-]{36}}/download', StatutoryReturnController::class . ':download');
        })
            ->add(new RoleMiddleware(['admin', 'super_admin']))
            ->add(JwtAuthMiddleware::class);

        // --- Office console API (Phase 3) --------------------------------
        // Server-side CRUD for the office. JWT + office roles only
        // (inspectors are blocked). The browser console (Phase 3b) renders
        // on top of these same services.
        $app->group('/api/office', function (RouteCollectorProxy $group): void {
            $group->get('/clients', ClientController::class . ':index');
            $group->post('/clients', ClientController::class . ':create');
            $group->get('/clients/{uuid}', ClientController::class . ':show');
            $group->patch('/clients/{uuid}', ClientController::class . ':update');

            $group->get('/consignments', ConsignmentController::class . ':index');
            $group->post('/consignments', ConsignmentController::class . ':create');
            $group->get('/consignments/{uuid}', ConsignmentController::class . ':show');
            $group->patch('/consignments/{uuid}', ConsignmentController::class . ':update');

            $group->get('/inspection-requests', InspectionRequestController::class . ':index');
            $group->post('/inspection-requests', InspectionRequestController::class . ':create');
            $group->get('/inspection-requests/{uuid}', InspectionRequestController::class . ':show');
            $group->patch('/inspection-requests/{uuid}', InspectionRequestController::class . ':reschedule');
            $group->post('/inspection-requests/{uuid}/transition', InspectionRequestController::class . ':transition');
            // Phase 4 (A18): assign to an inspector, creating the inspection row.
            $group->post('/inspection-requests/{uuid}/schedule', SchedulingController::class);

            // Phase 5: the office review queue (per-inspection actions live on
            // the /api/inspections/{uuid}/* routes above).
            $group->get('/inspections', ReviewController::class . ':queue');
        })
            ->add(new RoleMiddleware(['office_reviewer', 'admin', 'super_admin']))
            ->add(JwtAuthMiddleware::class);

        // --- Office browser console (Phase 3b) --------------------------
        // Server-rendered HTML. Cookie session + CSRF (Q4). The authed pages
        // sit in a nested group behind ConsoleAuthMiddleware; /login is not.
        $app->group('/console', function (RouteCollectorProxy $group): void {
            $group->get('/login', ConsoleAuthController::class . ':showLogin');
            // Phase 9 hardening: the console login had no brute-force protection
            // at all (only /api/auth/* was rate-limited, per §5/§7's literal
            // wording) even though it's how admin/super_admin accounts sign in.
            // Reuses the same bucket-per-IP-per-path limiter as the API; a 429
            // here renders as JSON rather than a console page, matching the
            // console's existing (documented, Phase 3b) accepted limitation for
            // errors that reach the global handler — a content-negotiated HTML
            // error page is still a follow-up, not a regression introduced here.
            $group->post('/login', ConsoleAuthController::class . ':login')->add(AuthRateLimitMiddleware::class);
            $group->post('/logout', ConsoleAuthController::class . ':logout');

            // Second step of sign-in for accounts with two-factor (no session yet;
            // the POST is rate-limited like the password step).
            $group->get('/two-factor', TwoFactorChallengeController::class . ':show');
            $group->post('/two-factor', TwoFactorChallengeController::class . ':verify')->add(AuthRateLimitMiddleware::class);

            // Self-service password reset (only offered when email is configured).
            // Both POSTs share the sign-in rate limiter's bucket-per-IP-per-path.
            $group->get('/forgot-password', PasswordResetConsoleController::class . ':showForgot');
            $group->post('/forgot-password', PasswordResetConsoleController::class . ':sendLink')->add(AuthRateLimitMiddleware::class);
            $group->get('/reset-password', PasswordResetConsoleController::class . ':showReset');
            $group->post('/reset-password', PasswordResetConsoleController::class . ':doReset')->add(AuthRateLimitMiddleware::class);

            $group->group('', function (RouteCollectorProxy $authed): void {
                $authed->get('', DashboardController::class . ':index');
                $authed->get('/search', SearchConsoleController::class . ':index');
                $authed->get('/calendar', CalendarConsoleController::class . ':index');
                $authed->get('/attention', NotificationsConsoleController::class . ':index');

                // Archive of every generated document (admin-only kinds are filtered inside).
                $authed->get('/archive', ArchiveConsoleController::class . ':index');
                $authed->get('/archive/download', ArchiveConsoleController::class . ':download');

                // My account: own password + two-factor (every console role).
                $authed->get('/account', AccountConsoleController::class . ':index');
                $authed->post('/account/password', AccountConsoleController::class . ':changePassword');
                $authed->post('/account/two-factor/start', AccountConsoleController::class . ':startTwoFactor');
                $authed->get('/account/two-factor/setup', AccountConsoleController::class . ':setup');
                $authed->post('/account/two-factor/confirm', AccountConsoleController::class . ':confirmTwoFactor');
                $authed->post('/account/two-factor/recovery-codes', AccountConsoleController::class . ':regenerateRecoveryCodes');
                $authed->post('/account/two-factor/disable', AccountConsoleController::class . ':disableTwoFactor');

                // Supporting documents on clients / NXP records.
                $authed->post('/clients/{uuid}/attachments', RecordAttachmentConsoleController::class . ':uploadForClient');
                $authed->post('/nxp/{uuid}/attachments', RecordAttachmentConsoleController::class . ':uploadForConsignment');
                $authed->get('/attachments/{uuid}', RecordAttachmentConsoleController::class . ':download');
                $authed->post('/attachments/{uuid}/delete', RecordAttachmentConsoleController::class . ':delete');

                // CSV exports (each one is written to the audit log).
                $authed->get('/clients/export', ExportConsoleController::class . ':clients');
                $authed->get('/nxp/export', ExportConsoleController::class . ':consignments');
                $authed->get('/inspections/export', ExportConsoleController::class . ':inspections');

                $authed->get('/clients', ClientConsoleController::class . ':index');
                $authed->get('/clients/new', ClientConsoleController::class . ':new');
                $authed->post('/clients', ClientConsoleController::class . ':create');
                $authed->get('/clients/{uuid}/edit', ClientConsoleController::class . ':edit');
                $authed->post('/clients/{uuid}', ClientConsoleController::class . ':update');

                $authed->get('/nxp', ConsignmentConsoleController::class . ':index');
                $authed->get('/nxp/new', ConsignmentConsoleController::class . ':new');
                $authed->post('/nxp', ConsignmentConsoleController::class . ':create');
                $authed->get('/nxp/{uuid}/edit', ConsignmentConsoleController::class . ':edit');
                $authed->post('/nxp/{uuid}', ConsignmentConsoleController::class . ':update');
                // Phase 6: optional trade/banking details that feed the CCI.
                $authed->post('/nxp/{uuid}/trade-details', ConsignmentConsoleController::class . ':saveTradeDetails');
                // "Consignments" was renamed "NXP" (Sep 2026); old links and bookmarks
                // land on the same page under its new address.
                $authed->get('/consignments[/{rest:.*}]', function (ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface {
                    $rest = isset($args['rest']) && $args['rest'] !== '' ? '/' . $args['rest'] : '';
                    $query = $request->getUri()->getQuery();

                    return $response->withStatus(301)->withHeader('Location', '/console/nxp' . $rest . ($query !== '' ? '?' . $query : ''));
                });

                $authed->get('/inspection-requests', InspectionRequestConsoleController::class . ':index');
                $authed->get('/inspection-requests/new', InspectionRequestConsoleController::class . ':new');
                $authed->post('/inspection-requests', InspectionRequestConsoleController::class . ':create');
                $authed->get('/inspection-requests/{uuid}', InspectionRequestConsoleController::class . ':show');
                $authed->post('/inspection-requests/{uuid}/transition', InspectionRequestConsoleController::class . ':transition');
                $authed->post('/inspection-requests/{uuid}/reschedule', InspectionRequestConsoleController::class . ':reschedule');
                $authed->post('/inspection-requests/{uuid}/schedule', InspectionRequestConsoleController::class . ':schedule');

                // Phase 5: inspection review queue + amend / finalise / reject.
                $authed->get('/inspections', ReviewConsoleController::class . ':index');
                $authed->get('/inspections/{uuid}', ReviewConsoleController::class . ':show');
                $authed->post('/inspections/{uuid}/amend', ReviewConsoleController::class . ':amend');
                $authed->post('/inspections/{uuid}/finalize', ReviewConsoleController::class . ':finalize');
                $authed->post('/inspections/{uuid}/reject', ReviewConsoleController::class . ':reject');
                // Phase 6: shipment/NESS details (feed the CCI) + manual (re)generate + download.
                $authed->post('/inspections/{uuid}/shipment-details', ReviewConsoleController::class . ':saveShipmentDetails');
                $authed->post('/inspections/{uuid}/documents', ReviewConsoleController::class . ':generateDocument');
                $authed->get('/inspections/{uuid}/documents/{docUuid}', ReviewConsoleController::class . ':downloadDocument');

                // Admin-only pages (Phase 6/7 + Phase 9 hardening): gated at the
                // route level by ConsoleRoleMiddleware, not just a per-action
                // check inside each controller — one place to audit, and a new
                // admin-only action added later inherits the gate automatically.
                // Oversight pages: admins and the Board of Directors. Viewing and
                // downloading only — every change is in the admin group below,
                // and BoardReadOnlyMiddleware refuses any write by a board user.
                $authed->group('', function (RouteCollectorProxy $view): void {
                    // Service-fee income (0.35% of FOB on issued CCIs).
                    $view->get('/income', IncomeConsoleController::class . ':index');
                    $view->get('/income/card', IncomeConsoleController::class . ':card');
                    $view->get('/income/export', IncomeConsoleController::class . ':export');

                    $view->get('/invoices', CbnInvoiceConsoleController::class . ':index');
                    $view->get('/invoices/{uuid}/download', CbnInvoiceConsoleController::class . ':download');
                    $view->get('/statutory-returns', StatutoryReturnConsoleController::class . ':index');
                    $view->get('/statutory-returns/{uuid}/download', StatutoryReturnConsoleController::class . ':download');
                    $view->get('/compliance', ComplianceConsoleController::class . ':index');
                    $view->get('/audit-log', AuditLogConsoleController::class . ':index');
                })->add(new ConsoleRoleMiddleware(['admin', 'super_admin', 'board']));

                $authed->group('', function (RouteCollectorProxy $admin): void {
                    $admin->get('/settings', SettingsConsoleController::class . ':edit');
                    $admin->post('/settings', SettingsConsoleController::class . ':update');
                    $admin->post('/settings/operational', SettingsConsoleController::class . ':updateOperational');
                    $admin->post('/settings/test-email', SettingsConsoleController::class . ':sendTestEmail');
                    $admin->post('/settings/security', SettingsConsoleController::class . ':updateSecurity');


                    // Bulk import (previews first, writes all-or-nothing).
                    $admin->get('/import', ImportConsoleController::class . ':index');
                    $admin->get('/import/template', ImportConsoleController::class . ':template');
                    $admin->post('/import/preview', ImportConsoleController::class . ':preview');
                    $admin->post('/import/commit', ImportConsoleController::class . ':commit');

                    $admin->get('/users/export', ExportConsoleController::class . ':users');
                    $admin->get('/users', UserConsoleController::class . ':index');
                    $admin->get('/users/new', UserConsoleController::class . ':new');
                    $admin->post('/users', UserConsoleController::class . ':create');
                    $admin->get('/users/{uuid}/edit', UserConsoleController::class . ':edit');
                    $admin->post('/users/{uuid}', UserConsoleController::class . ':update');
                    $admin->post('/users/{uuid}/reset-password', UserConsoleController::class . ':resetPassword');
                    $admin->post('/users/{uuid}/toggle-status', UserConsoleController::class . ':toggleStatus');
                    $admin->post('/users/{uuid}/sign-out', UserConsoleController::class . ':signOutEverywhere');
                    $admin->post('/users/{uuid}/two-factor/reset', UserConsoleController::class . ':resetTwoFactor');

                    $admin->get('/security', SecurityConsoleController::class . ':index');

                    $admin->post('/compliance/evaluate', ComplianceConsoleController::class . ':evaluate');

                    // Monthly service-fee invoice to the CBN.
                    $admin->post('/invoices', CbnInvoiceConsoleController::class . ':issue');
                    $admin->post('/invoices/settings', CbnInvoiceConsoleController::class . ':saveSettings');
                    $admin->post('/invoices/{uuid}/paid', CbnInvoiceConsoleController::class . ':markPaid');
                    $admin->post('/invoices/{uuid}/void', CbnInvoiceConsoleController::class . ':void');

                    $admin->post('/statutory-returns/generate', StatutoryReturnConsoleController::class . ':generate');
                    $admin->post('/statutory-returns/{uuid}/submit', StatutoryReturnConsoleController::class . ':submit');
                })->add(new ConsoleRoleMiddleware(['admin', 'super_admin']));

                // Backups hold every password hash and all client data —
                // super_admin only, a step above the admin group.
                $authed->group('/backups', function (RouteCollectorProxy $bk): void {
                    $bk->get('', BackupConsoleController::class . ':index');
                    $bk->post('', BackupConsoleController::class . ':create');
                    // The archive name travels as a parameter, not a path segment:
                    // a trailing ".zip" in the URL is routed as a static-file
                    // request by some servers (PHP's built-in one, for a start).
                    $bk->get('/download', BackupConsoleController::class . ':download');
                    $bk->post('/delete', BackupConsoleController::class . ':delete');
                })->add(new ConsoleRoleMiddleware(['super_admin']));
            })
                // LIFO: ConsoleAuthMiddleware runs first, then the bell count
                // (which needs the signed-in user's role), then the board's
                // read-only gate.
                ->add(new BoardReadOnlyMiddleware())
                ->add(AttentionCountMiddleware::class)
                ->add(ConsoleAuthMiddleware::class);
        })
            ->add(CsrfMiddleware::class)
            ->add(SessionMiddleware::class);

        // Base path: a landing page so a first-time browser hit has somewhere
        // useful to go (office console vs. field inspector PWA) instead of a
        // bare API stub. Machine clients have /health; this route is human-only.
        $app->get('/', LandingController::class);
    }
}
