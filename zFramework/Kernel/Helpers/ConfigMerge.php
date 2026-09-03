<?php

namespace zFramework\Kernel\Helpers;

/**
 * Carry a config file's settings across a framework update without losing the
 * file's shape.
 *
 * The obvious approach - read the old array, write it into the new file with
 * var_export() - loses two things: short array syntax, because var_export still
 * emits `array (`, and every comment in the new file, which in this framework
 * is where the settings are documented.
 *
 * So nothing is regenerated. The new file is taken verbatim and only the bytes
 * of the values that need to change are spliced, located with token_get_all()
 * rather than a regex. Comments, indentation and `[]` survive because they are
 * never rewritten.
 *
 * Per container, four cases:
 *
 *   same shape             -> patch the leaf scalars and lists. The new file's comments
 *                             for that section stay.
 *   the app added keys     -> take the app's container whole, or its additions
 *                             disappear. Its comments come with it; the shipped
 *                             comments inside that container do not - the one
 *                             unavoidable loss.
 *   the update added keys  -> keep the shipped container, or the new settings
 *                             disappear, which is the point of updating. Shared
 *                             leaves are still patched.
 *   both                   -> neither can be taken whole without losing the
 *                             other. The shipped one is kept and the app's extra
 *                             keys are reported to be re-added by hand, because
 *                             a merge that guesses here is worse than one that
 *                             says it cannot.
 *
 * A list - an array with no string keys, `['10.0.0.5', '10.0.0.6']` - is a value,
 * not a section: it is compared and spliced as one piece, the way a scalar is.
 * Located as a container it had no leaves, so a customised list was never
 * patched and the shipped default was written over trusted-proxies, error.mask
 * and mail's from without a word. An element that is itself a keyed array
 * (`[['host' => ..], ['host' => ..]]`) stays inside the list: its keys are not
 * paths, two elements would collide on the same one.
 *
 * A key that is a keyed array on one side and something else on the other has
 * changed shape between the two files. When the application filled in a section
 * the update ships empty its container is taken whole; the other direction is
 * reported, since neither side can be written without losing the other.
 *
 * A setting that moved to another file is followed by Update::configs(), which
 * sees every file at once; this class only ever sees two versions of one.
 */
class ConfigMerge
{
    /**
     * Map every `'key' => value` in a config file to its byte range.
     *
     * Keys are dot paths. A keyed array appears twice: once as the container
     * (`redis.database`, type array) and once per leaf inside it
     * (`redis.database.cache`, type scalar), so the caller can choose which to
     * replace. An array with no string keys is one entry of type list, covering
     * the whole literal; nothing inside it is located.
     *
     * @param string $source
     * @return array<string, array{type: string, offset: int, length: int}>
     */
    public static function locate(string $source): array
    {
        $tokens = token_get_all($source);
        $count  = count($tokens);

        # Absolute offset of every token, so a match can be spliced by byte.
        $offsets = [];
        $at      = 0;
        foreach ($tokens as $i => $token) {
            $offsets[$i] = $at;
            $at += strlen(is_array($token) ? $token[1] : $token);
        }

        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        $next = function (int $i) use ($tokens, $count, $skip) {
            for ($j = $i + 1; $j < $count; $j++)
                if (!(is_array($tokens[$j]) && in_array($tokens[$j][0], $skip, true))) return $j;
            return null;
        };

        $found  = [];
        $stack  = [];   # depth => key, for the arrays currently open
        $opened = [];   # depth => index of the '[' that opened it
        $depth  = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '[') {
                $depth++;
                $opened[$depth] = $i;
                continue;
            }

