<?php
// /privacy/  Privacy policy (GDPR). Controller details: Admin → Settings → Privacy → Legal & Contact (or config.php → 'legal').
require __DIR__ . '/app/_init.php';

$legal = m3d_legal();
$missing = '[add this in Admin → Settings → Privacy → Legal & Contact]';
$name = trim((string) ($legal['name'] ?? '')) ?: $missing;
$address = trim((string) ($legal['address'] ?? '')) ?: $missing;
$email = trim((string) ($legal['email'] ?? ''));
$sessionDays = (int) round(m3d_session_lifetime() / 86400);
$retention = (int) ($M3D['log_retention_days'] ?? 365);
$retentionText = $retention % 365 === 0 ? ($retention / 365 === 1 ? '12 months' : ($retention / 365) . ' years') : "$retention days";
$updated = '16 October 2026';

layout_start('Privacy Policy', 'How ' . site_name() . ' handles personal data.', 'page-legal');
?>
  <article class="wrap legal">
    <p class="eyebrow">Legal</p>
    <h1>Privacy Policy</h1>
    <p class="muted">Last updated: <?= e($updated) ?></p>

    <p class="lead-sm">This website shows 3D character models and accessories and lets Patreon members download them. It does not use analytics, advertising or tracking of any kind. This page explains, as required by the EU General Data Protection Regulation (GDPR), which personal data is processed, why, and what your rights are.</p>

    <h2>1. Who Is Responsible</h2>
    <p>Controller under Art. 4(7) GDPR:</p>
    <p class="pre addr"><?= e($name) ?>
