<?php
// Welcome page (/welcome/, welcome.php): a short tour of the site shown once to a paying patron, right after their first login
// (auth/callback.php sends them there with the address they were going to). Admin → Settings → Features → Welcome Page;
// settings.json → welcome: {on, skip_old, text, start, since}; off by default.
//   skip_old: patrons whose first download in the log is before `since` (the time it was switched on) don't get it;
//             "Show Again to Everyone" empties the seen list and sets `since` to 0, so nobody counts as old.
// data/welcomed.json: {seen: {code: time}}, code = m3d_like_code() (Patreon id + secret key, like likes: no ids, no names).
declare(strict_types=1);
defined('M3D_PRIVATE') || exit;

const WELCOME_TEXT_MAX = 2000;
const WELCOME_START_MAX = 300;

function m3d_welcome(): array
{
    $w = (array) (m3d_settings()['welcome'] ?? []);
    return ['on' => !empty($w['on']), 'skip_old' => !array_key_exists('skip_old', $w) || !empty($w['skip_old']),
            'text' => (string) ($w['text'] ?? ''), 'start' => (string) ($w['start'] ?? ''), 'since' => (int) ($w['since'] ?? 0)];
}

function m3d_welcome_on(): bool { return m3d_welcome()['on']; }

/** The intro under the title: the saved text, else a neutral line. */
function m3d_welcome_text(): string
{
    $t = trim(m3d_welcome()['text']);
    return $t !== '' ? $t : 'Thanks for supporting ' . site_name() . ' on Patreon. Here’s a quick tour, so you know where everything is.';
}

/** Whether this patron has seen the welcome page. */
function m3d_welcome_seen(string $patronId): bool
{
    if ($patronId === '') return true;
    $seen = (array) ((m3d_read_json('welcomed') ?? [])['seen'] ?? []);
    return isset($seen[m3d_like_code($patronId)]);
}

/** Whether any patron is remembered as having seen it: the privacy page describes the seen list while it is on or still holds codes. */
function m3d_welcome_stored(): bool
{
    return m3d_welcome_on() || !empty((m3d_read_json('welcomed') ?? [])['seen']);
}

/** Remembers that this patron saw it (false = not saved). */
function m3d_welcome_mark(string $patronId): bool
{
    if ($patronId === '') return false;
    $code = m3d_like_code($patronId);
    return m3d_json_update('welcomed', function ($d) use ($code) {
        $d = is_array($d) ? $d : [];
        $seen = is_array($d['seen'] ?? null) ? $d['seen'] : [];
        if (isset($seen[$code])) return [$d, true];
        $seen[$code] = time();
        return [['seen' => $seen] + $d, true];
    }) === true;
}

/** "Show Again to Everyone": nobody has seen it, and nobody counts as an old patron. */
function m3d_welcome_reset(): bool
{
    $ok = m3d_json_update('welcomed', fn($d) => [['seen' => []], true]) === true;
    return $ok && m3d_save_settings(['welcome' => ['since' => 0] + (array) (m3d_settings()['welcome'] ?? [])]);
}

/**
 * Whether a patron who just logged in gets the welcome page: it's on, they can download (paid tier or exception), they're not an
 * admin, they haven't seen it, and (skip_old) their first download isn't from before it was switched on. A patron skipped as old
 * is marked as seen, so the log isn't read at every login.
 */
function m3d_welcome_due(?array $p): bool
{
    $w = m3d_welcome();
    $id = (string) ($p['id'] ?? '');
    if (!$w['on'] || !$p || empty($p['access']) || !empty($p['preview']) || $id === '') return false;
    if (($p['reason'] ?? '') === 'owner' || in_array($id, m3d_owner_ids($GLOBALS['M3D']), true)) return false;
    if (m3d_welcome_seen($id)) return false;
    if ($w['skip_old'] && $w['since'] > 0) {
        $log = m3d_patron_log($id);
        if ($log && $log[0][0] < $w['since']) { m3d_welcome_mark($id); return false; }
    }
    return true;
}

