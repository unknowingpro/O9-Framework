<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Csv;
use PHPUnit\Framework\TestCase;

final class CsvTest extends TestCase
{
    /**
     * The prefix must be present immediately before the trigger character
     * regardless of whether the value ALSO needed CSV quoting for an
     * unrelated reason (a leading \r or \n is both a formula trigger and,
     * on its own, something the CSV quoting rules require wrapping in
     * quotes) — so this checks containment, not just how the row starts.
     *
     * @dataProvider formulaTriggers
     */
    public function testFormulaTriggerCharactersAreNeutralized(string $trigger): void
    {
        $row = Csv::row([$trigger . 'SUM(A1:A10)']);
        $this->assertStringContainsString("'" . $trigger . 'SUM', $row);
    }

    /** @return list<list<string>> */
    public static function formulaTriggers(): array
    {
        return [['='], ['+'], ['-'], ['@'], ["\t"], ["\r"]];
    }

    public function testOrdinaryValuesAreUnmodified(): void
    {
        $this->assertSame("hello,world\r\n", Csv::row(['hello', 'world']));
        // A minus sign NOT in the leading position is not a formula trigger.
        $this->assertSame("2020-01-01\r\n", Csv::row(['2020-01-01']));
    }

    public function testValuesContainingCommasOrQuotesAreProperlyQuoted(): void
    {
        $this->assertSame("\"a,b\"\r\n", Csv::row(['a,b']));
        $this->assertSame("\"say \"\"hi\"\"\"\r\n", Csv::row(['say "hi"']));
    }

    public function testFormulaNeutralizationHappensBeforeQuoting(): void
    {
        // Starts with '=' AND contains a comma — must be both prefixed and quoted.
        $row = Csv::row(['=A1,B1']);
        $this->assertSame("\"'=A1,B1\"\r\n", $row);
    }

    public function testStreamWritesEveryRowToOutput(): void
    {
        ob_start();
        Csv::stream([['a', 'b'], ['1', '2']]);
        $output = ob_get_clean();
        $this->assertSame("a,b\r\n1,2\r\n", $output);
    }
}
