<?php

if (! function_exists('ray')) {
    function ray(mixed ...$args): object
    {
        return new class {
            public function __call(string $method, array $args): static { return $this; }
        };
    }
}
