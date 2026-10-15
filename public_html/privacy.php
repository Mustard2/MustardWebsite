<?php
// /privacy/  Privacy policy (GDPR). Controller details: Admin → Settings → Legal & contact (or config.php → 'legal').
require __DIR__ . '/app/_init.php';

$legal = m3d_legal();
$missing = '[add this in Admin → Settings → Legal & contact]';
$name = trim((string) ($legal['name'] ?? '')) ?: $missing;
$address = trim((string) ($legal['address'] ?? '')) ?: $missing;
$email = trim((string) ($legal['email'] ?? ''));
$sessionDays = (int) round(m3d_session_lifetime() / 86400);
$retention = (int) ($M3D['log_retention_days'] ?? 365);
$retentionText = $retention % 365 === 0 ? ($retention / 365 === 1 ? '12 months' : ($retention / 365) . ' years') : "$retention days";
$updated = '11 October 2026';

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

    <h2>3. Cookies</h2>
    <p>The website only sets cookies that are strictly necessary for features you use. No consent banner is needed for these (§ 25(2) TDDDG), and no cookies are used for tracking or advertising.</p>
    <div class="table-wrap">
      <table class="legal-table">
        <thead><tr><th>Cookie</th><th>Purpose</th><th>Duration</th></tr></thead>
        <tbody>
          <tr><td><code>m3d_age_ok</code></td><td>Remembers that you confirmed you are 18 or older, so the question isn’t asked on every page. Contains only the value “1”.</td><td>30 days</td></tr>
          <tr><td><code>m3d_session</code></td><td>Keeps you logged in after the Patreon login (see section 4). Only set when you log in.</td><td><?= e(m3d_plural($sessionDays, 'day')) ?>, or until you log out</td></tr>
          <tr><td><code>m3d_notice</code><br><span class="muted small">browser storage, not a cookie</span></td><td>Only if you close the message bar at the top of the page: your browser remembers that you closed it (a short code of that message). It is never sent to us.</td><td>Until you clear your browser data</td></tr>
          <tr><td><code>m3d_seen_updates</code><br><span class="muted small">browser storage, not a cookie</span></td><td>Only for logged-in patrons who open My account while some of their downloaded models have a newer version: your browser remembers which of those updates you have seen (model name and version), so the dot on your account button goes away. It is never sent to us.</td><td>Until you clear your browser data</td></tr>
          <tr><td><code>m3d_tabs</code><br><span class="muted small">browser storage, not a cookie</span></td><td>Only if you click a tab in the documentation (for example “Manual” or another way to do the same steps): your browser remembers the names of the tabs you chose, so the next page opens on the same choice. It is never sent to us.</td><td>Until you clear your browser data</td></tr>
          <tr><td><code>m3d_unlock</code></td><td>Remembers which password-protected models or accessories you opened with their password, so you don’t have to type it again. Only set when you enter a password; contains a short code per page, nothing about you.</td><td><?= M3D_UNLOCK_DAYS ?> days</td></tr>
          <tr><td><code>__cf_bm</code>, <code>cf_clearance</code><br><span class="muted small">set by Cloudflare</span></td><td>Security cookies of Cloudflare (see section 2): they help tell people from bots and protect the site from attacks. Only set when Cloudflare’s protection needs them, <code>cf_clearance</code> only after you pass a security check. Not used for tracking or advertising.</td><td>30 minutes (after a security check, as long as set in Cloudflare)</td></tr>
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
    <p>Exceptions: if <?= e(site_name()) ?> gives you access without a paid tier, or lets you skip the extra requirements of some downloads or the download limits (for example as a tester), your Patreon user ID is stored on our server together with a name and a short note written by <?= e(site_name()) ?>, for as long as the exception lasts. It is deleted when the exception is removed. Legal basis: Art. 6(1)(b) GDPR (providing the access agreed with you).</p>

    <h2>5. Download Log</h2>
    <p>When a patron uses a patrons-only download link, we record: date and time, Patreon user ID, Patreon name, which model or accessory (and its version), which link, and your membership at that moment as Patreon reported it (your tier’s price, when your current membership started and how many months that makes, and your total support). No IP address is stored.</p>
    <p>Purpose: to protect patron-only files from abuse (for example finding out which account shared a link publicly) to know which files are used, to apply the download limits (how many different models or accessories a patron can download in a period, shown on My account), and to check afterwards why a download was allowed (the membership saved with it). Legal basis: Art. 6(1)(f) GDPR (our legitimate interest in protecting paid content). Entries are deleted automatically after <?= e($retentionText) ?>.</p>

    <h3>Likes</h3>
    <p>Patrons can like models, accessories and gallery pictures. To make sure each patron likes each of them only once, we store, for every like, a code computed from your Patreon user ID with a secret key (a keyed hash). The code can't be turned back into your Patreon ID by anyone without the key, and it isn't linked to your name. Only the number of likes is shown on the website. A like stays until you remove it (click the heart again) or the model, accessory or picture is removed. Legal basis: Art. 6(1)(b) GDPR (the like feature you choose to use).</p>
