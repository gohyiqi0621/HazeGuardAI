<?php
/**
 * The administrator sidebar, shared by the overview and every panel.
 *
 * Groups and labels come from Entities::menu(), so a panel's name and its place
 * in the navigation are declared once, next to the entity itself.
 *
 * @var \HazeGuard\Component\Hazeguard\Administrator\View\Dashboard\HtmlView $this
 * @var string $current the view key to mark as the current page
 */

use HazeGuard\Component\Hazeguard\Administrator\Model\Entities;

$current = $current ?? '';
?>
<aside class="rail">
  <div class="rail__brand">
    <span class="name">HazeGuard <em>AI</em></span>
    <span class="role">Administrator</span>
  </div>

  <nav class="rail__nav" aria-label="Administrator">
    <?php foreach (Entities::menu() as $group => $items): ?>
      <div class="rail__group">
        <div class="rail__group-title"><?= $this->escape($group) ?></div>
        <div class="rail__items">
          <?php foreach ($items as $key => $label): ?>
            <a class="rail__item<?= $key === $current ? ' is-current' : '' ?>"
               href="index.php?option=com_hazeguard&amp;view=<?= $this->escape($key) ?>&amp;task=display"
               <?= $key === $current ? 'aria-current="page"' : '' ?>><?= $this->escape($label) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </nav>

  <div class="rail__foot"><a href="/">Back to the app</a></div>
</aside>
