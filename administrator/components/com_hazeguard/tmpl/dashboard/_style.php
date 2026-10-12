<?php
/**
 * Shared styles for the administrator templates.
 *
 * Included by the layouts with $this->partial('_style'). A leading underscore
 * marks it as a partial rather than a layout you can select.
 *
 * Direction: restrained utility. The admin is a tool someone uses daily, so
 * familiarity beats expression — one type family on a fixed scale, one accent
 * colour for selection and primary action, and three neutral layers (rail,
 * page, card) doing the structural work. Identity is carried by the sidebar,
 * not by decoration.
 *
 * @var \HazeGuard\Mvc\View\HtmlView $this
 */
?>
<style>
  :root {
    /* Three neutral layers: rail sits behind page, cards sit on top. */
    --rail: #e9efec; --bg: #f4f7f5; --surface: #ffffff;
    --ink: #0f1f19; --ink-2: #47635a; --line: #dde5e1; --line-2: #eef2f0;
    --accent: #047857; --accent-ink: #035e45; --accent-soft: #e7f2ed;
    --danger: #b42318; --danger-soft: #fdeaea;
    --ok-soft: #e7f6ef; --warn-soft: #fdf3e3; --warn-ink: #8a5a05;
    --radius: 12px; --radius-sm: 8px;
    --step: 150ms;
  }

  * { box-sizing: border-box; }
  body {
    margin: 0; background: var(--bg); color: var(--ink);
    font: 15px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    -webkit-font-smoothing: antialiased;
  }
  /* Browser surfaces belong to the design too. */
  ::selection { background: var(--accent-soft); color: var(--accent-ink); }
  :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 4px; }

  /* ------------------------------------------------------------- shell --- */
  .shell { display: grid; grid-template-columns: 244px minmax(0, 1fr); min-height: 100vh; }

  .rail {
    background: var(--rail); border-right: 1px solid var(--line);
    padding: 20px 14px 18px; position: sticky; top: 0; height: 100vh;
    overflow-y: auto; display: flex; flex-direction: column; gap: 20px;
  }
  .rail__brand { padding: 0 6px; }
  .rail__brand .name { font-size: 16px; font-weight: 700; letter-spacing: -.01em; display: block; }
  .rail__brand .name em { font-style: normal; color: var(--accent); }
  .rail__brand .role {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .09em; color: var(--ink-2); margin-top: 3px; display: block;
  }
  .rail__nav { display: flex; flex-direction: column; gap: 16px; flex: 1; }
  .rail__group-title {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .09em; color: var(--ink-2); padding: 0 6px; margin-bottom: 5px;
  }
  .rail__items { display: flex; flex-direction: column; gap: 2px; }
  .rail__item {
    display: block; padding: 7px 10px; border-radius: var(--radius-sm);
    font-size: 14px; font-weight: 500; color: var(--ink); text-decoration: none;
    transition: background var(--step) ease, color var(--step) ease;
  }
  .rail__item:hover { background: rgba(255, 255, 255, .72); }
  .rail__item.is-current { background: var(--accent); color: #fff; font-weight: 600; }
  .rail__foot { padding: 0 6px; font-size: 13px; }
  .rail__foot a { color: var(--ink-2); text-decoration: none; }
  .rail__foot a:hover { color: var(--accent); text-decoration: underline; text-underline-offset: 2px; }

  .main { padding: 30px 34px 72px; min-width: 0; }
  .main > * { max-width: 1000px; }

  /* ------------------------------------------------------------ header --- */
  .page-head { margin-bottom: 24px; }
  h1 { font-size: 22px; line-height: 1.25; margin: 0 0 5px; letter-spacing: -.01em; }
  p.lede { margin: 0; color: var(--ink-2); max-width: 72ch; font-size: 14px; }
  h2 { font-size: 15px; margin: 30px 0 10px; letter-spacing: -.005em; }

  /* -------------------------------------------------------------- tabs --- */
  .tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--line); margin: 0 0 24px; }
  .tab {
    padding: 9px 14px; font-size: 14px; font-weight: 600; color: var(--ink-2);
    text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px;
    transition: color var(--step) ease, border-color var(--step) ease;
  }
  .tab:hover { color: var(--ink); }
  .tab.is-current { color: var(--accent); border-bottom-color: var(--accent); }

  /* -------------------------------------------------------------- cards --- */
  .panel {
    background: var(--surface); border: 1px solid var(--line);
    border-radius: var(--radius); padding: 22px; max-width: 620px;
  }
  .panel--wide { max-width: none; }
  .empty { padding: 22px; color: var(--ink-2); }

  /* Overview: the numbers first, then the way into each panel. */
  .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(148px, 1fr)); gap: 10px; }
  .stat {
    background: var(--surface); border: 1px solid var(--line);
    border-radius: var(--radius); padding: 14px 16px;
  }
  .stat .n {
    font-size: 23px; font-weight: 700; line-height: 1.15;
    letter-spacing: -.02em; font-variant-numeric: tabular-nums;
  }
  .stat .k { font-size: 12px; color: var(--ink-2); margin-top: 3px; }

  .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(196px, 1fr)); gap: 10px; }
  .tile {
    display: block; padding: 14px 16px; text-decoration: none; color: var(--ink);
    background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
    transition: border-color var(--step) ease, background var(--step) ease;
  }
  .tile:hover { border-color: var(--accent); background: var(--accent-soft); }
  .tile .t { font-size: 14px; font-weight: 600; }
  .tile .d { font-size: 12px; color: var(--ink-2); margin-top: 2px; }

  /* Account status. */
  .pill {
    display: inline-block; padding: 2px 9px; border-radius: 999px;
    font-size: 11px; font-weight: 700; letter-spacing: .03em; text-transform: capitalize;
    background: var(--line-2); color: var(--ink-2);
  }
  .pill--active { background: var(--ok-soft); color: var(--accent-ink); }
  .pill--pending { background: var(--warn-soft); color: var(--warn-ink); }
  .pill--suspended { background: var(--danger-soft); color: var(--danger); }

  /* -------------------------------------------------------------- forms --- */
  .field { margin-bottom: 18px; }
  .field__label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
  .field input[type="text"], .field input[type="email"],
  .field input[type="password"], .field select {
    width: 100%; padding: 9px 11px; font: inherit; color: var(--ink);
    background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-sm);
    transition: border-color var(--step) ease, box-shadow var(--step) ease;
  }
  .field input:hover:not(:disabled), .field select:hover:not(:disabled) { border-color: #c3d1cb; }
  .field input:focus, .field select:focus {
    outline: none; border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(4, 120, 87, .14);
  }
  .field input:disabled, .field select:disabled {
    background: #f6f8f7; color: var(--ink-2); cursor: not-allowed;
  }
  .field .hint { font-size: 12px; color: var(--ink-2); margin-top: 5px; }
  .field .err { font-size: 12px; color: var(--danger); margin-top: 5px; font-weight: 500; }
  .field.has-error input, .field.has-error select { border-color: var(--danger); }
  .field.has-error input:focus, .field.has-error select:focus { box-shadow: 0 0 0 3px rgba(180, 35, 24, .14); }

  .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 22px; }
  .btn {
    display: inline-block; padding: 9px 16px; border-radius: var(--radius-sm);
    font: inherit; font-weight: 600; font-size: 14px; cursor: pointer;
    border: 1px solid var(--line); background: var(--surface); color: var(--ink);
    text-decoration: none;
    transition: background var(--step) ease, border-color var(--step) ease, color var(--step) ease;
  }
  .btn:hover { border-color: var(--accent); color: var(--accent); }
  .btn--primary { background: var(--accent); border-color: var(--accent); color: #fff; }
  .btn--primary:hover { background: var(--accent-ink); border-color: var(--accent-ink); color: #fff; }
  .btn:disabled { opacity: .55; cursor: not-allowed; }

  /* ------------------------------------------------------------ notices --- */
  .alert { padding: 11px 14px; border-radius: var(--radius-sm); margin: 0 0 18px; font-size: 14px; }
  .alert--error { background: var(--danger-soft); color: var(--danger); }
  .alert--ok { background: var(--ok-soft); color: var(--accent-ink); }

  /* ------------------------------------------------------------- tables --- */
  .table-wrap {
    overflow-x: auto; border: 1px solid var(--line);
    border-radius: var(--radius); background: var(--surface);
  }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: 11px 14px; border-bottom: 1px solid var(--line-2); font-size: 14px; vertical-align: middle; }
  th {
    background: #f8fbfa; font-size: 11px; text-transform: uppercase;
    letter-spacing: .07em; color: var(--ink-2); font-weight: 700; white-space: nowrap;
  }
  tbody tr { transition: background var(--step) ease; }
  tbody tr:hover { background: #fafcfb; }
  tbody tr:last-child td { border-bottom: 0; }
  td.num { font-variant-numeric: tabular-nums; color: var(--ink-2); }

  .col-actions { white-space: nowrap; text-align: right; }
  .col-actions form { display: inline; }
  .col-actions > * + * { margin-left: 12px; }
  .link {
    background: none; border: 0; padding: 0; font: inherit; font-size: 13px; font-weight: 600;
    cursor: pointer; color: var(--accent); text-decoration: underline; text-underline-offset: 2px;
  }
  .link:hover { color: var(--accent-ink); }
  .link--danger { color: var(--danger); }
  .link--danger:hover { color: #8a1a12; }

  footer.note {
    margin-top: 34px; padding-top: 14px; border-top: 1px solid var(--line);
    font-size: 13px; color: var(--ink-2); max-width: 72ch;
  }

  /* --------------------------------------------------------------- gate --- */
  /* Shown instead of the dashboard to anyone who is not an administrator.
     No sidebar here: there is nothing for them to navigate to yet. */
  body.gate { display: grid; place-items: center; min-height: 100vh; padding: 24px; }
  .gate__box {
    width: 100%; max-width: 430px; background: var(--surface);
    border: 1px solid var(--line); border-radius: var(--radius); padding: 28px;
  }
  .gate__brand { font-size: 15px; font-weight: 700; letter-spacing: -.01em; }
  .gate__brand em { font-style: normal; color: var(--accent); }
  .gate__role {
    display: block; margin-top: 3px; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .09em; color: var(--ink-2);
  }
  .gate__box h1 { font-size: 19px; margin: 22px 0 8px; }
  .gate__box p { margin: 0 0 10px; font-size: 14px; color: var(--ink-2); }
  .gate__box .actions { margin-top: 20px; }

  /* --------------------------------------------------------- narrow screens */  @media (max-width: 900px) {
    .shell { grid-template-columns: 1fr; }
    .rail {
      position: static; height: auto; border-right: 0; border-bottom: 1px solid var(--line);
      padding: 16px 14px 14px; gap: 14px;
    }
    .rail__nav { gap: 12px; }
    .rail__items { flex-direction: row; flex-wrap: wrap; }
    .rail__item { border: 1px solid var(--line); background: var(--surface); }
    .rail__item.is-current { border-color: var(--accent); }
    .main { padding: 22px 16px 56px; }
    .panel { max-width: none; padding: 18px; }
    h1 { font-size: 20px; }
    .tabs { overflow-x: auto; }
  }
</style>
