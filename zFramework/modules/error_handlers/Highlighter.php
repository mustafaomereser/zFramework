<?php

namespace zFramework\modules\error_handlers;

/**
 * Syntax colouring for the code panels of the error page.
 *
 * PHP's own lexer does the work. token_get_all() reads the snippet exactly as
 * the engine would - strings that span lines, heredocs, comments holding
 * quotes, HTML with PHP inside it - and hands back typed tokens; each becomes a
 * <span> with a class and nothing else has to be guessed. The regex approach
 * this replaces worked one line at a time and could not know that `class` sat
 * inside a string, or that a comment had begun three lines up.
 *
 * Lines are split after colouring, not before, so a token that crosses a line
 * break is closed at the end of one line and reopened on the next.
 */
class Highlighter
{
    /**
     * Colour a whole file and return its lines, each already escaped HTML.
     *
     * The complete file is tokenised rather than the window being shown: a
     * window opening halfway through a string or a docblock would read as code.
     *
     * @param string $source
     * @return string[] 1-based is the caller's business; this is 0-based.
     */
    public static function lines(string $source): array
    {
        # A template starts as inline HTML; a class file starts with <?php. The
        # lexer tells them apart on its own, so nothing is prepended.
        $tokens = @token_get_all($source);

        # Which spans are still open when a line ends, reopened on the next line.
        $lines   = [];
        $current = '';

        foreach ($tokens as $token) {
            [$text, $class] = is_array($token) ? [$token[1], self::classOf($token[0], $token[1])] : [$token, 'p'];

            $pieces = explode("\n", $text);
            $last   = count($pieces) - 1;

            foreach ($pieces as $i => $piece) {
                if ($piece !== '') $current .= $class ? '<span class="t-' . $class . '">' . ($class === 'html' ? self::template(htmlspecialchars($piece, ENT_QUOTES)) : htmlspecialchars($piece, ENT_QUOTES)) . '</span>' : htmlspecialchars($piece, ENT_QUOTES);
                if ($i < $last) {
                    $lines[] = $current;
                    $current = '';
                }
            }
        }

        $lines[] = $current;

        return $lines;
    }

    /**
     * Template syntax inside inline HTML: @directives and {{ }} / {!! !!} echoes.
     *
     * The lexer sees a template as one HTML token, so its own syntax would be
     * grey. Applied to text that is already escaped, on a single line.
     *
     * @param string $html
     * @return string
     */
    private static function template(string $html): string
    {
        $html = preg_replace('/\{\{(?:--.*?--|\/\*.*?\*\/)\}\}/', '<span class="t-c">$0</span>', $html);
        $html = preg_replace('/\{\{(?!--)(.*?)\}\}|\{!!(.*?)!!\}/', '<span class="t-e">$0</span>', $html);
        $html = preg_replace('/(?<![\w@])@(?:end)?[a-z]+\b/', '<span class="t-d">$0</span>', $html);

        return $html;
    }

    /**
     * The class a token renders under, or null for plain text.
     *
     * @param int    $id
     * @param string $text
     * @return string|null
     */
    private static function classOf(int $id, string $text): ?string
    {
        static $keywords = null;

        # Every T_* the engine treats as a reserved word. Built once from the
        # tokenizer's own list rather than typed out, so a keyword the list forgets
        # cannot exist.
        if ($keywords === null) {
            $keywords = [];
            foreach (get_defined_constants(true)['tokenizer'] ?? [] as $name => $value) {
                if (!is_int($value)) continue;
                if (in_array($name, ['T_STRING', 'T_VARIABLE', 'T_CONSTANT_ENCAPSED_STRING', 'T_ENCAPSED_AND_WHITESPACE', 'T_LNUMBER', 'T_DNUMBER', 'T_COMMENT', 'T_DOC_COMMENT', 'T_INLINE_HTML', 'T_WHITESPACE', 'T_OPEN_TAG', 'T_OPEN_TAG_WITH_ECHO', 'T_CLOSE_TAG', 'T_START_HEREDOC', 'T_END_HEREDOC', 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE', 'T_NS_SEPARATOR', 'T_CURLY_OPEN', 'T_DOLLAR_OPEN_CURLY_BRACES', 'T_STRING_VARNAME', 'T_NUM_STRING', 'T_BAD_CHARACTER', 'T_ATTRIBUTE', 'T_OBJECT_OPERATOR', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_DOUBLE_COLON', 'T_DOUBLE_ARROW', 'T_PAAMAYIM_NEKUDOTAYIM'], true)) continue;
                # Operators are T_* too (T_IS_EQUAL, T_INC ...); a keyword is letters.
                if (!ctype_alpha(str_replace('_', '', substr($name, 2)))) continue;
                $keywords[$value] = true;
            }
        }

        return match (true) {
            $id === T_INLINE_HTML                                          => 'html',
            $id === T_OPEN_TAG, $id === T_OPEN_TAG_WITH_ECHO, $id === T_CLOSE_TAG => 'tag',
            $id === T_COMMENT, $id === T_DOC_COMMENT                       => 'c',
            $id === T_VARIABLE, $id === T_STRING_VARNAME                   => 'v',
            $id === T_CONSTANT_ENCAPSED_STRING, $id === T_ENCAPSED_AND_WHITESPACE,
            $id === T_START_HEREDOC, $id === T_END_HEREDOC, $id === T_NUM_STRING => 's',
            $id === T_LNUMBER, $id === T_DNUMBER                           => 'n',
            $id === T_STRING, $id === T_NAME_QUALIFIED, $id === T_NAME_FULLY_QUALIFIED, $id === T_NAME_RELATIVE
                => in_array(strtolower($text), ['true', 'false', 'null'], true) ? 'k' : 'i',
            $id === T_WHITESPACE                                           => null,
            isset($keywords[$id])                                          => 'k',
            default                                                        => 'p',
        };
    }
}
