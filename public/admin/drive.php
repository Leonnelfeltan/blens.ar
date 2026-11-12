<?php
declare(strict_types=1);
function drive_folder_id(string $u): ?string {
    $u = trim($u);
    if (preg_match('#^https?://(?:drive|docs)\.google\.com/drive/(?:u/\d+/)?folders/([A-Za-z0-9_-]{10,})#i', $u, $m)) return $m[1];
    if (preg_match('#^https?://(?:drive|docs)\.google\.com/folders/([A-Za-z0-9_-]{10,})#i', $u, $m)) return $m[1];
    return null;
}
function drive_http_get(string $url): string {
    $o = []; $rc = 1;
    @exec('curl -sSL --compressed --max-time 60 -A ' . escapeshellarg('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36') . ' ' . escapeshellarg($url) . ' 2>/dev/null', $o, $rc);
    return $rc === 0 ? implode("\n", $o) : '';
}
function drive_folder_videos(string $fid): array {
    $htmls = [
        drive_http_get('https://drive.google.com/embeddedfolderview?id=' . rawurlencode($fid) . '#list'),
        drive_http_get('https://drive.google.com/drive/folders/' . rawurlencode($fid)),
    ];
    $ext = 'mp4|mov|mkv|webm|avi|m4v|mts|m2ts|3gp|flv|wmv|mpeg|mpg';
    $vids = [];
    foreach ($htmls as $html) {
        if ($html === '') continue;
        if (preg_match_all('#(?:id="entry-|data-id=")([A-Za-z0-9_-]{10,})"(.{0,8000}?)(?=(?:id="entry-|data-id=")|$)#is', $html, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $x) {
                if (!preg_match('#flip-entry-title[^>]*>(.*?)</div>#is', $x[2], $tm)) continue;
                $name = trim(html_entity_decode(strip_tags($tm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($name !== '' && preg_match('/\.(?:' . $ext . ')$/i', $name)) $vids[$x[1]] = $name;
            }
        }
        if (!$vids && preg_match_all('#"([A-Za-z0-9_-]{25,44})".{0,500}?"([^"<>]{1,255}\.(?:' . $ext . '))"#is', $html, $jm, PREG_SET_ORDER)) {
            foreach ($jm as $x) {
                $name = trim(html_entity_decode($x[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($name !== '' && preg_match('/\.(?:' . $ext . ')$/i', $name)) $vids[$x[1]] = $name;
            }
        }
    }
    return $vids;
}
