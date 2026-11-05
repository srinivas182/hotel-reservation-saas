<?php

declare(strict_types=1);

namespace App\Domains\GoLive;

/**
 * One line of the go-live readiness page.
 */
final readonly class ReadinessCheck
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $required,
        public bool $ok,
        public ?string $detail = null,
        public ?string $fixRoute = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'required' => $this->required,
            'ok' => $this->ok,
            'detail' => $this->detail,
            'fix_url' => $this->fixRoute === null ? null : route($this->fixRoute),
        ];
    }
}