/** What some rules allow, in plain words, per kind: ['models' => ["1 new model every 7 days"], 'outfits' => […]] (empty = no limit). */
function m3d_welcome_rule_texts(array $rules): array
{
    $out = ['models' => [], 'outfits' => []];
    foreach (array_keys($out) as $kind) {
        foreach ($rules as $r) {
            if (!in_array($kind, m3d_rule_kinds($r), true)) continue;
            $max = max(1, (int) ($r['max'] ?? 1));
            $per = m3d_rule_period($r)[1];
            $out[$kind][] = (($r['what'] ?? 'models') === 'all' ? m3d_plural($max, 'new download') . ' (models and accessories)'
                : $max . ' new ' . DL_WHAT[$kind][$max === 1 ? 0 : 1]) . ' every ' . $per;
        }
    }
    return $out;
}

/** The one row of a patron with their own unlock rules (Admin → Access: Unlock Limits "Custom"), like m3d_welcome_rule_rows()'s; [] = none. */
function m3d_welcome_own_rows(array $rules): array
{
    return $rules ? [['tier' => null, 'label' => 'Just for You', 'from' => 0, 'to' => null] + m3d_welcome_rule_texts($rules)] : [];
}

/**
 * The download rules in plain words, one row per tier with rules of its own (Admin → Access → Unlock Limits: Tier) and stretch of
 * "months as a patron" (the rules' from / to), with what each kind allows: [{tier (cents, null = the tiers without rules of their
 * own), label, from, to, models: [text], outfits: [text]}] (an empty list = no limit). [] when no rule is on. The rules of each
 * row come from m3d_rules_pick(), like a patron's own (a tier's rules replace the "Any Tier" ones).
 */
function m3d_welcome_rule_rows(): array
{
    $rules = array_values(array_filter(m3d_dl_settings()['rules'], fn($r) => !empty($r['on'])));
    if (!$rules) return [];
    $tiers = [null];
    foreach ($rules as $r) if ((int) ($r['tier'] ?? 0) > 0) $tiers[] = (int) $r['tier'];
    $tiers = array_values(array_unique($tiers, SORT_REGULAR));
    usort($tiers, fn($a, $b) => (int) $a <=> (int) $b);
    $label = fn(int $from, ?int $to, ?int $tier) => match (true) {
        $from === 0 && $to === null => $tier === null ? 'Every Patron' : 'Any Time',   // under its tier's name
        $from === 0                 => 'Under ' . m3d_plural($to, 'month'),
        $to === null                => $from . '+ months',
        default                     => $from . '–' . m3d_plural($to, 'month'),
    };
    $rows = [];
    foreach ($tiers as $tier) {
        $cuts = [0];
        foreach ($rules as $r) {
            if ((int) ($r['tier'] ?? 0) > 0 && (int) $r['tier'] !== $tier) continue;
            foreach (['from', 'to'] as $f) if (isset($r[$f]) && $r[$f] !== '' && (int) $r[$f] > 0) $cuts[] = (int) $r[$f];
        }
        $cuts = array_values(array_unique($cuts));
        sort($cuts);
        $tierRows = [];
        foreach ($cuts as $i => $from) {
            $to = $cuts[$i + 1] ?? null;
            $row = ['tier' => $tier, 'label' => $label($from, $to, $tier), 'from' => $from, 'to' => $to];
            $row += m3d_welcome_rule_texts(m3d_rules_pick($rules, $from, $tier));
            // two stretches in a row that allow the same: one row ("Under 3 months" + "3+ months" of a tier = "Every Patron")
            $prev = $tierRows ? $tierRows[count($tierRows) - 1] : null;
            if ($prev && $prev['models'] === $row['models'] && $prev['outfits'] === $row['outfits']) {
                $row['from'] = $prev['from'];
                $row['label'] = $label($prev['from'], $to, $tier);
                array_pop($tierRows);
            }
            $tierRows[] = $row;
        }
        array_push($rows, ...$tierRows);
    }
    return $rows;
}
