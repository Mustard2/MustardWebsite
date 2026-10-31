<?php
// /privacy/  Privacy policy (GDPR). Controller details: Admin → Settings → Privacy → Legal & Contact (or config.php → 'legal').
require __DIR__ . '/app/_init.php';

$legal = m3d_legal();
$missing = '[add this in Admin → Settings → Privacy → Legal & Contact]';
$name = trim((string) ($legal['name'] ?? '')) ?: $missing;
$address = trim((string) ($legal['address'] ?? '')) ?: $missing;
$email = trim((string) ($legal['email'] ?? ''));
$sc = m3d_scenes_on();   // scenes are named only while they are on
$sessionDays = (int) round(m3d_session_lifetime() / 86400);
$retention = (int) ($M3D['log_retention_days'] ?? 365);
$retentionText = $retention % 365 === 0 ? ($retention / 365 === 1 ? '12 months' : ($retention / 365) . ' years') : "$retention days";
$updated = '27 October 2026';
$avOn = m3d_avatars_on();
$avStored = !$avOn && m3d_avatars_count() > 0;   // turned off: the pictures are deleted then, so this is only a safety net

layout_start('Privacy Policy', 'How ' . site_name() . ' handles personal data.', 'page-legal');
?>
  <article class="wrap legal">
    <h1>Privacy Policy</h1>
    <p class="muted">Last updated: <?= e($updated) ?></p>

    <p class="lead-sm">This website shows 3D character models<?= $sc ? ', accessories and scenes' : ' and accessories' ?> and lets Patreon members download them. It uses no analytics, advertising or tracking of any kind. This page explains, as the EU General Data Protection Regulation (GDPR) requires, which personal data is processed, why, and what your rights are.</p>

    <h2>1. Who Is Responsible</h2>
    <p>Controller under Art. 4(7) GDPR:</p>
    <p class="pre addr"><?= e($name) ?>
<?= e($address) ?></p>
    <p>Email: <?php if ($email): ?><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a><?php else: ?><?= e($missing) ?><?php endif ?></p>

    <h2>2. Visiting the Website</h2>
    <ul>
      <li><b>What:</b> the technical data every browser sends (IP address, date and time, the page requested, the page you came from, browser type and operating system).</li>
      <li><b>Why:</b> to deliver the pages and keep the website secure, for example against attacks and abuse. We don’t combine this data with other sources or use it to identify you.</li>
      <li><b>Hosting:</b> our hosting provider runs the website and keeps short-term server logs, on our behalf under a data processing agreement (Art. 28 GDPR).</li>
      <li><b>Delivery and protection:</b> requests pass through a content delivery and protection service in the USA, which delivers the pages quickly and filters malicious traffic. It processes IP addresses and request data on our behalf under a data processing agreement. Transfers to the USA rely on the EU–US Data Privacy Framework and the EU Standard Contractual Clauses.</li>
      <li><b>Fonts</b> come from this website itself: your browser doesn’t contact Google or other font providers.</li>
      <li><b>Legal basis:</b> Art. 6(1)(f) GDPR (our legitimate interest in a secure, working website).</li>
    </ul>

    <h2>3. Cookies and Browser Storage</h2>
    <p>Only cookies that are strictly necessary for features you use, so no consent banner is needed (§ 25(2) TDDDG). None are used for tracking or advertising.</p>
    <div class="table-wrap">
      <table class="legal-table">
        <thead><tr><th>Name</th><th>Purpose</th><th>Duration</th></tr></thead>
        <tbody>
          <tr><td><code>m3d_age_ok</code></td><td>Remembers that you confirmed you are 18 or older. Contains only “1”.</td><td>30 days</td></tr>
          <tr><td><code>m3d_session</code></td><td>Keeps you logged in after the Patreon login (section 4). Only set when you log in.</td><td><?= e(m3d_plural($sessionDays, 'day')) ?>, or until you log out</td></tr>
          <tr><td><code>m3d_unlock</code></td><td>Remembers the password-protected pages and downloads you opened, so you don’t type the password again. Only set when you enter one; a short code per page or download, nothing about you.</td><td><?= M3D_UNLOCK_DAYS ?> days</td></tr>
          <tr><td><code>__cf_bm</code>, <code>cf_clearance</code><br><span class="muted small">set by the protection service</span></td><td>Help the protection service (section 2) tell people from bots. Only set when needed, <code>cf_clearance</code> only after a security check. Not used for tracking.</td><td>30 minutes (after a security check, as set in that service)</td></tr>
