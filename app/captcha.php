<?php
/**
 * Built-in captcha (self-hosted, no accounts, no external requests).
 * Types: math (default), text (distorted characters), question (your own question + accepted answers).
 * Math and text challenges are drawn as an SVG image by the server, so the answer is never in the page's HTML.
 * Only a keyed hash of the answer is stored. Each challenge is single-use, expires in 10 minutes and allows 5 tries.
 * If Google reCAPTCHA or Cloudflare Turnstile is configured in Settings, that is used for every form instead.
 */
const CAPTCHA_TTL = 600;
const CAPTCHA_MAX_TRIES = 5;

/** The external provider that is actually usable (keys present), else 'none' (= use the built-in captcha). */
/** Captcha keys in force: the website's own (when it set a provider) else the platform's. @return array [provider, siteKey, secret] */
function captcha_cfg(): array {
    $sid = site_id();
    $own = $sid ? site_own_setting($sid, 'captcha_provider', 'inherit') : 'inherit';
    if ($own === 'none') return ['none', '', ''];
    if ($sid && in_array($own, ['recaptcha', 'turnstile'], true)) { $k = trim((string)site_own_setting($sid, 'captcha_site_key')); $sec = trim((string)site_own_setting($sid, 'captcha_secret_key')); return ($k !== '' && $sec !== '') ? [$own, $k, $sec] : ['none', '', '']; }
    $p = setting('captcha_provider', 'none'); $k = trim((string)setting('captcha_site_key')); $sec = trim((string)setting('captcha_secret_key'));
    return (in_array($p, ['recaptcha', 'turnstile'], true) && $k !== '' && $sec !== '') ? [$p, $k, $sec] : ['none', '', ''];
}
function captcha_provider(): string {
    return captcha_cfg()[0];
}
function captcha_required(): bool { return setting('captcha_required', '1') === '1'; }
function captcha_builtin_active(): bool { return captcha_required() && captcha_provider() === 'none'; }
function captcha_external_active(): bool { return captcha_required() && captcha_provider() !== 'none'; }

function captcha_norm(string $s): string { return mb_strtolower(preg_replace('/\s+/', ' ', trim($s))); }
function captcha_hash(string $answer, string $id): string { return hash_hmac('sha256', captcha_norm($answer), spam_secret() . '|' . $id); }

/** Default questions when a form has none of its own. Format of custom ones: one per line, "Question | answer, other answer". */
function captcha_default_questions(): array {
    return [['What colour is the sky on a clear day?', ['blue']], ['How many days are in a week? (a number)', ['7', 'seven']], ['What is the opposite of "hot"?', ['cold']],
        ['What is the first month of the year?', ['january']], ['How many legs does a dog have? (a number)', ['4', 'four']], ['What do you call frozen water?', ['ice']]];
}
function captcha_parse_questions(string $text): array {
    $out = [];
    foreach (preg_split('/\R/', $text) as $line) {
        if (strpos($line, '|') === false) continue;
        [$q, $a] = array_map('trim', explode('|', $line, 2));
        $answers = array_values(array_filter(array_map('trim', explode(',', $a)), 'strlen'));
        if ($q !== '' && $answers) $out[] = [mb_substr($q, 0, 200), array_slice($answers, 0, 8)];
    }
    return $out;
}

/** An SVG image (as a data URI) of the given characters, with per-character rotation / offset / colour plus noise. */
function captcha_svg(string $text): string {
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY); $n = count($chars);
    $step = 31; $w = max(170, $n * $step + 34); $h = 74;
    $rc = fn($a, $b) => random_int($a, $b);
    $dark = ['#1e3a8a', '#7c2d12', '#14532d', '#581c87', '#831843', '#134e4a', '#78350f', '#1f2937'];
    $bgs = [['#eef2ff', '#fdf2f8'], ['#ecfeff', '#f0fdf4'], ['#fffbeb', '#fef2f2'], ['#f5f3ff', '#eff6ff']];
    [$b1, $b2] = $bgs[$rc(0, count($bgs) - 1)];
    $svg = '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">'
        . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $b1 . '"/><stop offset="1" stop-color="' . $b2 . '"/></linearGradient></defs><rect width="100%" height="100%" fill="url(#g)"/>';
    for ($i = 0; $i < 6; $i++) // wavy background strokes
        $svg .= '<path d="M' . $rc(0, 20) . ' ' . $rc(8, $h - 8) . ' C ' . $rc($w / 4, $w / 2) . ' ' . $rc(0, $h) . ', ' . $rc($w / 2, $w * 3 / 4) . ' ' . $rc(0, $h) . ', ' . $rc($w - 20, $w) . ' ' . $rc(8, $h - 8) . '" stroke="' . $dark[$rc(0, 7)] . '" stroke-opacity=".28" stroke-width="' . ($rc(10, 25) / 10) . '" fill="none"/>';
    for ($i = 0; $i < 38; $i++) $svg .= '<circle cx="' . $rc(0, $w) . '" cy="' . $rc(0, $h) . '" r="' . ($rc(4, 20) / 10) . '" fill="' . $dark[$rc(0, 7)] . '" fill-opacity=".35"/>';
    foreach ($chars as $i => $ch) {
        $x = 20 + $i * $step + $rc(-3, 3); $y = 49 + $rc(-9, 9); $size = $rc(31, 41); $rot = $rc(-24, 24); $skew = $rc(-14, 14);
        $svg .= '<text x="' . $x . '" y="' . $y . '" font-size="' . $size . '" font-weight="700" font-family="Verdana,Geneva,Arial,sans-serif" fill="' . $dark[$rc(0, 7)] . '" transform="rotate(' . $rot . ' ' . $x . ' ' . $y . ') skewX(' . $skew . ')">' . htmlspecialchars($ch, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</text>';
    }
    for ($i = 0; $i < 3; $i++) // lines drawn over the characters
        $svg .= '<path d="M' . $rc(0, 12) . ' ' . $rc(6, $h - 6) . ' L ' . $rc($w - 12, $w) . ' ' . $rc(6, $h - 6) . '" stroke="' . $dark[$rc(0, 7)] . '" stroke-opacity=".55" stroke-width="' . ($rc(12, 22) / 10) . '"/>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg . '</svg>');
}

