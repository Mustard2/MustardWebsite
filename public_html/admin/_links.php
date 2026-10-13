<?php
// Admin → Assets → Link check: the table's rows (the checks themselves are in app/links.php).
declare(strict_types=1);

/** "Today 20:15 CEST", "Yesterday 09:02 CEST", "Mon 5 Oct, 09:02 CEST" (Berlin time), or "Never". */
function lc_when(?int $t): string
{
    if (!$t) return 'Never';
    $tz = new DateTimeZone('Europe/Berlin');
    $d = (new DateTimeImmutable('@' . $t))->setTimezone($tz);
    $today = new DateTimeImmutable('today', $tz);
    if ($d >= $today) return 'Today ' . $d->format('H:i T');
    if ($d >= $today->modify('-1 day')) return 'Yesterday ' . $d->format('H:i T');
    return $d->format('D j M, H:i T');
}

/** One row of the Link check table. */
function lc_row(array $l, ?array $r): string
{
    $st = in_array($r['st'] ?? '', LC_STATES, true) ? $r['st'] : 'new';
    $badge = match ($st) {
        'ok'      => '<span class="badge tone-green">✓ Works</span>',
        'dead'    => '<span class="badge tone-red">✗ Dead</span>',
        'unknown' => '<span class="badge tone-amber">? Couldn’t Check</span>',
        default   => '<span class="badge tone-grey">Not Checked</span>',
    };
    $why = (string) ($r['why'] ?? '');
    $search = admin_search_text([$l['name'], $l['sub'], $l['label'], $l['host'], $l['url'], $why]);
    return '<tr data-lc-row="' . e($l['h']) . '" data-url="' . e($l['url']) . '" data-st="' . $st . '" data-group="' . lc_group($l['host']) . '" data-search="' . e($search) . '"' . ($st === 'dead' ? ' class="is-dead"' : '') . '>'
        . '<td><a class="m-name" href="' . e($l['edit']) . '">' . e($l['name']) . '</a><div class="muted small">' . e($l['sub']) . '</div></td>'
        . '<td>' . e($l['label']) . '<div class="muted small">' . e($l['access']) . '</div></td>'
        . '<td class="lc-host"><a href="' . e($l['url']) . '" target="_blank" rel="noopener noreferrer" title="' . e($l['url']) . '">' . e($l['host']) . ' ↗</a></td>'
        . '<td class="lc-st">' . $badge . ($why !== '' ? '<div class="lc-why">' . e($why) . '</div>' : '') . '</td>'
        . '<td class="small muted lc-at">' . e(lc_when(isset($r['at']) ? (int) $r['at'] : null)) . '</td>'
        . '<td class="c-actions">' . ($st === 'dead' ? '<button type="button" class="btn btn-ghost btn-sm" data-lc-replace>Replace…</button>' : '')
        . '<button type="button" class="btn btn-ghost btn-sm" data-lc-one>Check Again</button>'
        . '<a class="btn btn-ghost btn-sm" href="' . e($l['edit']) . '">Edit</a></td></tr>';
}
