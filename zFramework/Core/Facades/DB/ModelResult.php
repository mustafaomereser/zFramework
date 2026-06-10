<?php

namespace zFramework\Core\Facades\DB;

/**
 * Wraps a DB row so columns and closures are accessible
 * both as array keys ($row['id']) and object properties/methods ($row->id, $row->posts()).
 */
class ModelResult implements \ArrayAccess, \JsonSerializable
{
    private array $attributes;

    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    // --- ArrayAccess ---

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) $this->attributes[] = $value;
        else $this->attributes[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
    }

    // --- Object property access: $row->id ---

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->attributes[$name]);
    }

    // --- Closure/relation calls: $row->posts() ---

    public function __call(string $name, array $args): mixed
    {
        if (isset($this->attributes[$name]) && is_callable($this->attributes[$name]))
            return ($this->attributes[$name])(...$args);

        throw new \BadMethodCallException("Closure '$name' is not defined on this result.");
    }

    // --- JsonSerializable: excludes closures so json_encode() works ---

    public function jsonSerialize(): mixed
    {
        return array_filter($this->attributes, fn($v) => !($v instanceof \Closure));
    }

    /**
     * Convert to a plain array (closures excluded).
     * @return array
     */
    public function toArray(): array
    {
        return array_filter($this->attributes, fn($v) => !($v instanceof \Closure));
    }
}
