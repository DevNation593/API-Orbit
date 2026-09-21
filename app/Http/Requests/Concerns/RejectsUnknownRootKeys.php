<?php

namespace App\Http\Requests\Concerns;

trait RejectsUnknownRootKeys
{
    /**
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $allowedKeys
     * @return array<string, mixed>
     */
    protected function withStrictRootKeys(array $rules, array $allowedKeys): array
    {
        foreach (array_diff(array_keys($this->all()), $allowedKeys) as $key) {
            if (! array_key_exists($key, $rules)) {
                $rules[$key] = ['missing'];
            }
        }

        return $rules;
    }
}
