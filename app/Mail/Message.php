<?php
declare(strict_types=1);

namespace App\Mail;

/**
 * One outbound plain-text message. Callers are expected to have already
 * sanitised the address/subject/from fields (CRLF stripped, recipient
 * validated) before constructing a Message — transports use them as-is.
 */
final class Message
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $from,
        public readonly string $fromName = '',
    ) {
    }

    /** "Name <addr>" when a display name is set, else the bare address. */
    public function fromHeader(): string
    {
        return $this->fromName !== '' ? '"' . $this->fromName . '" <' . $this->from . '>' : $this->from;
    }
}