<?php $emoUsed = m3d_emoji_storage_on(); ?>
          <tr><td><code>m3d_notice</code>, <code>m3d_tabs</code><?= $emoUsed ? ', <code>m3d_emoji</code>' : '' ?><br><span class="muted small">browser storage, not cookies</span></td><td>Only after you close the message bar at the top (a short code of that message)<?= $emoUsed ? ',' : ' or' ?> click a tab in the documentation (the tab names you chose)<?= $emoUsed ? ' or pick an emoji in a comment box (the emoji you used last and the skin tone you chose)' : '' ?>. Never sent to us.</td><td>Until you clear your browser data</td></tr>
        </tbody>
      </table>
    </div>

    <h2>4. Logging In with Patreon</h2>
    <p>“Log In with Patreon” sends you to Patreon, Inc. (USA), where you log in and agree to share data with this website. Patreon then sends us only:</p>
    <ul>
      <li>your Patreon user ID and profile name<?= $avOn ? ', and the address of your profile picture' : '' ?>;</li>
      <li>your membership status for the <?= e(site_name()) ?> campaign and the tiers you hold (to check you are on a paid tier);</li>
      <li>when your current membership started (worked out from Patreon’s start date and your pledge history; only the resulting date is kept), your total support, your current pledge and the date and status of your last payment (some downloads need a minimum tier, time as a patron or total support).</li>
    </ul>
    <ul>
      <li><b>Not received:</b> your email address, password or payment details. We don’t store the access token Patreon issues.</li>
      <li><b>Where you see it:</b> your <a href="/account/">My account</a> page while you are logged in.</li>
      <li><b>How long:</b> in a session file on our server while you stay logged in (at most <?= e(m3d_plural($sessionDays, 'day')) ?>), deleted when you log out or shortly after the login expires. Your tier, membership start and total support are also saved with each download (section 5).</li>
      <li><b>Checked again</b> with Patreon about every <?= (int) round(($M3D['recheck_after'] ?? 43200) / 3600) ?> hours while you are logged in.</li>
      <li><b>Legal basis:</b> Art. 6(1)(b) GDPR (providing the downloads your membership entitles you to, at your request). Patreon’s own processing: <a href="https://privacy.patreon.com/policies" rel="noopener">Patreon’s privacy policy</a>. Transfers to Patreon in the USA rely on the EU Standard Contractual Clauses / EU–US Data Privacy Framework as applicable to Patreon.</li>
    </ul>
    <?php if ($avOn || $avStored): ?>
    <h3>Profile Picture</h3>
    <ul>
      <li><b>What:</b> <?= $avOn ? 'at' : 'while profile pictures were on, at' ?> each login our server copies your Patreon profile picture once, shrinks it to at most <?= AVATAR_SIZE_LG ?> × <?= AVATAR_SIZE_LG ?> pixels (plus a smaller copy) and keeps it. Your browser never loads it from Patreon.</li>
      <li><b>Where it shows:</b> next to your comments and bug reports (to every visitor), in the header’s account menu (only to you) and to the site owner in the admin.</li>
      <li><b>How long:</b> replaced at your next login; deleted when you turn off “Show My Patreon Picture” on your Settings page (only your Patreon user ID is kept as that choice, so it never comes back), when you remove it on Patreon (at your next login), when the site owner turns profile pictures off, or when you ask us.</li>
      <li><b>Legal basis:</b> Art. 6(1)(f) GDPR (our legitimate interest in showing who wrote a comment or report). You can object at any time with that switch.</li>
    </ul>
    <?php endif ?>
    <h3>Exceptions and Bans</h3>
    <ul>
      <li><b>Exceptions:</b> if <?= e(site_name()) ?> gives you access without a paid tier, or lets you skip or change the extra requirements or the download limits (for example as a tester), your Patreon user ID is stored with a name, a short note and the access chosen for you, until the exception is removed. Legal basis: Art. 6(1)(b) GDPR (providing the access agreed with you).</li>
      <li><b>Bans:</b> if <?= e(site_name()) ?> stops an account from logging in, downloading, reporting bugs, commenting or sharing renders (for example after sharing patron files publicly), your Patreon user ID is stored with what is turned off and the reason, which you are shown when you try. Kept until the ban is lifted. Legal basis: Art. 6(1)(f) GDPR (our legitimate interest in protecting paid content and the community).</li>
    </ul>

    <h2>5. Downloads</h2>
    <ul>
      <li><b>What:</b> for each patrons-only download, the date and time, your Patreon user ID and name, the model<?= $sc ? ', accessory or scene' : ' or accessory' ?> (and its version), the link, and your membership at that moment as Patreon reported it (tier price, membership start and months, total support). No IP address.</li>
      <li><b>Why:</b> to protect patron-only files from abuse (for example finding out which account shared a link publicly), to know which files are used, to apply the download limits (shown on My account), and to check afterwards why a download was allowed.</li>
      <li><b>How long:</b> deleted automatically after <?= e($retentionText) ?>.</li>
      <li><b>Counters:</b> every click on a download link, free ones too, adds one to that item’s total, with nothing about who clicked (so free links pass through this website for a moment).</li>
      <li><b>Waiting page:</b> its link carries the time it was opened and a signature, so the wait is checked without a cookie. If you are logged in, your session also remembers, for those few seconds, which download is counting down (one at a time), and for 10 minutes which links you downloaded (so pressing Download again isn’t counted twice).</li>
      <li><b>Legal basis:</b> Art. 6(1)(f) GDPR (our legitimate interest in protecting paid content).</li>
    </ul>

    <h2>6. Likes and Other Choices You Make</h2>
    <p>For these we never store your Patreon ID or name, only a code computed from your Patreon user ID with a secret key (a keyed hash): it can’t be turned back into your ID without the key and isn’t linked to your name. Legal basis: Art. 6(1)(b) GDPR (features you choose to use)<?= m3d_welcome_stored() ? ', and for the welcome page Art. 6(1)(f) GDPR (our legitimate interest in not showing you the same page at every login)' : '' ?>.</p>
    <ul>
      <li><b>Likes</b> on models, accessories, <?= $sc ? 'scenes, ' : '' ?>gallery pictures and blog posts, so each person likes each one once. Only the number is shown. Kept until you remove it or the item is removed. The time of a new like is also kept for <?= LIKES_FRESH_DAYS ?> days, apart from the code, so <?= e(site_name()) ?> learns how many new likes an item got (never who gave them).</li>
      <li><b>Ignored updates:</b> the model<?= $sc ? ', accessory or scene' : ' or accessory' ?> and version you chose to ignore (the ✕ on an update in the bell or on My account), so the “Update” mark stays hidden on every device. Gone when you choose “Stop Ignoring” (My account or Settings), the item is removed, or it no longer applies.</li>
      <li><b>Wrong passwords:</b> when you are logged in, the time of each wrong try on a password-protected page or download, for <?= (int) (PW_TRIES_SECS / 60) ?> minutes, so too many tries pause only your own. Legal basis: Art. 6(1)(f) GDPR (keeping passwords from being guessed).</li>
      <li><b>Notifications bell:</b> which notifications you opened (so its number counts only new ones), which you removed with ✕ (kind, which one, when), and which kinds you switched off on your Settings page. What it lists comes from data already stored (your downloads and bookmarks, the reports you follow, replies to your comments, reactions to your comments and bug reports (how many of each, never who), your renders). Opened and removed ones are forgotten after <?= UBELL_DAYS ?> days; your switches stay until you change them or ask us to delete them.</li>
      <li><b>Bookmarks:</b> the models<?= $sc ? ', accessories and scenes' : ' and accessories' ?> you bookmarked, with the version out then and the date, so the bell tells you about newer ones and My Account lists them, and the downloaded ones you un-bookmarked, so their updates stay hidden. Kept until you remove the bookmark or ask us to delete them.</li>
