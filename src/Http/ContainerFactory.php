<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AccessTokenService;
use App\Auth\OfflineSession;
use App\Auth\PasswordHasher;
use App\Auth\PinService;
use App\Auth\RateLimiter;
use App\Auth\RefreshTokenService;
use App\Auth\UserRepository;
use App\Compliance\ComplianceEvaluator;
use App\Compliance\ComplianceRepository;
use App\Documents\CciDataAssembler;
use App\Documents\DocumentNumberAllocator;
use App\Documents\DocumentRepository;
use App\Documents\DocumentService;
use App\Documents\DocumentSigner;
use App\Documents\DocumentStorage;
use App\Documents\PdfRenderer;
use App\Inspections\InspectionRepository;
use App\Returns\StatutoryReturnStorage;
use App\Invoicing\InvoiceStorage;
use App\Archive\ArchiveRepository;
use App\Archive\ArchiveService;
use App\Audit\AuditLog;
use App\Auth\PasswordResetService;
use App\Backup\BackupService;
use App\Notifications\AttentionFeed;
use App\Notifications\DigestService;
use App\Mail\MailerInterface;
use App\Mail\NullMailer;
use App\Mail\SmtpMailer;
use App\Users\UserAdminRepository;
use App\Backup\DatabaseDumper;
use App\Office\ClientRepository;
use App\Office\ClientService;
use App\Documents\ShipmentDetailsRepository;
use App\Documents\TradeDetailsRepository;
use App\Import\ImportService;
use App\Inspections\SchedulingService;
use App\Users\UserAdminService;
use App\Records\RecordAttachmentRepository;
use App\Records\RecordAttachmentService;
use App\Security\SecretBox;
use App\Security\SecurityOverview;
use App\Security\Totp;
use App\Security\TwoFactorService;
use App\Settings\CompanySettingsRepository;
use App\Settings\OperationalSettingsRepository;
use App\Http\Middleware\SessionMiddleware;
use App\Http\View\Renderer;
use App\Logging\LoggerFactory;
use App\Inspections\AttachmentStorage;
use App\Office\ConsignmentRepository;
use App\Office\ConsignmentService;
use App\Office\InspectionRequestRepository;
use App\Office\InspectionRequestService;
use App\Support\Database;
use DI\Container;
use DI\ContainerBuilder;
use PDO;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the PHP-DI container.
 *
 * Infrastructure and any service that needs scalar config is defined
 * explicitly here, so it is obvious where each value comes from. Plain
 * class-to-class services (repositories, AuthService, controllers, middleware)
 * are left to autowiring.
 */
