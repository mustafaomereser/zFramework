<?php

namespace zFramework\Core\Validator;

abstract class Rule
{
    public array $errors = [];

    abstract public function handle(array $data): bool;
}