<?php if (m3d_welcome_stored()): ?>
      <li><b>Welcome page:</b> that you have seen it, with the date, so it shows only once after your first login. Kept until <?= e(site_name()) ?> resets the welcome page.</li>
<?php endif ?>
<?php if (m3d_blog_on() || m3d_polls_stored()): ?>
      <li><b>Poll votes</b> on blog posts: the choices you picked, so each patron votes once (and can change it when the poll allows). Only the totals are shown. Kept until the post is removed.</li>
<?php endif ?>
<?php if (m3d_bugs_on()): ?>
      <li><b>“Me Too” and following bug reports</b> (section 7): that you pressed “Me Too” (only the number is shown) and, for reports you follow, when you last opened them, to show what is new. Stop with “Following” on the report or “Unfollow” on your Settings page.</li>
<?php endif ?>
<?php if (m3d_reactions_on() || m3d_reactions_stored()): ?>
      <li><b>Reactions</b> (👍, ❤️…) on comments and bug reports, so each person gives each reaction once. Everyone sees only the numbers. Kept until you take it back or the comment or report is removed.</li>
<?php endif ?>
<?php if (m3d_ideas_on() || m3d_ideas_stored()): ?>
      <li><b>Idea votes</b> on model<?= $sc ? ', accessory and scene' : ' and accessory' ?> pages, so each person votes once on each idea. Only the number is shown. Kept until you take your vote back or the idea is removed.</li>