<?= e($address) ?></p>
    <p>Email: <?php if ($email): ?><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a><?php else: ?><?= e($missing) ?><?php endif ?></p>

    <h2>2. Visiting the Website</h2>
    <p>When you open a page, your browser sends technical data that every website receives: IP address, date and time, the page requested, the referring page, browser type and operating system. This data is needed to deliver the pages and to keep the website secure (for example against attacks and abuse).</p>
    <ul>
      <li><b>Hosting:</b> the website runs on servers of Hostinger International Ltd. (Cyprus, EU), which processes this data on our behalf under a data processing agreement (Art. 28 GDPR) and keeps short-term server logs.</li>
      <li><b>Delivery and protection:</b> requests pass through Cloudflare, Inc. (USA), which delivers the pages quickly and filters malicious traffic. Cloudflare processes IP addresses and request data on our behalf under a data processing agreement. Transfers to the USA are based on the EU–US Data Privacy Framework and the EU Standard Contractual Clauses.</li>
    </ul>
    <p>Legal basis: Art. 6(1)(f) GDPR (our legitimate interest in a secure, working website). We do not combine this data with other sources or use it to identify you.</p>
    <p>Fonts are served from this website itself; your browser does not contact Google or other font providers.</p>

    <h2>3. Cookies and Browser Storage</h2>
    <p>The website only sets cookies that are strictly necessary for features you use. No consent banner is needed for these (§ 25(2) TDDDG), and no cookies are used for tracking or advertising.</p>
    <div class="table-wrap">
      <table class="legal-table">
        <thead><tr><th>Name</th><th>Purpose</th><th>Duration</th></tr></thead>
        <tbody>
          <tr><td><code>m3d_age_ok</code></td><td>Remembers that you confirmed you are 18 or older, so the question isn’t asked on every page. Contains only the value “1”.</td><td>30 days</td></tr>
          <tr><td><code>m3d_session</code></td><td>Keeps you logged in after the Patreon login (see section 4). Only set when you log in.</td><td><?= e(m3d_plural($sessionDays, 'day')) ?>, or until you log out</td></tr>
          <tr><td><code>m3d_unlock</code></td><td>Remembers which password-protected models or accessories you opened with their password, so you don’t have to type it again. Only set when you enter a password; contains a short code per page, nothing about you.</td><td><?= M3D_UNLOCK_DAYS ?> days</td></tr>
          <tr><td><code>__cf_bm</code>, <code>cf_clearance</code><br><span class="muted small">set by Cloudflare</span></td><td>Security cookies of Cloudflare (see section 2): they help tell people from bots and protect the site from attacks. Only set when Cloudflare’s protection needs them, <code>cf_clearance</code> only after you pass a security check. Not used for tracking or advertising.</td><td>30 minutes (after a security check, as long as set in Cloudflare)</td></tr>
          <tr><td><code>m3d_notice</code>, <code>m3d_tabs</code><br><span class="muted small">browser storage, not cookies</span></td><td>Only after you close the message bar at the top of the page (a short code of that message), or click a tab in the documentation (the names of the tabs you chose), so the bar stays closed and the next page opens on the same tab. Never sent to us.</td><td>Until you clear your browser data</td></tr>
        </tbody>
      </table>
    </div>

    <h2>4. Logging In with Patreon</h2>
    <p>Downloads for patrons require logging in with your Patreon account. When you click “Log In with Patreon”, you are sent to Patreon, Inc. (USA), where you log in and agree to share data with this website. Patreon then sends us only:</p>
    <ul>
      <li>your Patreon user ID and the name shown on your Patreon profile;</li>
      <li>your membership status for the <?= e(site_name()) ?> campaign and the tiers you currently hold (to check that you are on a paid tier);</li>
      <li>when your current membership started (worked out from Patreon’s start date and your pledge history: the dates and amounts of the times you started, changed or ended your pledge; only the resulting date is kept), the total amount you have paid to <?= e(site_name()) ?>, your current pledge amount and the date and status of your last payment (some downloads require a minimum tier, a minimum time as a patron or a minimum total support).</li>
    </ul>
    <p>We do <b>not</b> receive your email address, password or payment details, and we do not store the access token Patreon issues. You can see all of this data on your <a href="/account/">My account</a> page while you are logged in. The data above is kept in a session file on our server for as long as you stay logged in (at most <?= e(m3d_plural($sessionDays, 'day')) ?>) and is deleted when you log out, or shortly after the login expires; only your tier, the start of your membership and your total support are also saved with each download in the download log (section 5). Your membership is checked again with Patreon about every <?= (int) round(($M3D['recheck_after'] ?? 43200) / 3600) ?> hours while you are logged in.</p>
    <p>Legal basis: Art. 6(1)(b) GDPR (providing the downloads your Patreon membership entitles you to, at your request). Patreon’s own processing is covered by <a href="https://privacy.patreon.com/policies" rel="noopener">Patreon’s privacy policy</a>. Transfers to Patreon in the USA are based on the EU Standard Contractual Clauses / EU–US Data Privacy Framework as applicable to Patreon.</p>
    <p>Exceptions: if <?= e(site_name()) ?> gives you access without a paid tier, or lets you skip or change the extra requirements of some downloads or the download limits (for example as a tester), your Patreon user ID is stored on our server together with a name, a short note written by <?= e(site_name()) ?> and the access settings chosen for you, for as long as the exception lasts. It is deleted when the exception is removed. Legal basis: Art. 6(1)(b) GDPR (providing the access agreed with you).</p>

    <h2>5. Downloads</h2>
    <p>When a patron uses a patrons-only download link, we record in the download log: date and time, Patreon user ID, Patreon name, which model or accessory (and its version), which link, and your membership at that moment as Patreon reported it (your tier’s price, when your current membership started and how many months that makes, and your total support). No IP address is stored.</p>
    <p>Purpose: to protect patron-only files from abuse (for example finding out which account shared a link publicly), to know which files are used, to apply the download limits (how many different models or accessories a patron can download in a period, shown on My account), and to check afterwards why a download was allowed (the membership saved with it). Legal basis: Art. 6(1)(f) GDPR (our legitimate interest in protecting paid content). Entries are deleted automatically after <?= e($retentionText) ?>.</p>
    <p>Every click on a download link, free ones included, also adds one to a counter for that model or accessory: only the totals are stored, with nothing about who clicked (so free links pass through this website for a moment before the file host). The short waiting page before a patron download carries the time it was opened and a signature in its link, so the server can check the wait without a cookie; if you are logged in, your session also remembers for those few seconds which download is counting down, so only one runs at a time.</p>

    <h2>6. Likes and Other Choices You Make</h2>
    <p>For the features below we never store your Patreon ID or name: we store a code computed from your Patreon user ID with a secret key (a keyed hash), which can’t be turned back into your ID by anyone without the key and isn’t linked to your name. Legal basis: Art. 6(1)(b) GDPR (features you choose to use)<?= m3d_welcome_stored() ? ', and for the welcome page Art. 6(1)(f) GDPR (our legitimate interest in not showing you the same page at every login)' : '' ?>.</p>
    <ul>
      <li><b>Likes</b> on models, accessories and gallery pictures, so each patron likes each one only once. Only the number of likes is shown. A like stays until you remove it or the item is removed.</li>
      <li><b>Ignored updates:</b> when you choose “Ignore this update”, the model or accessory and the version you ignored, so the “Update” mark stays hidden on every device. It goes when you choose “Stop Ignoring” on My account or the item is removed; once it no longer applies (you downloaded that version, or a later one came out), it is cleared the next time you ignore or show an update.</li>
