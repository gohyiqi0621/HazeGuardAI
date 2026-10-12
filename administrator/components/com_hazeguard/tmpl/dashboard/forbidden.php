<?php
/**
 * Shown when the visitor is not an administrator.
 *
 * The controller switches to this layout instead of the dashboard, so a
 * signed-out or non-admin visitor never reaches the data queries' output.
 * There is no sidebar here on purpose: navigation to panels they cannot open
 * would be furniture.
 *
 * @var \HazeGuard\Component\Hazeguard\Administrator\View\Dashboard\HtmlView $this
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Administrator — HazeGuard AI</title>
<?php $this->partial('_style'); ?>
</head>
<body class="gate">
  <div class="gate__box">
    <div class="gate__brand">HazeGuard <em>AI</em>
      <span class="gate__role">Administrator</span>
    </div>

    <h1>Administrator access required</h1>
    <p>This dashboard is limited to accounts in an usergroup with <strong>administrator</strong> access.</p>
    <p>Sign in with such an account from the app, then reload this page.</p>

    <div class="actions">
      <a class="btn btn--primary" href="/">Back to the app</a>
    </div>
  </div>
</body>
</html>