<?php endif ?>
<?php if (m3d_showcase_on() || m3d_showcase_stored()): ?>
      <li><b>Render defaults:</b> what the Share window of patron renders starts with, if you save it on your Settings page (whether to show your name, and your profile link). Kept until you change it.</li>
<?php endif ?>
    </ul>

<?php $n = 7; if (m3d_bugs_on()): $bs = m3d_bug_settings(); ?>
    <h2><?= $n++ ?>. Bug Reports</h2>
    <ul>
      <li><b>What:</b> reports and comments you write on the <a href="/bugs/">Bug reports</a> page, with your Patreon user ID, Patreon name and the date. They are public, with your Patreon name, so don’t put private details in them.</li>
      <li><b>Pictures:</b> links to pictures or videos on other websites are shown only as links, never loaded on our pages<?= $bs['uploads'] ? '; pictures you upload with a report are stored on our server and shown with it' : '' ?>.</li>
      <li><b>Following:</b> writing a report or commenting makes you follow it (section 6).</li>
<?php if (($bugDc = notify_bug_discord_words()) !== ''): ?>
      <li><b>Discord:</b> <?= e($bugDc) ?> are also sent to <?= e(site_name()) ?> through a private Discord channel (title, text and your Patreon name).</li>
<?php endif ?>
      <li><b>Why:</b> to fix the problems you report and tell others about them.</li>
      <li><b>How long:</b> until <?= e(site_name()) ?> deletes them; ask us (“Contacting Us”) to delete yours.</li>
      <li><b>Legal basis:</b> Art. 6(1)(b) GDPR (the bug report feature you choose to use).</li>
    </ul>
<?php endif ?>
<?php $cmShown = m3d_comments_on() || m3d_comments_count() > 0; if ($cmShown): $cs = m3d_comment_settings(); ?>
    <h2><?= $n++ ?>. Comments</h2>
    <ul>
      <li><b>What:</b> comments you write on models, accessories<?= $sc ? ', scenes' : '' ?> and blog posts, with your Patreon user ID, Patreon name and the date. They are public, with your Patreon name, to everyone who can see that page, so don’t put private details in them. Links show as links; pictures are never loaded from other websites.</li>
<?php if ($cs['moderate']): ?>
      <li><b>Checked first:</b> new comments<?= $cs['paid_skip'] ? ' from accounts without a paid tier' : '' ?> are checked by <?= e(site_name()) ?> before they show.</li>
