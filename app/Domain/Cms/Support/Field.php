<?php

namespace App\Domain\Cms\Support;

use Illuminate\Validation\Rule;

/** One field of a typed JSON payload (home sections, settings). Produces validation rules and the `/meta` description. */
final class Field
{
    /** @param list<string>|null $options */
    public function __construct(
        public readonly string $name,
        public readonly string $kind, // string|text|markdown|media|link|int|bool|enum|time|date|email|float
        public readonly bool $required = false,
        public readonly ?int $max = null,
        public readonly ?array $options = null,
        public readonly mixed $default = null,
    ) {}

    /** @return list<mixed> */
    public function rules(): array
    {
        $r = [$this->required ? 'required' : 'nullable'];
        $r = array_merge($r, match ($this->kind) {
            'string', 'text', 'markdown' => ['string', 'max:'.($this->max ?? 500)],
            'media' => [new MediaExists],
            'link' => [new SafeLink],
            'int' => ['integer', ...($this->options !== null ? ['min:'.$this->options[0], 'max:'.$this->options[1]] : [])],
            'bool' => ['boolean'],
            'enum' => [Rule::in($this->options ?? [])],
            'time' => ['date_format:H:i'],
            'date' => ['date_format:Y-m-d'],
            'email' => ['email:rfc', 'max:190'],
            'float' => ['numeric'],
            'url' => ['url:http,https', 'max:500'],
            default => [],
        });

        return $r;
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return ['name' => $this->name, 'kind' => $this->kind, 'required' => $this->required, 'max' => $this->max, 'options' => $this->kind === 'int' ? null : $this->options];
    }
}
