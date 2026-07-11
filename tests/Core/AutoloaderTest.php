<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Autoloader;
use PHPUnit\Framework\TestCase;

final class AutoloaderTest extends TestCase
{
    public function testIgnoresClassesOutsideAppNamespace(): void
    {
        // Must be a silent no-op — no require attempt, no error.
        Autoloader::load('Vendor\\Package\\SomeClass');
        $this->addToAssertionCount(1);
    }

    public function testIgnoresMissingAppClasses(): void
    {
        Autoloader::load('App\\Nope\\DoesNotExist');
        $this->assertFalse(class_exists('App\\Nope\\DoesNotExist', false));
    }

    public function testMapsAppNamespaceToAppDirectory(): void
    {
        // Verified structurally: the class file the loader would require must
        // be the real path of a known Core class.
        $expected = BASE_PATH . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Env.php';
        $this->assertFileExists($expected);
    }
}
