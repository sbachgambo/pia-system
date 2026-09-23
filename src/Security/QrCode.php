<?php

declare(strict_types=1);

namespace App\Security;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Renders a string as an inline SVG QR code, entirely server-side — no image
 * host, no JavaScript, nothing that would need a CSP exception. The secret in
 * the code never leaves the response to the enrolling user's own browser.
 */
final class QrCode
{
    public static function svg(string $data, int $size = 220): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd())))->writeString($data);

        // Drop the XML prolog so the markup can sit inside an HTML page.
        return (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg);
    }
}