/** Build a challenge. Returns ['answers' => [...], 'img' => data-URI | null, 'question' => text | null, 'label' => accessible description, 'type' => ...]. */
function captcha_make(string $type, string $questionsText = ''): array {
    if ($type === 'question') {
        $bank = captcha_parse_questions($questionsText) ?: captcha_default_questions();
        [$q, $a] = $bank[random_int(0, count($bank) - 1)];
        return ['type' => 'question', 'answers' => $a, 'img' => null, 'question' => $q, 'label' => $q];
    }
    if ($type === 'text') {
        $set = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $s = '';
        for ($i = 0; $i < 5; $i++) $s .= $set[random_int(0, strlen($set) - 1)];
        return ['type' => 'text', 'answers' => [$s], 'img' => captcha_svg($s), 'question' => null, 'label' => 'Security check. Type the 5 characters shown in the image (letters are not case sensitive).'];
    }
    $op = ['+', '-', '×'][random_int(0, 2)];
    if ($op === '+') { $a = random_int(2, 15); $b = random_int(2, 15); $ans = $a + $b; }
    elseif ($op === '-') { $a = random_int(8, 25); $b = random_int(2, $a - 1); $ans = $a - $b; }
    else { $a = random_int(2, 9); $b = random_int(2, 6); $ans = $a * $b; }
    return ['type' => 'math', 'answers' => [(string)$ans], 'img' => captcha_svg("$a $op $b = ?"), 'question' => null, 'label' => 'Security check. Solve the math problem shown in the image and type the number.'];
}

/** Create a challenge for a form and return what the browser needs (never the answer). */
function captcha_issue(int $fid, string $type, string $questionsText = ''): array {
    if (!in_array($type, ['math', 'text', 'question'], true)) $type = 'math';
    $c = captcha_make($type, $questionsText);
    $id = bin2hex(random_bytes(10));
    q('INSERT INTO captcha_challenges (id, form_id, answers_json, expires_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL ? SECOND))',
        [$id, $fid, json_encode(array_map(fn($a) => captcha_hash($a, $id), $c['answers'])), CAPTCHA_TTL]);
    return ['id' => $id, 'type' => $c['type'], 'img' => $c['img'], 'question' => $c['question'], 'label' => $c['label']];
}

/** Check an answer. Returns ['ok' => bool, 'msg' => string, 'new' => bool (the old challenge is gone: load a new one)]. */
function captcha_verify(string $id, string $answer, int $fid): array {
    if (!preg_match('/^[a-f0-9]{20}$/', $id)) return ['ok' => false, 'msg' => 'Please answer the security check.', 'new' => true];
    $row = q('SELECT * FROM captcha_challenges WHERE id=? AND form_id=? AND expires_at > NOW()', [$id, $fid])->fetch();
    if (!$row) return ['ok' => false, 'msg' => 'The security check expired. Please answer the new one.', 'new' => true];
    if ($row['passed']) return ['ok' => true, 'msg' => '', 'new' => false];            // solved earlier; the visitor is fixing another field
    if (trim($answer) === '') return ['ok' => false, 'msg' => 'Please answer the security check.', 'new' => false];
    $h = captcha_hash($answer, $id);
    foreach ((array)json_decode($row['answers_json'], true) as $good) if (hash_equals($good, $h)) { q('UPDATE captcha_challenges SET passed=1 WHERE id=?', [$id]); return ['ok' => true, 'msg' => '', 'new' => false]; }
    if ($row['attempts'] + 1 >= CAPTCHA_MAX_TRIES) { q('DELETE FROM captcha_challenges WHERE id=?', [$id]); return ['ok' => false, 'msg' => 'Incorrect answer. Here is a new security check.', 'new' => true]; }
    q('UPDATE captcha_challenges SET attempts=attempts+1 WHERE id=?', [$id]);
    return ['ok' => false, 'msg' => 'Incorrect answer. Please try again.', 'new' => false];
}
/** Use up a solved challenge once the submission really went through. */
function captcha_consume(string $id): void { q('DELETE FROM captcha_challenges WHERE id=?', [$id]); }
