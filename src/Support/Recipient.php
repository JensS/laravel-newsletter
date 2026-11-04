<?php

namespace JensS\Newsletter\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A single resolved recipient. Providers create these; the package never needs
 * to know what your member model looks like.
 */
final class Recipient
{
    public readonly string $email;

    /**
     * @param  string  $key  Stable identifier, unique within the audience (e.g. "sub:12" or "user:42").
     * @param  array<string, scalar|null>  $attributes  Extra personalization values.
     */
    public function __construct(
        public readonly string $key,
        string $email,
        public readonly ?string $name = null,
        public readonly ?string $locale = null,
        public readonly array $attributes = [],
        public readonly ?Model $subscribable = null,
        public readonly ?int $subscriptionId = null,
        private readonly ?string $firstNameOverride = null,
    ) {
        $this->email = Email::normalize($email);
    }

    public function firstName(): ?string
    {
        if ($this->firstNameOverride !== null) {
            return $this->firstNameOverride;
        }

        if ($this->name === null || trim($this->name) === '') {
            return null;
        }

        return strtok(trim($this->name), ' ') ?: null;
    }

    /** @return array<string, scalar|null> */
    public function personalization(): array
    {
        return array_merge($this->attributes, [
            'email' => $this->email,
            'name' => $this->name,
            'first_name' => $this->firstName(),
        ]);
    }
}
