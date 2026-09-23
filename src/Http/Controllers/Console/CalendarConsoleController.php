<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Calendar\CalendarEvents;
use App\Http\Support\Query;
use App\Http\View\Renderer;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** /console/calendar — month view of scheduled inspections and request deadlines. */
final class CalendarConsoleController extends AbstractConsoleController
{
    public function __construct(Renderer $view, private readonly CalendarEvents $events)
    {
        parent::__construct($view);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = Query::params($request);
        $utc = new DateTimeZone('UTC');
        $today = new DateTimeImmutable('today', $utc);

        $month = (string) ($q['month'] ?? '');
        $first = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1
            ? new DateTimeImmutable($month . '-01', $utc)
            : $today->modify('first day of this month');

        // Monday-first grid covering whole weeks around the month.
        $gridStart = $first->modify('monday this week');
        $lastOfMonth = $first->modify('last day of this month');
        $gridEnd = $lastOfMonth->modify('sunday this week');
        if ($gridEnd < $lastOfMonth) {
            $gridEnd = $gridEnd->modify('+7 days');
        }

        $inspector = (string) ($q['inspector'] ?? '');
        $byDay = $this->events->between($gridStart, $gridEnd->modify('+1 day'), $inspector !== '' ? $inspector : null);

        $day = (string) ($q['day'] ?? '');
        $selected = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? $day : null;

        return $this->render($request, $response, 'console/calendar/index', [
            'first'      => $first,
            'gridStart'  => $gridStart,
            'gridEnd'    => $gridEnd,
            'today'      => $today->format('Y-m-d'),
            'byDay'      => $byDay,
            'inspectors' => $this->events->inspectors(),
            'inspector'  => $inspector,
            'selected'   => $selected,
        ]);
    }
}
