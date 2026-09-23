<?php

declare(strict_types=1);

namespace App\Documents;

use Mpdf\Mpdf;
use Mpdf\MpdfException;
use RuntimeException;

/**
 * Thin wrapper around mPDF (D6: pure-PHP, no shell/exec()). Not specific to
 * the CCI — any HTML-producing document builder (statutory returns in
 * Phase 7, say) can reuse this.
 *
 * mPdf needs a writable scratch directory for its own caching; we point it at
 * `storage/cache/mpdf` (outside the web root, alongside the app's other
 * runtime state) rather than its package default under vendor/.
 */
final class PdfRenderer
{
    public function __construct(private readonly string $tempDir)
    {
    }

    public function render(string $html, string $format = 'A4'): string
    {
        if (!is_dir($this->tempDir) && !mkdir($this->tempDir, 0775, true) && !is_dir($this->tempDir)) {
            throw new RuntimeException("mPDF temp directory {$this->tempDir} is not writable.");
        }

        try {
            $mpdf = new Mpdf([
                'format'        => $format,
                'tempDir'       => $this->tempDir,
                'margin_top'    => 12,
                'margin_bottom' => 12,
                'margin_left'   => 12,
                'margin_right'  => 12,
            ]);
            $mpdf->WriteHTML($html);

            return $mpdf->Output('', 'S'); // 'S' = return as string, don't touch the filesystem/browser
        } catch (MpdfException $e) {
            throw new RuntimeException('PDF generation failed: ' . $e->getMessage(), previous: $e);
        }
    }
}
