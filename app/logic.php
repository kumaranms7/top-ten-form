<?php
/**
 * Conditional logic + safe formula evaluation.
 * The JavaScript in public/assets/form.js implements the SAME rules for the live form; the server re-checks everything on submit.
 */

const LOGIC_OPS = ['is' => 'is', 'is_not' => 'is not', 'contains' => 'contains', 'not_contains' => 'does not contain', 'gt' => 'is greater than', 'lt' => 'is less than', 'empty' => 'is empty', 'not_empty' => 'is not empty'];

function logic_is_empty($v): bool { return $v === '' || $v === null || $v === []; }

function logic_rule_matches(string $op, $actual, string $expected): bool {
    $exp = mb_strtolower(trim($expected));
    $arr = is_array($actual);
    $list = $arr ? array_map(fn($x) => mb_strtolower(trim((string)$x)), $actual) : [];
    $str = $arr ? '' : mb_strtolower(trim((string)$actual));
    switch ($op) {
        case 'empty': return logic_is_empty($actual);
        case 'not_empty': return !logic_is_empty($actual);
        case 'is': return $arr ? in_array($exp, $list, true) : $str === $exp;
        case 'is_not': return !logic_rule_matches('is', $actual, $expected);
        case 'contains': return $arr ? in_array($exp, $list, true) : ($exp !== '' && mb_strpos($str, $exp) !== false);
        case 'not_contains': return !logic_rule_matches('contains', $actual, $expected);
        case 'gt': return !$arr && is_numeric($str) && is_numeric($exp) && (float)$str > (float)$exp;
        case 'lt': return !$arr && is_numeric($str) && is_numeric($exp) && (float)$str < (float)$exp;
    }
    return false;
}

/** $rules = [['field'=>..,'op'=>..,'value'=>..], ...]; $vals = effective values by field name. Empty rule list = true. */
function logic_eval_rules(string $match, array $rules, array $vals): bool {
    if (!$rules) return true;
    foreach ($rules as $r) {
        $ok = logic_rule_matches($r['op'] ?? 'is', $vals[$r['field'] ?? ''] ?? '', (string)($r['value'] ?? ''));
        if ($match === 'any' && $ok) return true;
        if ($match !== 'any' && !$ok) return false;
    }
    return $match !== 'any';
}

/** Should this field be shown, given the values entered so far? */
function logic_visible(array $field, array $vals): bool {
    $l = $field['logic'] ?? null;
    if (!$l || empty($l['rules'])) return true;
    $hit = logic_eval_rules($l['match'] ?? 'all', $l['rules'], $vals);
    return ($l['action'] ?? 'show') === 'hide' ? !$hit : $hit;
}

/** Notification condition {match, rules}: send only when it matches. No condition = always. */
function condition_matches(?string $json, array $vals): bool {
    $c = $json ? json_decode($json, true) : null;
    if (!is_array($c) || empty($c['rules'])) return true;
    return logic_eval_rules($c['match'] ?? 'all', $c['rules'], $vals);
}

/** Evaluate "{qty} * {price} + 5". Numbers, {field}, + - * / and parentheses only (no eval). Unknown/empty fields count as 0; division by zero gives 0. */
function formula_eval(string $formula, array $vals): float {
    $formula = trim($formula); $tokens = [];
    if ($formula === '' || !preg_match_all('/\s*(\{[a-z0-9_]+\}|\d+(?:\.\d+)?|\.\d+|[-+*\/()])/i', $formula, $m)) return 0.0;
    if (preg_replace('/\s*(\{[a-z0-9_]+\}|\d+(?:\.\d+)?|\.\d+|[-+*\/()])/i', '', $formula) !== '') return 0.0; // stray characters: refuse
    foreach ($m[1] as $t) {
        if ($t[0] === '{') { $v = $vals[trim($t, '{}')] ?? 0; $tokens[] = is_numeric($v) ? (float)$v : 0.0; }
        elseif (is_numeric($t)) $tokens[] = (float)$t;
        else $tokens[] = $t;
    }
    $pos = 0;
    $expr = function () use (&$tokens, &$pos, &$expr, &$term, &$factor): float {
        $v = $term();
        while ($pos < count($tokens) && ($tokens[$pos] === '+' || $tokens[$pos] === '-')) { $op = $tokens[$pos++]; $r = $term(); $v = $op === '+' ? $v + $r : $v - $r; }
        return $v;
    };
    $term = function () use (&$tokens, &$pos, &$factor): float {
        $v = $factor();
        while ($pos < count($tokens) && ($tokens[$pos] === '*' || $tokens[$pos] === '/')) { $op = $tokens[$pos++]; $r = $factor(); $v = $op === '*' ? $v * $r : ($r == 0 ? 0.0 : $v / $r); }
        return $v;
    };
    $factor = function () use (&$tokens, &$pos, &$expr, &$factor): float {
        if ($pos >= count($tokens)) return 0.0;
        $t = $tokens[$pos++];
        if ($t === '-') return -$factor();
        if ($t === '+') return $factor();
        if ($t === '(') { $v = $expr(); if (($tokens[$pos] ?? null) === ')') $pos++; return $v; }
        return is_float($t) ? $t : 0.0;
    };
    $res = $expr();
    return is_finite($res) ? $res : 0.0;
}

function format_number(float $n): string {
    $s = number_format($n, 2, '.', '');
    return rtrim(rtrim($s, '0'), '.') === '' ? '0' : rtrim(rtrim($s, '0'), '.');
}
