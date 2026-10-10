<?php
// /mustardui/  MustardUI, the add-on built into the models: what it is, where to get it, wiki guides and issues.
require __DIR__ . '/app/_init.php';
if (!m3d_mustardui_on()) not_found();   // Admin → Settings → MustardUI

$wiki = SITE['mustarduiWiki'];
$guides = [
    ['title' => 'User guide', 'text' => 'Everything the panel can do: outfits, hair, body, physics, morphs and settings, section by section.', 'href' => "$wiki/User-Guide"],
    ['title' => 'Developer guide', 'text' => 'For creators: configure MustardUI on your own characters and add your outfits, morphs and settings.', 'href' => "$wiki/Developer-Guide"],
];
$features = [
    ['Outfits', 'Switch outfits and single pieces on and off, and add your own outfits to the list.'],
    ['Hair', 'Pick the hairstyle and change its settings from the same panel.'],
    ['Body & skin', 'Skin shader settings like subsurface scattering and translucency, no node digging needed.'],
    ['Physics', 'Physics for body parts, hair and clothes, turned on only when you need them.'],
    ['Morphs', 'Shape keys and morphs as sliders; disable them all at once to speed things up.'],
    ['Simplify & tools', 'One-click Simplify for a smooth viewport, plus tools like Clean Model and breathing animation.'],
];

layout_start('MustardUI', 'MustardUI, the free Blender add-on built into the ' . site_name() . ' models: guides, wiki and bug reports.', 'page-mustardui');
?>
  <div class="wrap mui-page">
    <section class="mui mui-hero">
      <div>
        <p class="eyebrow">Open source · Free</p>
        <h1 class="h2">MustardUI</h1>
        <p class="lead">
          The control panel built into the models. Change outfits, hair, skin, physics and morphs from one
          artist-friendly panel in Blender, without writing a line of Python.
        </p>
        <div class="hero-cta">
          <a class="btn btn-primary" href="<?= e(SITE['mustarduiExt']) ?>" rel="noopener">Get it on Blender Extensions</a>
          <a class="btn btn-ghost" href="<?= e(SITE['mustardui']) ?>" rel="noopener">View on GitHub</a>
        </div>
      </div>
      <?php $shot = asset('mustardui-panel.webp'); // the Outfits panel, from the MustardUI wiki ?>
      <div class="mui-shot" data-slides="<?= slides_attr([['src' => $shot, 'caption' => 'The Outfits panel of MustardUI in Blender']]) ?>">
        <button type="button" data-slide="0" aria-label="Enlarge the screenshot">
          <img src="<?= e($shot) ?>" width="754" height="648" alt="The Outfits panel of MustardUI in Blender: outfit list, pieces, Optimize and Extras" decoding="async" />
        </button>
      </div>
    </section>

    <section class="section">
      <div class="section-head"><div><p class="eyebrow">What it does</p><h2 class="h2">One panel for the whole character</h2></div></div>
      <ul class="mui-features">
        <?php foreach ($features as [$t, $d]): ?>
          <li><b><?= e($t) ?></b><span class="muted"><?= e($d) ?></span></li>
        <?php endforeach ?>
      </ul>
    </section>

    <section class="section" id="wiki">
      <div class="section-head">
        <div><p class="eyebrow">Wiki</p><h2 class="h2">Guides</h2></div>
        <a class="btn btn-ghost btn-sm" href="<?= e($wiki) ?>" target="_blank" rel="noopener">Open the wiki ↗</a>
      </div>
      <div class="contact-grid">
        <?php foreach ($guides as $g): ?>
          <a class="contact-card" href="<?= e($g['href']) ?>" target="_blank" rel="noopener">
            <h3><?= e($g['title']) ?></h3>
            <p><?= e($g['text']) ?></p>
            <span class="contact-cta">Read on the wiki ↗</span>
          </a>
        <?php endforeach ?>
      </div>
      <p class="small muted mui-note">Questions about the models themselves (requirements, outfits, poses) are in the <a href="<?= e(SITE['docs']) ?>">documentation</a> and the <a href="<?= e(SITE['faqs']) ?>">FAQ</a>.</p>
    </section>

    <section class="section" id="issues">
      <div class="section-head"><div><p class="eyebrow">Help</p><h2 class="h2">Found a bug?</h2></div></div>
      <div class="contact-grid">
        <a class="contact-card" href="<?= e(SITE['mustarduiIssues']) ?>" target="_blank" rel="noopener">
          <h3>Report an issue <span class="tag-pref">GitHub</span></h3>
          <p>Bugs and feature ideas for MustardUI go to GitHub issues, so they can be tracked and fixed. Search first: someone may have reported it already. Include your Blender and MustardUI versions.</p>
          <span class="contact-cta">Open the issues ↗</span>
        </a>
        <a class="contact-card" href="<?= e(social_url('discord')) ?>" target="_blank" rel="noopener">
          <h3>Ask on Discord</h3>
          <p>Not sure it's a bug, or having trouble with a specific model? Ask in #bug-report on the Discord server.</p>
          <span class="contact-cta">Join the Discord ↗</span>
        </a>
      </div>
    </section>
  </div>
<?php layout_end();
