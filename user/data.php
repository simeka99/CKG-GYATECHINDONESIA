<?php

ob_start();
$page_title = 'Data Peserta';
require_once __DIR__ . '/../includes/header.php';

$db = db();
$uid = (int) $_SESSION['user_id'];
$success = '';
$filter_usia = ''; // Compatibility guard for legacy partials still referencing this variable.

$data_candidates = [__DIR__ . '/data', __DIR__ . '/Data'];
$data_partial_base = __DIR__ . '/data';
$best_score = PHP_INT_MIN;
$best_mtime = -1;
$has = static fn(string $text, string $needle): bool => strpos($text, $needle) !== false;

foreach ($data_candidates as $cand) {
    $toolbar = $cand . '/ui_toolbar.php';
    if (!is_file($toolbar)) continue;

    $content = (string) @file_get_contents($toolbar);
    $mtime = (int) @filemtime($toolbar);
    $score = 0;

    // Prioritaskan template terbaru: tanpa Filter Usia + BPJS gagal-only.
    if (!$has($content, 'filter_usia') && !$has($content, 'Filter Usia')) $score += 3;
    if ($has($content, "sync_bpjs_bulk_start(") && $has($content, "'gagal'")) $score += 2;
    if ($has($content, 'data-count-gagal')) $score += 1;
    if ($has($content, 'bpjs_bulk_scope_menu')) $score -= 2;
    if ($has($content, 'toggle_bpjs_bulk_menu')) $score -= 2;

    if ($score > $best_score || ($score === $best_score && $mtime > $best_mtime)) {
        $best_score = $score;
        $best_mtime = $mtime;
        $data_partial_base = $cand;
    }
}

$data_web_base = '/user/' . basename($data_partial_base);

require_once $data_partial_base . '/actions.php';
require_once $data_partial_base . '/query.php';
require_once $data_partial_base . '/ui.php';

require_once __DIR__ . '/../includes/footer.php';

