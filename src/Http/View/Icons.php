<?php

declare(strict_types=1);

namespace App\Http\View;

/**
 * The console's hand-authored outline icon set (Phosphor/Lucide-style, 24px
 * grid, stroke-based) — no icon package, since the app ships no build step.
 * Single source of truth so `templates/layout.php` (sidebar/topbar) and any
 * page template (e.g. the dashboard's stat cards) draw the same icon for the
 * same name instead of maintaining separate copies of the path data.
 */
final class Icons
{
    private const PATHS = [
        'house' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9.5 21v-6h5v6"/>',
        'users' => '<circle cx="9" cy="8.5" r="3.25"/><path d="M2.75 20c.7-3.4 3.05-5.5 6.25-5.5s5.55 2.1 6.25 5.5"/><circle cx="17.25" cy="9.5" r="2.5"/><path d="M15.75 14.7c2.3.5 3.85 2.15 4.4 4.6"/>',
        'package' => '<path d="M3 8 12 3l9 5-9 5-9-5Z"/><path d="M3 8v9l9 5 9-5V8"/><path d="M12 13v9"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 3.5h6a1 1 0 0 1 1 1V6H8V4.5a1 1 0 0 1 1-1Z"/><path d="M8.5 11h7M8.5 14.5h7M8.5 18h4"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="M20 20l-4.8-4.8"/>',
        'shield' => '<path d="M12 3l7 3v6c0 5-3 8-7 9-4-1-7-4-7-9V6l7-3Z"/><path d="M9 12l2 2 4-4"/>',
        'file' => '<path d="M7 3h7l4 4v14H7Z"/><path d="M14 3v4h4"/><path d="M9.5 13h5M9.5 16.5h5"/>',
        'sliders' => '<line x1="4" y1="6" x2="20" y2="6"/><circle cx="15" cy="6" r="2"/><line x1="4" y1="12" x2="20" y2="12"/><circle cx="9" cy="12" r="2"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="16" cy="18" r="2"/>',
        'user-cog' => '<circle cx="10" cy="8" r="3.25"/><path d="M3.5 20c.6-3.1 3-5 6.5-5"/><circle cx="18" cy="16.5" r="3"/><path d="M18 13.2v.9M18 18.4v.9M20.6 15l-.8.45M15.4 18l-.8.45M15.4 15l.8.45M20.6 18l-.8.45"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 8v4l3 2"/>',
        'signout' => '<path d="M9 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h4"/><path d="M15 16l4-4-4-4"/><path d="M19 12H9"/>',
        'menu' => '<line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/>',
        'close' => '<line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M3 12h2M19 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/>',
        'moon' => '<path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5Z"/>',
        'calendar' => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 9.5h16M8 3v4M16 3v4"/>',
        'alert' => '<path d="M12 3 3 20h18L12 3Z"/><path d="M12 10v4"/><circle cx="12" cy="17" r="1"/>',
        'download' => '<path d="M12 4v11"/><path d="M7.5 10.5 12 15l4.5-4.5"/><path d="M5 19h14"/>',
        'bell' => '<path d="M6 9a6 6 0 1 1 12 0c0 5 2 6.5 2 6.5H4S6 14 6 9Z"/><path d="M10 19a2 2 0 0 0 4 0"/>',
        'lock' => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'archive' => '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8"/><path d="M10 12h4"/>',
        'upload' => '<path d="M12 20V9"/><path d="M7.5 13.5 12 9l4.5 4.5"/><path d="M5 5h14"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'trending' => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
    ];

    public static function svg(string $name, string $class = ''): string
    {
        $inner = self::PATHS[$name] ?? '';
        $classAttr = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"' : '';

        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" '
            . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"' . $classAttr . '>' . $inner . '</svg>';
    }
}
