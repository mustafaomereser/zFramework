<?php

namespace zFramework\Core\Validator;

abstract class Rule
{
    public array $errors = [];

    abstract public function handle(array $data): bool;

    /**
     * Nothing was sent: null, an empty string, an empty array. The rules that
     * only apply to a value (max, email, in ...) pass on this - `required` is
     * the rule that objects to it.
     *
     * Not strlen(): a `name[]` field arrives as an array, and strlen() on an
     * array is a TypeError that `@` does not silence - every form with a
     * multi-select answered 500 to anyone who sent one.
     *
     * @param mixed $value
     * @return bool
     */
    protected function blank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * A value the rule can read as text. An array is not one, and a rule that
     * needs text fails it rather than casting it to "Array".
     *
     * @param mixed $value
     * @return bool
     */
    protected function text(mixed $value): bool
    {
        return is_scalar($value) || $value === null;
    }
}
