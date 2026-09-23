<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Middleware\ConsoleAuthMiddleware;
use App\Http\Support\ClientContext;
use App\Http\Support\Query;
use App\Http\View\Renderer;
use App\Import\CsvReader;
use App\Import\ImportService;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * /console/import — load any kind of office record from a spreadsheet
 * (clients, NXP records, inspection requests, trade / shipment details, users).
 *
 * Two steps on purpose: the upload only ever previews (the import runs for real
 * against the database and is rolled back), and nothing is written until the
 * person confirms what they have just been shown. The file is held in their own
 * session between the two steps, so the confirm step imports exactly the bytes
 * that were previewed and nothing can be swapped in between.
 *
 * Admin-only, via the route group.
 */
final class ImportConsoleController extends AbstractConsoleController
{
    private const SESSION_CSV = 'import_pending_csv';
    private const SESSION_TYPE = 'import_pending_type';
    private const SESSION_NAME = 'import_pending_name';

    public function __construct(Renderer $view, private readonly ImportService $imports)
    {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $type = $this->type((string) (Query::params($request)['type'] ?? 'clients'));

        return $this->page($request, $response, $type, null, null);
    }

    /** Step 1: read the uploaded file, dry-run it, show what would happen. */
    public function preview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $type = $this->type((string) ($body['type'] ?? 'clients'));

        try {
            [$csv, $filename] = $this->uploadedCsv($request);
            $actor = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);
            $result = $this->imports->preview($type, $csv, isset($actor['id']) ? (int) $actor['id'] : null);
        } catch (ApiException $e) {
            $this->clearPending($request);

            return $this->page($request, $response, $type, null, $e->getMessage(), 422);
        }

        $session = $this->session($request);
        $session->set(self::SESSION_CSV, $csv);
        $session->set(self::SESSION_TYPE, $type);
        $session->set(self::SESSION_NAME, $filename);

        return $this->page($request, $response, $type, $result + ['filename' => $filename], null);
    }

    /** Step 2: import the previewed file for real. */
    public function commit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->session($request);
        $csv = $session->get(self::SESSION_CSV);
        $type = $session->get(self::SESSION_TYPE);

        if (!is_string($csv) || !is_string($type)) {
            $this->flash($request, 'error', 'That import has expired. Upload the file again.');

            return $this->redirect($response, '/console/import');
        }

        $actor = (array) $request->getAttribute(ConsoleAuthMiddleware::ATTRIBUTE);

        try {
            $result = $this->imports->commit($type, $csv, (int) $actor['id'], ClientContext::ip($request));
        } catch (ApiException $e) {
            $this->clearPending($request);

            return $this->page($request, $response, $this->type($type), null, $e->getMessage(), 422);
        }

        $this->clearPending($request);

        if ($result['failed'] > 0) {
            // Something changed under us between preview and confirm (another
            // admin added a clashing record, say) — nothing was written.
            return $this->page($request, $response, $this->type($type), $result, 'Nothing was imported: some rows no longer pass. Check the list below and upload a corrected file.', 422);
        }

        [, $one, $many] = ImportService::LABELS[$type];
        $verb = in_array($type, ['trade_details', 'shipment_details'], true) ? 'Updated' : 'Imported';
        $message = "{$verb} {$result['ok']} " . ($result['ok'] === 1 ? $one : $many) . '.';
        if ($type === 'users') {
            $message .= ' Accounts imported without a password have a random one: the person uses "Forgot password", or you reset it on their user page.';
        }
        $this->flash($request, 'success', $message);

        return $this->redirect($response, ImportService::RETURN_TO[$type]);
    }

    /** A CSV with the expected header row and one example line. */
    public function template(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $type = $this->type((string) (Query::params($request)['type'] ?? 'clients'));
        $response->getBody()->write(ImportService::templateFor($type));

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="adwol-' . $type . '-template.csv"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @return array{0:string,1:string} the file's text and its name
     * @throws ApiException
     */
    private function uploadedCsv(ServerRequestInterface $request): array
    {
        $file = ($request->getUploadedFiles()['file'] ?? null);
        if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
            throw new ApiException(422, 'validation_failed', 'Choose a CSV file to import.');
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ApiException(422, 'validation_failed', 'That file did not upload correctly. Try again.');
        }
        if (($file->getSize() ?? 0) > CsvReader::MAX_BYTES) {
            throw new ApiException(422, 'validation_failed', 'That file is larger than 1 MB. Split it into smaller batches.');
        }

        $name = (string) ($file->getClientFilename() ?? 'upload.csv');

        return [(string) $file->getStream()->getContents(), basename($name)];
    }

    /**
     * @param array<string,mixed>|null $result
     */
    private function page(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $type,
        ?array $result,
        ?string $error,
        int $status = 200,
    ): ResponseInterface {
        return $this->render($request, $response, 'console/import/index', [
            'type'    => $type,
            'types'   => ImportService::LABELS,
            'columns' => ImportService::columnsFor($type),
            'result'  => $result,
            'error'   => $error,
            'maxRows' => CsvReader::MAX_ROWS,
        ], $status);
    }

    private function clearPending(ServerRequestInterface $request): void
    {
        $session = $this->session($request);
        $session->remove(self::SESSION_CSV);
        $session->remove(self::SESSION_TYPE);
        $session->remove(self::SESSION_NAME);
    }

    private function type(string $type): string
    {
        return in_array($type, ImportService::TYPES, true) ? $type : 'clients';
    }
}
