<?php

namespace App\Support;

/**
 * Advanced filter/search syntax, mirroring the prototype's buildMatcher
 * (spec §9.11):
 *   - plain tokens: AND across tokens, substring match; a token containing
 *     regex metacharacters is tried as a regex over the row's `_all` haystack.
 *   - field:value filters (e.g. status:paid, by:ali), substring on that field.
 *   - a full /regex/flags literal (e.g. /R26/) over `_all`.
 */
final class Matcher
{
    private ?array $regex = null;      // [pattern, flags] for a /.../ literal

    /** @var list<array{field:?string,value:string}> */
    private array $tokens = [];

    public function __construct(string $query)
    {
        $query = trim($query);
        if ($query === '') {
            return;
        }

        if (preg_match('#^/(.*)/([a-z]*)$#i', $query, $m)) {
            $this->regex = [$m[1], $m[2]];

            return;
        }

        foreach (preg_split('/\s+/', $query) as $tok) {
            if ($tok === '') {
                continue;
            }
            if (str_contains($tok, ':')) {
                [$field, $value] = explode(':', $tok, 2);
                $this->tokens[] = ['field' => strtolower($field), 'value' => strtolower($value)];
            } else {
                $this->tokens[] = ['field' => null, 'value' => $tok];
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->regex === null && $this->tokens === [];
    }

    /**
     * @param  array<string,string>  $fields  row fields incl. an `_all` haystack
     */
    public function matches(array $fields): bool
    {
        $all = strtolower($fields['_all'] ?? implode(' ', $fields));

        if ($this->regex !== null) {
            return $this->pregTest($this->regex[0], $all, $this->regex[1]);
        }

        foreach ($this->tokens as $t) {
            if ($t['field'] !== null) {
                $hay = strtolower((string) ($fields[$t['field']] ?? ''));
                if (! str_contains($hay, $t['value'])) {
                    return false;
                }

                continue;
            }

            // Plain token: substring, or regex when it looks like one.
            $val = $t['value'];
            if (preg_match('/[.*+?^${}()|\[\]\\\\]/', $val) && $this->pregTest($val, $all)) {
                continue;
            }
            if (! str_contains($all, strtolower($val))) {
                return false;
            }
        }

        return true;
    }

    private function pregTest(string $pattern, string $subject, string $flags = 'i'): bool
    {
        $flags = preg_replace('/[^imsxu]/', '', $flags) ?: 'i';
        $delimited = '#'.str_replace('#', '\#', $pattern).'#'.$flags;

        return @preg_match($delimited, $subject) === 1;
    }
}
