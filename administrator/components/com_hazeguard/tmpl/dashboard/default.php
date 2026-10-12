<?php
/**
 * Dashboard layout — the administrator overview.
 *
 * Plain PHP template. $this is the view, so the data the controller assigned is
 * available as $this->stats and $this->users, and output goes through
 * $this->escape(). No Joomla template syntax is used anywhere.
 *
 * @var \HazeGuard\Component\Hazeguard\Administrator\View\Dashboard\HtmlView $this
 */

use HazeGuard\Component\Hazeguard\Administrator\Model\Entities;

$stats = $this->stats;
$users = $this->users;

$tiles = [
    ['users', 'Users'],
    ['pending', 'Pending'],
    ['active', 'Active'],
    ['suspended', 'Suspended'],
    ['usergroups', 'Usergroups'],
    ['credentials', 'API credentials'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Administrator — HazeGuard AI</title>
<?php $this->partial('_style'); ?>
</head>
<body>
<div class="shell">

  <?php $this->partial('_nav', ['current' => 'dashboard']); ?>

  <div class="main">

    <div class="page-head">
      <h1>Dashboard</h1>
      <p class="lede">Overview of accounts and credentials. Each panel creates, edits and deletes records, and every relationship between them is enforced in the database.</p>
    </div>

    <section class="stats">
      <?php foreach ($tiles as [$statKey, $statLabel]): ?>
        <div class="stat">
          <div class="n"><?= (int) ($stats[$statKey] ?? 0) ?></div>
          <div class="k"><?= $this->escape($statLabel) ?></div>
        </div>
      <?php endforeach; ?>
    </section>

    <h2>Management panels</h2>
    <section class="tiles">
      <?php foreach (Entities::all() as $key => $spec): ?>
        <a class="tile" href="index.php?option=com_hazeguard&amp;view=<?= $this->escape($key) ?>&amp;task=display">
          <div class="t"><?= $this->escape($spec['title']) ?></div>
          <div class="d"><?= $this->escape($spec['summary']) ?></div>
        </a>
      <?php endforeach; ?>
    </section>

    <h2>Newest accounts</h2>
    <?php if ($users === []): ?>
      <div class="panel empty">No accounts yet.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th><th>Username</th><th>Email</th><th>Group</th><th>Status</th><th>Created (UTC)</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($users as $user): ?>
            <tr>
              <td class="num"><?= (int) $user['id'] ?></td>
              <td><?= $this->escape($user['username']) ?></td>
              <td><?= $this->escape($user['email']) ?></td>
              <td><?= $this->escape($user['group_title'] ?? '—') ?></td>
              <td><span class="pill pill--<?= $this->escape($user['status']) ?>"><?= $this->escape($user['status']) ?></span></td>
              <td><?= $this->escape($user['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <footer class="note">
      Data comes straight from the existing schema (users, usergroups, credential_types).
      API keys are stored encrypted, never in plain text.
    </footer>

  </div>
</div>
</body>
</html>