final class ContainerFactory
{
    /** @param array<string,mixed>|null $settings Overrides config/settings.php (tests). */
    public static function create(?array $settings = null): Container
    {
        $settings ??= require \dirname(__DIR__, 2) . '/config/settings.php';

        $builder = new ContainerBuilder();

        // Compilation is disabled: shared hosting has no build step and the
        // container is small. Revisit if boot time becomes measurable.
        $builder->addDefinitions([
            'settings' => $settings,

            LoggerInterface::class => fn () => LoggerFactory::create($settings['log']),

            PDO::class => fn () => Database::connect($settings['db']),

            // --- Auth (Phase 2) -------------------------------------------
            PasswordHasher::class => fn () => new PasswordHasher($settings['security']['argon2']),

            AccessTokenService::class => fn () => new AccessTokenService(
                secret: $settings['security']['jwt_secret'],
                issuer: $settings['security']['jwt_issuer'],
                ttlSeconds: $settings['security']['jwt_access_ttl'],
            ),

            RefreshTokenService::class => fn (ContainerInterface $c) => new RefreshTokenService(
                $c->get(PDO::class),
                $settings['security']['offline_reauth_days'],
            ),

            RateLimiter::class => fn (ContainerInterface $c) => new RateLimiter(
                $c->get(PDO::class),
                maxHits: $settings['security']['auth_rate_limit']['max'],
                windowSeconds: $settings['security']['auth_rate_limit']['window'],
            ),

            // --- Offline session / PIN (Phase 8) --------------------------
            OfflineSession::class => fn () => new OfflineSession(
                $settings['security']['offline_reauth_days'],
            ),

            PinService::class => fn (ContainerInterface $c) => new PinService(
                $c->get(UserRepository::class),
                $c->get(PasswordHasher::class),
                minLength: $settings['security']['pin']['min_length'],
                maxLength: $settings['security']['pin']['max_length'],
            ),

            // --- Office (Phase 3) --------------------------------------------
            InspectionRequestService::class => fn (ContainerInterface $c) => new InspectionRequestService(
                $c->get(InspectionRequestRepository::class),
                $c->get(ConsignmentRepository::class),
                noticeWindowHours: $settings['workflow']['notice_window_hours'],
            ),

            // --- Console (Phase 3b) ----------------------------------------
            Renderer::class => fn () => new Renderer(
                $settings['app']['base_path'] . '/templates',
                ['app_name' => $settings['app']['name']],
            ),

            SessionMiddleware::class => fn () => new SessionMiddleware(
                secureCookie: $settings['app']['env'] === 'production',
            ),

            // --- Inspections / sync (Phase 4) -----------------------------
            AttachmentStorage::class => fn () => new AttachmentStorage(
                $settings['storage']['path'] . DIRECTORY_SEPARATOR . 'attachments',
                (int) $settings['storage']['attachment_max_bytes'],
            ),

            // --- Documents (Phase 6) ---------------------------------------
            DocumentSigner::class => fn () => new DocumentSigner($settings['security']['hmac_document_key']),

            DocumentStorage::class => fn () => new DocumentStorage(
                $settings['storage']['path'] . DIRECTORY_SEPARATOR . 'documents',
            ),

            PdfRenderer::class => fn () => new PdfRenderer(
                $settings['storage']['path'] . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'mpdf',
            ),

            DocumentService::class => fn (ContainerInterface $c) => new DocumentService(
                $c->get(InspectionRepository::class),
                $c->get(CciDataAssembler::class),
                $c->get(DocumentNumberAllocator::class),
                $c->get(DocumentSigner::class),
                $c->get(DocumentStorage::class),
                $c->get(DocumentRepository::class),
                $c->get(Renderer::class),
                $c->get(PdfRenderer::class),
                $c->get(CompanySettingsRepository::class),
            ),

            CompanySettingsRepository::class => fn (ContainerInterface $c) => new CompanySettingsRepository(
                $c->get(PDO::class),
                $settings['pia'],
            ),

            // Office documents on clients/consignments: the same hardened
            // AttachmentStorage as inspector photos, in its own directory.
            RecordAttachmentService::class => fn (ContainerInterface $c) => new RecordAttachmentService(
                $c->get(RecordAttachmentRepository::class),
                new AttachmentStorage(
                    $settings['storage']['path'] . DIRECTORY_SEPARATOR . 'record-attachments',
                    (int) $settings['storage']['attachment_max_bytes'],
                ),
                $c->get(ClientRepository::class),
                $c->get(ConsignmentRepository::class),
            ),

            BackupService::class => fn (ContainerInterface $c) => new BackupService(
                $c->get(PDO::class),
                new DatabaseDumper($c->get(PDO::class)),
                $settings['storage']['path'] . DIRECTORY_SEPARATOR . 'backups',
                [
                    'attachments'        => $settings['storage']['path'] . DIRECTORY_SEPARATOR . 'attachments',
                    'documents'          => $settings['storage']['path'] . DIRECTORY_SEPARATOR . 'documents',
                    'returns'            => $settings['storage']['path'] . DIRECTORY_SEPARATOR . 'returns',
                    'invoices'           => $settings['storage']['path'] . DIRECTORY_SEPARATOR . 'invoices',
                    'record-attachments' => $settings['storage']['path'] . DIRECTORY_SEPARATOR . 'record-attachments',
                ],
            ),

            // --- Two-factor (TOTP) ---------------------------------------------
            // The seed is encrypted under a key derived from APP_KEY (see SecretBox).
            SecretBox::class => fn () => new SecretBox($settings['app']['key']),

            TwoFactorService::class => fn (ContainerInterface $c) => new TwoFactorService(
                $c->get(PDO::class),
                new Totp(),
                $c->get(SecretBox::class),
                $settings['app']['key'],
                $settings['app']['name'],
            ),

            // --- Outgoing email -------------------------------------------------
            // A real SMTP transport only when MAIL_HOST is set; otherwise a
            // no-op that logs the subject, so features degrade instead of failing.
            MailerInterface::class => fn (ContainerInterface $c) => $settings['mail']['host'] !== ''
                ? new SmtpMailer($settings['mail'], $c->get(LoggerInterface::class))
                : new NullMailer($c->get(LoggerInterface::class)),

            PasswordResetService::class => fn (ContainerInterface $c) => new PasswordResetService(
                $c->get(PDO::class),
                $c->get(UserRepository::class),
                $c->get(UserAdminRepository::class),
                $c->get(RefreshTokenService::class),
                $c->get(PasswordHasher::class),
                $c->get(MailerInterface::class),
                $c->get(AuditLog::class),
                $settings['app']['url'],
                $settings['app']['name'],
            ),

            DigestService::class => fn (ContainerInterface $c) => new DigestService(
                $c->get(PDO::class),
                $c->get(AttentionFeed::class),
                $c->get(MailerInterface::class),
                $settings['app']['url'],
                $settings['app']['name'],
            ),

            SecurityOverview::class => fn (ContainerInterface $c) => new SecurityOverview(
                $c->get(PDO::class),
                $settings['security']['auth_rate_limit']['max'],
                $settings['security']['auth_rate_limit']['window'],
            ),

            // --- Compliance / statutory returns (Phase 7) -------------------
            OperationalSettingsRepository::class => fn (ContainerInterface $c) => new OperationalSettingsRepository(
                $c->get(PDO::class),
                ['compliance_grace_hours' => $settings['workflow']['compliance_grace_hours']],
            ),

            ComplianceEvaluator::class => fn (ContainerInterface $c) => new ComplianceEvaluator(
                $c->get(PDO::class),
                $c->get(ComplianceRepository::class),
                graceHours: $c->get(OperationalSettingsRepository::class)->get()['compliance_grace_hours'],
                strikeThreshold: $settings['workflow']['compliance_strike_threshold'],
            ),

            StatutoryReturnStorage::class => fn () => new StatutoryReturnStorage(
                $settings['storage']['path'] . DIRECTORY_SEPARATOR . 'returns',
            ),

            // Bulk CSV import: every service a single-record form uses, so the
            // same rules apply to a spreadsheet row.
            ImportService::class => fn (ContainerInterface $c) => new ImportService(
                $c->get(PDO::class),
                $c->get(ClientService::class),
                $c->get(ConsignmentService::class),
                $c->get(AuditLog::class),
                $c->get(InspectionRequestService::class),
                $c->get(TradeDetailsRepository::class),
                $c->get(ShipmentDetailsRepository::class),
                $c->get(UserAdminService::class),
                $c->get(SchedulingService::class),
            ),

            ArchiveService::class => fn (ContainerInterface $c) => new ArchiveService(
                $c->get(ArchiveRepository::class),
                $c->get(DocumentStorage::class),
                $c->get(InvoiceStorage::class),
                $c->get(StatutoryReturnStorage::class),
                $c->get(DocumentSigner::class),
                $settings['storage']['path'] . DIRECTORY_SEPARATOR . 'cache',
            ),

            InvoiceStorage::class => fn () => new InvoiceStorage(
                $settings['storage']['path'] . DIRECTORY_SEPARATOR . 'invoices',
            ),
        ]);

        return $builder->build();
    }
}
