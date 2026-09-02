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
 *   same shape             -> patch the leaf scalars. The new file's comments
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
 * What it cannot do is follow a setting that moved to another file - view.php
 * becoming framework.php['view']. Only whoever made that change knows where it
 * went, so it belongs in a per-version migration rather than here.
 */
class ConfigMerge
{
    /**
     * Map every `'key' => value` in a config file to its byte range.
     *
     * Keys are dot paths. A nested array appears twice: once as the container
     * (`redis.database`) and once per leaf inside it (`redis.database.cache`),
     * so the caller can choose which to replace.
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
                    $found[self::path($stack, $depth)] = [
                        'type'   => 'array',
                        'offset' => $start,
                        'length' => $offsets[$i] + 1 - $start,
                    ];
                    unset($stack[$depth]);
                }
                $depth--;
                continue;
            }

            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) continue;

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

        # Leaves first: anything the application set to something else.
        foreach ($new as $key => $entry) {
            if ($entry['type'] !== 'scalar' || !isset($old[$key])) continue;

            $shippedValue = substr($shipped, $entry['offset'], $entry['length']);
            $currentValue = substr($current, $old[$key]['offset'], $old[$key]['length']);

            if ($shippedValue === $currentValue) continue;

            $patches[$entry['offset']] = [$entry['length'], $currentValue];
            $changes[$entry['offset']] = "kept $key = $currentValue";
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
        $manual = [];

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
                foreach ($appAdded as $lost) $manual[] = $lost;
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
        $new = array_keys(array_filter(self::locate($shipped), fn($e) => $e['type'] === 'scalar'));
        $old = array_keys(array_filter(self::locate($current), fn($e) => $e['type'] === 'scalar'));

        return [
            'added'   => array_values(array_diff($new, $old)),
            'removed' => array_values(array_diff($old, $new)),
        ];
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