<?php endif ?>
<?php if (m3d_notify_discord_on('comments')): ?>
      <li><b>Discord:</b> new comments are also sent to <?= e(site_name()) ?> through a private Discord channel (the text and your Patreon name).</li>
<?php endif ?>
      <li><b>Why:</b> to let patrons talk about the models and posts. Your Patreon user ID is kept with each comment so you can delete it, so <?= e(site_name()) ?> can apply the daily limit or block abuse, and to answer requests about your data.</li>
      <li><b>How long:</b> until you delete it (“Delete My Comment” in its ⋯ menu), <?= e(site_name()) ?> deletes it, or the model, accessory<?= $sc ? ', scene' : '' ?> or post is removed.</li>
      <li><b>Legal basis:</b> Art. 6(1)(b) GDPR (the comment feature you choose to use).</li>
    </ul>
<?php endif ?>
<?php if (m3d_ideas_on() || m3d_ideas_stored()): $is = m3d_idea_settings(); ?>
    <h2><?= $n++ ?>. Ideas</h2>
    <ul>
      <li><b>What:</b> ideas you suggest on a model’s<?= $sc ? ', accessory’s or scene’s' : ' or accessory’s' ?> page (the idea, its details and its kind), with your Patreon user ID, Patreon name and the date. They are public, with your Patreon name, to everyone who can see that page, so don’t put private details in them.</li>
      <li><b>Votes:</b> which ideas you voted for, stored under a scrambled code made from your Patreon user ID (like likes, section 6), never with your name. Everyone sees only the number of votes.</li>
<?php if ($is['moderate']): ?>
      <li><b>Checked first:</b> new ideas are checked by <?= e(site_name()) ?> before they show.</li>
<?php endif ?>
<?php if (m3d_notify_discord_on('ideas')): ?>
      <li><b>Discord:</b> new ideas are also sent to <?= e(site_name()) ?> through a private Discord channel (the idea and your Patreon name).</li>
<?php endif ?>
      <li><b>Why:</b> to hear what patrons would like for each model<?= $sc ? ', accessory and scene' : ' and accessory' ?>. Your Patreon user ID is kept with each idea so you can delete it, so <?= e(site_name()) ?> can apply the daily limit or block abuse, and to answer requests about your data.</li>
      <li><b>How long:</b> until you delete it (“Delete My Idea” in its ⋯ menu), <?= e(site_name()) ?> deletes it, or the model<?= $sc ? ', accessory or scene' : ' or accessory' ?> is removed. Click your vote again to take it back.</li>
      <li><b>Legal basis:</b> Art. 6(1)(b) GDPR (the ideas feature you choose to use).</li>
    </ul>
<?php endif ?>
<?php if (m3d_showcase_on() || m3d_showcase_stored()): ?>
    <h2><?= $n++ ?>. Renders You Share</h2>
    <ul>
      <li><b>What:</b> when you share a render from a model’s<?= $sc ? ', accessory’s or scene’s' : ' or accessory’s' ?> page (paid tier), the picture (resized, saved as WebP on our server), your caption, Patreon user ID and name, whether your name may be shown, the links to your X or Patreon profile and to a full-quality copy if you add them, and the date.</li>
      <li><b>Where it shows:</b> once <?= e(site_name()) ?> approves it, publicly on that page, with your Patreon name (linked to your profile, if given) or as “A patron”; <?= e(site_name()) ?> may also add it to the Gallery with the same credit. The full-quality link is only a link (“Download Full Size”); our pages never load it.</li>
      <li><b>Added for you:</b> a render you send another way (for example on Discord) may be added with the name you give and, if you agree, your Patreon user ID, so it shows in your My Account, where you can remove it.</li>
<?php if (m3d_notify_discord_on('renders')): ?>
      <li><b>Discord:</b> new renders are announced to <?= e(site_name()) ?> through a private Discord channel (your Patreon name and the caption, not the picture).</li>
