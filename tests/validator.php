<?php

/**
 * php terminal tests run validator
 *
 * The rule set without a database: empty values, arrays, types, confirmed.
 * A failed validation throws a ResponseSignal (redirect/JSON), so "fails"
 * here means catching that signal.
 */

use zFramework\Core\ResponseSignal;
use zFramework\Core\Validator;

$passes = function (array $data, array $rules): bool {
    try {
        Validator::validate($data, $rules);
        return true;
    } catch (ResponseSignal) {
        return false;
    }
};

test('empty values pass everything but required', function () use ($passes) {
    truthy($passes(['n' => ''], ['n' => ['nullable', 'email']]));
    falsy($passes(['tags' => []], ['tags' => ['required']]));
});

test('array values validate instead of crashing', function () use ($passes) {
    truthy($passes(['tags' => ['a', 'b']], ['tags' => ['nullable', 'type:array']]));
    truthy($passes(['tags' => ['a', 'b']], ['tags' => ['required', 'max:5']]), 'two elements fit in max:5');
    falsy($passes(['tags' => ['a', 'b', 'c']], ['tags' => ['max:2']]), 'three elements exceed max:2');
    falsy($passes(['e' => ['x']], ['e' => ['email']]), 'an array is not an email');
    truthy($passes(['s' => ['x']], ['s' => ['between:1,5']]), 'one element sits between 1 and 5');
});

test('confirmed compares arrays strictly', function () use ($passes) {
    truthy($passes(['p' => ['x'], 'p_confirmation' => ['x']], ['p' => ['confirmed']]));
    falsy($passes(['p' => ['x'], 'p_confirmation' => ['y']], ['p' => ['confirmed']]));
});

test('types and bounds still hold for scalars', function () use ($passes) {
    truthy($passes(['n' => '42'], ['n' => ['type:integer', 'max:50']]));
    falsy($passes(['n' => '99'], ['n' => ['type:integer', 'max:50']]));
    truthy($passes(['s' => 'abc'], ['s' => ['regex:"^a.c$"']]));
});

test('an all-digit value is a number unless type:string says otherwise', function () use ($passes) {
    truthy($passes(['p' => '12345'], ['p' => ['required', 'min:8']]), 'untyped: 12345 >= 8 - the documented behaviour');
    falsy($passes(['p' => '12345'], ['p' => ['required', 'type:string', 'min:8']]), 'type:string measures five characters');
    truthy($passes(['t' => '2024'], ['t' => ['type:string', 'max:30']]));
});

test('min/max count characters, not bytes', function () use ($passes) {
    truthy($passes(['t' => 'şçğüöı'], ['t' => ['type:string', 'max:6']]), 'six Turkish letters are six characters, not twelve bytes');
    falsy($passes(['t' => 'şçğü'], ['t' => ['type:string', 'min:5']]), 'four letters are not five');
    truthy($passes(['t' => 'ğğğ'], ['t' => ['type:string', 'between:3,3']]));
});

test('length counts characters whatever the value looks like', function () use ($passes) {
    truthy($passes(['tc' => '12345678901'], ['tc' => ['required', 'length:11']]), 'an all-digit value is still eleven characters');
    falsy($passes(['tc' => '1234567890'], ['tc' => ['length:11']]));
    falsy($passes(['p' => '12345'], ['p' => ['length:8,72']]), 'the min:8 trap does not exist here');
    truthy($passes(['p' => 'şifreğüç'], ['p' => ['length:8,72']]), 'eight Turkish letters');
    falsy($passes(['p' => str_repeat('a', 73)], ['p' => ['length:8,72']]));
    truthy($passes(['p' => ''], ['p' => ['nullable', 'length:8,72']]), 'blank passes; required objects to it');
    truthy($passes(['tags' => ['a', 'b']], ['tags' => ['length:1,3']]), 'an array counts its elements');
});

test('a length failure says what was expected', function () {
    Validator::validate(['tc' => '123'], ['tc' => ['length:11']], [], function ($errors) use (&$got) { $got = $errors; });
    contains('11', $got['tc']['length'] ?? '', 'no message, or no expected length in it');
    contains('3', $got['tc']['length'] ?? '');
});

test('an unknown rule is a loud error, not a silent pass', function () {
    throws(\Exception::class, fn() => Validator::validate(['x' => '1'], ['x' => ['no-such-rule']]));
});
