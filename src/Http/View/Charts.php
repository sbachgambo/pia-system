<?php

declare(strict_types=1);

namespace App\Http\View;

/**
 * Tiny server-side SVG charts. Colours come from CSS classes (`chart-*` in
 * console.css) so both themes work and no inline script/style is needed, which
 * keeps the page compatible with the strict Content-Security-Policy.
 */
final class Charts
{
    /**
     * Two-series grouped bar chart.
     *
     * @param list<array{label:string,scheduled:int,finalized:int}> $data
     */
    public static function monthlyBars(array $data, string $aName, string $bName): string
    {
        $max = 1;
        foreach ($data as $d) {
            $max = max($max, $d['scheduled'], $d['finalized']);
        }

        $w = 480;
        $h = 220;
        $top = 24;
        $bottom = 30;
        $plotH = $h - $top - $bottom;
        $n = max(1, count($data));
        $slot = $w / $n;
        $barW = min(26.0, $slot / 3);

        $summary = [];
        $svg = '';
        foreach ($data as $i => $d) {
            $cx = $slot * $i + $slot / 2;
            foreach ([['scheduled', 'chart-bar-a', -$barW - 2], ['finalized', 'chart-bar-b', 2]] as [$key, $class, $dx]) {
                $v = $d[$key];
                $bh = $v > 0 ? max(2.0, $plotH * $v / $max) : 0.0;
                $x = $cx + $dx;
                $y = $top + $plotH - $bh;
                if ($bh > 0) {
                    $svg .= sprintf('<rect class="%s" x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="3"/>', $class, $x, $y, $barW, $bh);
                }
                $svg .= sprintf('<text class="chart-val" x="%.1f" y="%.1f" text-anchor="middle">%d</text>', $x + $barW / 2, $y - 5, $v);
            }
            $svg .= sprintf('<text class="chart-axis" x="%.1f" y="%d" text-anchor="middle">%s</text>', $cx, $h - 10, htmlspecialchars($d['label'], ENT_QUOTES));
            $summary[] = "{$d['label']}: {$d['scheduled']} {$aName}, {$d['finalized']} {$bName}";
        }

        $base = sprintf('<line class="chart-base" x1="0" y1="%d" x2="%d" y2="%d"/>', $top + $plotH, $w, $top + $plotH);

        return sprintf(
            '<svg class="chart" viewBox="0 0 %d %d" role="img" aria-label="%s">%s%s</svg>',
            $w,
            $h,
            htmlspecialchars('Inspections per month. ' . implode('; ', $summary), ENT_QUOTES),
            $base,
            $svg,
        );
    }

    /**
     * Monthly income bars; each bar links to that month's full income report.
     *
     * @param list<array{label:string,name:string,from:string,to:string,income:float,count:int}> $data
     */
    public static function incomeBars(array $data): string
    {
        $max = 0.0;
        foreach ($data as $d) {
            $max = max($max, $d['income']);
        }
        $max = $max > 0.0 ? $max : 1.0;

        $w = 600;
        $h = 190;
        $top = 22;
        $bottom = 26;
        $plotH = $h - $top - $bottom;
        $n = max(1, count($data));
        $slot = $w / $n;
        $barW = min(30.0, $slot * 0.6);

        $svg = '';
        $summary = [];
        foreach ($data as $i => $d) {
            $cx = $slot * $i + $slot / 2;
            $bh = $d['income'] > 0.0 ? max(3.0, $plotH * $d['income'] / $max) : 0.0;
            $y = $top + $plotH - $bh;
            $title = sprintf('%s: ₦%s from %d CCI%s', $d['name'], number_format($d['income'], 2), $d['count'], $d['count'] === 1 ? '' : 's');
            $href = '/console/income?period=custom&from=' . $d['from'] . '&to=' . $d['to'];

            $svg .= sprintf('<a href="%s" class="chart-link"><title>%s</title>', htmlspecialchars($href, ENT_QUOTES), htmlspecialchars($title, ENT_QUOTES));
            // A full-height hit area, so an empty month is still clickable.
            $svg .= sprintf('<rect class="chart-hit" x="%.1f" y="%d" width="%.1f" height="%d"/>', $slot * $i, $top, $slot, $plotH);
            if ($bh > 0) {
                $svg .= sprintf('<rect class="chart-bar-a" x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="3"/>', $cx - $barW / 2, $y, $barW, $bh);
                $svg .= sprintf('<text class="chart-val" x="%.1f" y="%.1f" text-anchor="middle">%s</text>', $cx, $y - 5, htmlspecialchars(self::compact($d['income']), ENT_QUOTES));
            }
            $svg .= sprintf('<text class="chart-axis" x="%.1f" y="%d" text-anchor="middle">%s</text></a>', $cx, $h - 8, htmlspecialchars($d['label'], ENT_QUOTES));
            $summary[] = $title;
        }

        $base = sprintf('<line class="chart-base" x1="0" y1="%d" x2="%d" y2="%d"/>', $top + $plotH, $w, $top + $plotH);

        return sprintf(
            '<svg class="chart" viewBox="0 0 %d %d" role="img" aria-label="%s">%s%s</svg>',
            $w,
            $h,
            htmlspecialchars('Income per month. ' . implode('; ', $summary), ENT_QUOTES),
            $base,
            $svg,
        );
    }

    /** ₦ amounts short enough to sit above a bar: 950, 12.4k, 3.2m. */
    public static function compact(float $v): string
    {
        return match (true) {
            $v >= 1_000_000_000 => rtrim(rtrim(number_format($v / 1_000_000_000, 1), '0'), '.') . 'bn',
            $v >= 1_000_000 => rtrim(rtrim(number_format($v / 1_000_000, 1), '0'), '.') . 'm',
            $v >= 1_000 => rtrim(rtrim(number_format($v / 1_000, 1), '0'), '.') . 'k',
            default => number_format($v, 0),
        };
    }

    /**
     * Horizontal proportional bars as accessible HTML (label, bar, count).
     *
     * @param array<string,int> $counts
     */
    public static function hbars(array $counts): string
    {
        $max = max(1, ...array_values($counts ?: [0]));
        $html = '<ul class="hbars">';
        foreach ($counts as $label => $n) {
            $pct = $n > 0 ? max(3, (int) round(100 * $n / $max)) : 0;
            $html .= sprintf(
                '<li><span class="hbar-label">%s</span><span class="hbar-track"><span class="hbar-fill hbar-%s" style="width:%d%%"></span></span><span class="hbar-n">%d</span></li>',
                htmlspecialchars(ucfirst($label), ENT_QUOTES),
                htmlspecialchars($label, ENT_QUOTES),
                $pct,
                $n,
            );
        }

        return $html . '</ul>';
    }
}
