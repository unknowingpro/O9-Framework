<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Hashid;
use PHPUnit\Framework\TestCase;

final class HashidTest extends TestCase
{
    public function testEncodeDecodeRoundTrip(): void
    {
        foreach ([0, 1, 42, 999_999, 1_000_000, 123_456_789] as $id) {
            $token = Hashid::encode($id);
            $this->assertNotSame('', $token, "id $id");
            $this->assertSame($id, Hashid::decode($token), "id $id");
        }
    }

    public function testEncodingIsDeterministicAndOpaque(): void
    {
        $this->assertSame(Hashid::encode(5), Hashid::encode(5));
        $this->assertNotSame(Hashid::encode(5), Hashid::encode(6));
        // The OFFSET guarantees multi-char tokens even for tiny ids.
        $this->assertGreaterThan(1, strlen(Hashid::encode(1)));
        // No ambiguous characters in the output alphabet.
        $this->assertDoesNotMatchRegularExpression('/[0O1Il]/', Hashid::encode(1_234_567));
    }

    public function testDecodeRejectsMalformedTokens(): void
    {
        $this->assertNull(Hashid::decode(''));
        $this->assertNull(Hashid::decode('   '));
        $this->assertNull(Hashid::decode('has-invalid-chars!'));
        $this->assertNull(Hashid::decode('0O1Il'));      // chars outside the alphabet
        $this->assertNull(Hashid::decode('2'));           // decodes below the OFFSET
    }

    public function testNegativeIdEncodesToEmptyString(): void
    {
        $this->assertSame('', Hashid::encode(-1));
    }
}