            if ($token === ']') {
                if (isset($stack[$depth])) {
                    $start = $offsets[$opened[$depth]];
                    $path  = self::path($stack, $depth);

                    # No leaf was located inside: a list, or an empty array. Either
                    # is one value to compare whole.
                    $keyed = false;
                    foreach ($found as $k => $_) if (str_starts_with($k, "$path.")) { $keyed = true; break; }

                    $found[$path] = [
                        'type'   => $keyed ? 'array' : 'list',
                        'offset' => $start,
                        'length' => $offsets[$i] + 1 - $start,
                    ];
                    unset($stack[$depth]);
                }
                $depth--;
                continue;
            }

            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) continue;

            # Inside a positional element - an unkeyed `[` somewhere below the first
            # keyed level. Its keys are not paths: the second element would land on
            # the first one's. The enclosing list is located whole instead.
            for ($d = 2; $d <= $depth; $d++) if (!isset($stack[$d])) continue 2;

            $arrow = $next($i);
            if ($arrow === null || !(is_array($tokens[$arrow]) && $tokens[$arrow][0] === T_DOUBLE_ARROW)) continue;

            $key   = trim($token[1], "'\"");
            $value = $next($arrow);
            if ($value === null) continue;

            # A nested array: remember the key so the closing bracket can name it.
            if ($tokens[$value] === '[') {
                $stack[$depth + 1] = $key;
                continue;
            }

            # The whole expression, not its first token. A value is whatever sits
            # between the arrow and the comma that ends it at this depth: `-1` is two
            # tokens, `60 * 60` five, `BASE_PATH . '/x'` three, and a closure is a
            # body full of them. Taking the first alone wrote `'gc' => -,` for -1,
            # `60` for an hour, and the shipped closure over a customised one - and
            # then walked into the closure's body and read its array literals as
            # config keys. The tokens of the value are stepped over afterwards.
            $end  = $value;
            $nest = 0;
            for ($j = $value; $j < $count; $j++) {
                $t  = $tokens[$j];
                $ch = is_array($t) ? null : $t;

                if ($ch === '(' || $ch === '[' || $ch === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) $nest++;
                elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                    if ($nest === 0) break;
                    $nest--;
                } elseif ($ch === ',' && $nest === 0) break;

                if (!(is_array($t) && in_array($t[0], $skip, true))) $end = $j;
            }

            $found[self::path($stack, $depth, $key)] = [
                'type'   => 'scalar',
                'offset' => $offsets[$value],
                'length' => $offsets[$end] + strlen(is_array($tokens[$end]) ? $tokens[$end][1] : $tokens[$end]) - $offsets[$value],
            ];

            $i = $end;
        }

        return $found;
    }

    /**
     * Merge a user's config file into the version that ships with the update.
     *
     * @param string $shipped What the new version ships.
     * @param string $current What the application has now.
     * @return array{source: string, changes: array<string>, manual: array<string>}
     */
    public static function merge(string $shipped, string $current): array
    {
        $new = self::locate($shipped);
        $old = self::locate($current);

        $patches = [];
        $changes = [];

        $manual = [];

        # Values first - scalars and lists - anything the application set to
        # something else. A key that is a keyed array on one side only has changed
        # shape: the application filling in a section the update ships empty is
        # taken whole, the other direction is reported.
        foreach ($new as $key => $entry) {
            if (!isset($old[$key])) continue;
            $mine = $old[$key];

            if ($entry['type'] === 'array' && $mine['type'] === 'array') continue;

            if ($entry['type'] === 'array' || $mine['type'] === 'array') {
                if ($entry['type'] === 'array') {
                    $manual[] = "$key is a keyed array in this version and a {$mine['type']} in yours - merge it by hand";
                    continue;
                }

                $patches[$entry['offset']] = [$entry['length'], substr($current, $mine['offset'], $mine['length'])];
                $changes[$entry['offset']] = "kept $key (yours has keys - taken whole)";
                continue;
            }

            $shippedValue = substr($shipped, $entry['offset'], $entry['length']);
            $currentValue = substr($current, $mine['offset'], $mine['length']);

            if ($shippedValue === $currentValue) continue;

            $patches[$entry['offset']] = [$entry['length'], $currentValue];
            $changes[$entry['offset']] = "kept $key = " . self::brief($currentValue);
        }

        # Containers where the shape differs. Which way it differs decides what
        # can be done, and the direction matters more than it looks:
        #
        #   the application added keys  -> take its container whole, or the
        #                                  additions disappear
        #   the update added keys       -> keep the shipped container, or the new
        #                                  settings disappear - which is the
        #                                  point of updating
        #   both                        -> neither can be taken whole without
        #                                  losing the other. Keep the shipped
        #                                  one, patch the shared leaves, and name
        #                                  what has to be re-added by hand.
        foreach ($new as $key => $entry) {
            if ($entry['type'] !== 'array' || !isset($old[$key])) continue;

            $under = fn(array $map) => array_keys(array_filter(
                $map,
                fn($k) => str_starts_with($k, "$key."),
                ARRAY_FILTER_USE_KEY
            ));

            $inNew = $under($new);
            $inOld = $under($old);

            $appAdded    = array_diff($inOld, $inNew);
            $updateAdded = array_diff($inNew, $inOld);

            if (!$appAdded && !$updateAdded) continue;

            if ($appAdded && $updateAdded) {
                foreach ($appAdded as $lost) $manual[] = "$lost must be added back by hand";
                continue;
            }

            # Only the update added keys: the shipped container already has
            # everything, and the shared leaves are patched above.
            if ($updateAdded) continue;

            foreach ($inNew as $inner) unset($patches[$new[$inner]['offset']], $changes[$new[$inner]['offset']]);

            $patches[$entry['offset']] = [$entry['length'], substr($current, $old[$key]['offset'], $old[$key]['length'])];
            $changes[$entry['offset']] = "kept $key (yours has extra keys - taken whole)";
        }

        # Right to left, so an earlier splice cannot move a later offset.
        krsort($patches);

        $source = $shipped;
        foreach ($patches as $offset => [$length, $text]) $source = substr_replace($source, $text, $offset, $length);

        krsort($changes);

        return [
            'source'  => $source,
            'changes' => array_values($changes),
            'manual'  => array_values(array_unique($manual)),
        ];
    }

    /**
     * Keys the new version adds and keys it drops, for reporting. Neither is
     * acted on: an added key arrives with the shipped file anyway, and a dropped
     * one is the developer's to remove.
     *
     * @param string $shipped
     * @param string $current
     * @return array{added: array<string>, removed: array<string>}
     */
    public static function keyDrift(string $shipped, string $current): array
    {
        $new = array_keys(array_filter(self::locate($shipped), fn($e) => $e['type'] !== 'array'));
        $old = array_keys(array_filter(self::locate($current), fn($e) => $e['type'] !== 'array'));

        return [
            'added'   => array_values(array_diff($new, $old)),
            'removed' => array_values(array_diff($old, $new)),
        ];
    }

    /**
     * A value on one line for the report: a closure or a list spans many.
     *
     * @param string $value
     * @return string
     */
    private static function brief(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value));
        return strlen($value) > 72 ? substr($value, 0, 69) . '...' : $value;
    }

    /**
     * @param array $stack
     * @param int   $depth
     * @param string|null $leaf
     * @return string
     */
    private static function path(array $stack, int $depth, ?string $leaf = null): string
    {
        $path = [];
        for ($d = 1; $d <= $depth; $d++) if (isset($stack[$d])) $path[] = $stack[$d];
        if ($leaf !== null) $path[] = $leaf;

        return implode('.', $path);
    }
}