<?php endif ?>
      <li><b>Why:</b> to show what patrons create with the models, as you asked when sharing.</li>
      <li><b>How long:</b> until you withdraw it: “Withdraw” or “Remove” on your render deletes the picture and everything stored with it at once, also from the Gallery. A rejected render is deleted at once.</li>
      <li><b>Legal basis:</b> Art. 6(1)(a) GDPR (your consent, given with the tick box when sharing), which you can withdraw at any time.</li>
    </ul>
<?php endif ?>
<?php if (m3d_mod_ids() || is_file(M3D_PRIVATE . '/data/modlog.json')): ?>
    <h2><?= $n++ ?>. Moderators</h2>
    <ul>
      <li><b>Who:</b> people <?= e(site_name()) ?> chose to help check comments, bug reports and ideas. They log in with their own Patreon account and act for <?= e(site_name()) ?>.</li>
      <li><b>What they see:</b> posts waiting for a check and hidden posts, with the name they were written under. They never see your Patreon user ID, your downloads or your membership.</li>
      <li><b>What they can do:</b> publish a post, hide it (it is kept, not deleted, and only moderators and <?= e(site_name()) ?> see it), and ban an account from commenting, reporting bugs or suggesting ideas, with a reason you are shown.</li>
      <li><b>The log:</b> each of these steps is recorded (when, who did it, the post and its author’s Patreon user ID, the reason), so <?= e(site_name()) ?> can check and undo it. The newest <?= MODLOG_KEEP ?> steps are kept. A moderator’s own Patreon user ID and name are stored while they are a moderator, and in the log. When someone is made a moderator or stops being one, the date is kept with their Patreon user ID for <?= UBELL_DAYS ?> days, to tell them in their notifications.<?php if (m3d_notify_discord_on('mods')): ?> Each step a moderator takes (their name, the author’s name, what it was and the reason) is also posted to a private Discord channel only <?= e(site_name()) ?> reads.<?php endif ?></li>
      <li><b>Legal basis:</b> Art. 6(1)(f) GDPR (our legitimate interest in keeping the community safe and free of spam).</li>
    </ul>
<?php endif ?>

    <h2><?= $n++ ?>. Links to Other Websites</h2>
    <p>Download links lead to file hosts such as Mega or Smutbase, and the site links to Patreon, Discord, X, GitHub, Blender Extensions and the networks at the bottom of each page<?= $cmShown || m3d_bugs_on() ? ', and to the websites people link in ' . ($cmShown && m3d_bugs_on() ? 'comments and bug reports' : ($cmShown ? 'comments' : 'bug reports')) : '' ?>. Once you follow such a link, that website’s privacy policy applies. Nothing from these services is embedded or loaded on our pages.</p>

    <h2><?= $n++ ?>. Contacting Us</h2>
    <p>If you email us, we use your message and address only to answer you, and delete them when the conversation is finished, unless the law requires us to keep them. Legal basis: Art. 6(1)(b) or (f) GDPR.</p>

    <h2><?= $n++ ?>. Your Rights</h2>
    <ul>
      <li>Access your data (Art. 15), have it corrected (Art. 16) or deleted (Art. 17), restrict its processing (Art. 18), receive it in a portable format (Art. 20), and object to processing based on legitimate interests (Art. 21).</li>
      <li>Write to the email address above with your Patreon user ID (shown on <a href="/account/">My account</a>): we can send you a copy of everything stored about you, or delete it.</li>
<?php if ($cmShown): ?>
      <li>You can delete your own comments yourself at any time.</li>
<?php endif ?>
<?php if (m3d_ideas_on() || m3d_ideas_stored()): ?>
      <li>You can delete your own ideas and take back your votes yourself at any time.</li>
<?php endif ?>
      <li>Many choices can be changed yourself on your <a href="/account/settings/">Settings</a> page.</li>
    </ul>

    <h2><?= $n++ ?>. Adults Only, and Changes</h2>
    <p>This website is for adults only; we don’t knowingly process data of people under 18. We update this policy when the website or the law changes; the date at the top shows the latest version.</p>
  </article>
<?php layout_end();