<?php if (m3d_welcome_stored()): ?>
    <h3>Welcome Page</h3>
    <p>After your first login as a patron we show you a short welcome page once. To know you have seen it, we store a code computed from your Patreon user ID with a secret key, like the likes, and the date; nothing else. Legal basis: Art. 6(1)(f) GDPR (our legitimate interest in not showing you the same page at every login). The code stays until <?= e(site_name()) ?> resets the welcome page.</p>
<?php endif ?>
    <h3>Download Counter</h3>
    <p>Every click on a download link, including free downloads, increases a counter for that model or accessory. Only the totals are stored, with nothing about who clicked. Free download links therefore pass through this website for a moment before taking you to the file host.</p>
    <p>Before a download starts, a short waiting page may be shown. Its link carries the time the page was opened and a signature, so the server can check the wait; no cookie is set for it. If you are logged in, your login session also remembers for those few seconds which download is counting down, so that only one runs at a time.</p>

<?php if (m3d_bugs_on()): $bs = m3d_bug_settings(); ?>
    <h3>Bug Reports</h3>
    <p>When you are logged in with Patreon you can report bugs on the <a href="/bugs/">Bug reports</a> page, comment on reports and press “Me Too”. A report or comment is stored on our server with your Patreon user ID, your Patreon name and the date, and is shown publicly with your Patreon name, so don’t put private details in it. A report can contain links to pictures or videos on other websites: they are shown only as links, never loaded on our pages<?= $bs['uploads'] ? '; pictures you upload with a report are stored on our server and shown with it' : '' ?>. For “Me Too” we store only a code made from your Patreon user ID with a secret key, like the likes; only the number is shown. Writing a report, commenting, pressing “Me Too” or “Follow” makes you follow that report: with the same code we store when you last opened it, to show you what is new there (a number next to “Bugs” in the menu); press “Following” to stop.<?= $bs['notify'] ? ' New reports are also sent to ' . e(site_name()) . ' through a private Discord channel (title, text and your Patreon name).' : '' ?></p>
    <p>Purpose: to fix the problems you report and to tell others about them. Legal basis: Art. 6(1)(b) GDPR (the bug report feature you choose to use). Reports and comments stay until <?= e(site_name()) ?> deletes them; ask us (see “Contacting us”) to delete yours.</p>
<?php endif ?>

    <h2>6. Links to Other Websites</h2>
    <p>Download links lead to file hosts such as Mega or Smutbase, and the site links to Patreon, Discord, X, GitHub, Blender Extensions and the other networks shown at the bottom of each page. Once you follow such a link, the privacy policy of that website applies. Nothing from these services is embedded or loaded on our pages.</p>

    <h2>7. Contacting Us</h2>
    <p>If you contact us by email, we use your message and address only to answer you, and delete them when the conversation is finished, unless we have to keep them by law. Legal basis: Art. 6(1)(b) or (f) GDPR.</p>

    <h2>8. Your Rights</h2>
    <p>You have the right to access your data (Art. 15), to have it corrected (Art. 16) or deleted (Art. 17), to restrict its processing (Art. 18), to receive it in a portable format (Art. 20), and to object to processing based on legitimate interests (Art. 21). Write to the email address above; for example we can delete all download log entries for your Patreon ID.</p>

    <h2>9. Minors</h2>
    <p>This website is intended for adults only. We do not knowingly process data of people under 18.</p>

    <h2>10. Changes</h2>
    <p>We update this policy when the website or the law changes. The date at the top shows the latest version.</p>
  </article>
<?php layout_end();