<?php if (m3d_welcome_stored()): ?>
      <li><b>Welcome page:</b> that you have seen it, with the date, so it is shown only once after your first login. It stays until <?= e(site_name()) ?> resets the welcome page.</li>
<?php endif ?>
<?php if (m3d_bugs_on()): ?>
      <li><b>“Me Too” and following bug reports</b> (section 7): that you pressed “Me Too” (only the number is shown), and for reports you follow, when you last opened them, to show you what is new there. Press “Following” to stop.</li>
<?php endif ?>
    </ul>

<?php $n = 7; if (m3d_bugs_on()): $bs = m3d_bug_settings(); ?>
    <h2><?= $n++ ?>. Bug Reports</h2>
    <p>When you are logged in with Patreon you can report bugs on the <a href="/bugs/">Bug reports</a> page and comment on reports. A report or comment is stored on our server with your Patreon user ID, your Patreon name and the date, and is shown publicly with your Patreon name, so don’t put private details in it. A report can contain links to pictures or videos on other websites: they are shown only as links, never loaded on our pages<?= $bs['uploads'] ? '; pictures you upload with a report are stored on our server and shown with it' : '' ?>. Writing a report or commenting makes you follow that report (section 6).<?= $bs['notify'] ? ' New reports are also sent to ' . e(site_name()) . ' through a private Discord channel (title, text and your Patreon name).' : '' ?></p>
    <p>Purpose: to fix the problems you report and to tell others about them. Legal basis: Art. 6(1)(b) GDPR (the bug report feature you choose to use). Reports and comments stay until <?= e(site_name()) ?> deletes them; ask us (see “Contacting Us”) to delete yours.</p>
<?php endif ?>

    <h2><?= $n++ ?>. Links to Other Websites</h2>
    <p>Download links lead to file hosts such as Mega or Smutbase, and the site links to Patreon, Discord, X, GitHub, Blender Extensions and the other networks shown at the bottom of each page. Once you follow such a link, the privacy policy of that website applies. Nothing from these services is embedded or loaded on our pages.</p>

    <h2><?= $n++ ?>. Contacting Us</h2>
    <p>If you contact us by email, we use your message and address only to answer you, and delete them when the conversation is finished, unless we have to keep them by law. Legal basis: Art. 6(1)(b) or (f) GDPR.</p>

    <h2><?= $n++ ?>. Your Rights</h2>
    <p>You have the right to access your data (Art. 15), to have it corrected (Art. 16) or deleted (Art. 17), to restrict its processing (Art. 18), to receive it in a portable format (Art. 20), and to object to processing based on legitimate interests (Art. 21). Write to the email address above with your Patreon user ID (shown on <a href="/account/">My account</a>): we can send you a copy of all the data stored about you, or delete it.</p>

    <h2><?= $n++ ?>. Adults Only, and Changes</h2>
    <p>This website is intended for adults only. We do not knowingly process data of people under 18. We update this policy when the website or the law changes; the date at the top shows the latest version.</p>
  </article>
<?php layout_end();
