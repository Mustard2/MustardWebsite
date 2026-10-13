<?php
// ─────────────────────────────────────────────────────────────────────────────
// Copy this file to config.php (same folder) and fill in the values.
// This folder must live OUTSIDE public_html so nobody can download it.
// ─────────────────────────────────────────────────────────────────────────────

return [
    // From https://www.patreon.com/portal/registration/register-clients
    'client_id'     => 'PASTE_CLIENT_ID',
    'client_secret' => 'PASTE_CLIENT_SECRET',

    // Must match EXACTLY one of the "Redirect URIs" registered on the Patreon client
    'redirect_uri'  => 'https://xyz.xy/auth/callback.php',

    // Your Patreon campaign id (numbers only). See README → "Find your campaign id".
    'campaign_id'   => '0000000',

    // Your own Patreon user id(s): always allowed to download, even without a tier.
    // Turn on 'debug' below, log in once, and the callback page shows your id.
    'owner_user_ids' => [],

    // 'identity' returns the user's membership to YOUR campaign, which is all we need.
    // Only if real patrons get "not a member" by mistake, change to 'identity identity.memberships'.
    'scope' => 'identity',

    // How long a membership check stays valid before Patreon is asked again (seconds).
    // Re-checking is invisible to patrons: Patreon sends them straight back.
    'recheck_after' => 12 * 3600,

    // How long patrons stay logged in on the site (seconds); Admin → Settings → Login Duration overrides it
    'session_lifetime' => 30 * 24 * 3600,

    // Write one line per download to logs/downloads.csv (date, Patreon id, name, model)
    'log_downloads' => true,

    // How long download log lines are kept (days). Older lines are deleted automatically.
    'log_retention_days' => 365,

    // Who runs the site: shown on the privacy page (required by the GDPR)
    'legal' => [
        'name'    => 'Your full name',
        'address' => "Street 1\n12345 City\nUSA",
        'email'   => 'privacy@example.com',
    ],

    // Patreon campaign currency for the "total support" requirements (Admin → Settings overrides it; patron logins detect it)
    'currency' => 'EUR',

    // Shown on /contacts/ for website problems (login, broken download links)
    'support_email' => 'privacy@example.com',

    // Where Admin → Settings → Update looks for new versions (GitHub repositories "owner/name"). Leave it out to use the defaults:
    // 'update_repos' => ['stable' => 'Mustard2/MustardWebsite', 'dev' => 'Mustard2/Website'],

    // Shows the raw Patreon answer after login. Use once for setup, then set back to false!
    'debug' => false,
];
