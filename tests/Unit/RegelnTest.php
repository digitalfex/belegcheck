<?php

namespace Tests\Unit;

use App\Pruefung\Regeln;
use PHPUnit\Framework\TestCase;

class RegelnTest extends TestCase
{
    /** Jede im Code verwendete Regel braucht eine Kurzbeschreibung. */
    public function test_jede_regel_hat_einen_titel(): void
    {
        $codes = [];
        $dateien = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../app'));
        foreach ($dateien as $datei) {
            if ($datei->getExtension() === 'php' && $datei->getFilename() !== 'Regeln.php') {
                preg_match_all("/'((?:AT|DE)-[A-Z]{2}-\d{2}|BF-\d{2}|PL-[A-Z]{2}-\d{2}|MU-[A-Z]{2}-\d{2}|BW-\d{2}|LAND-\d{2})'/", file_get_contents($datei), $m);
                $codes = [...$codes, ...$m[1]];
            }
        }

        $this->assertNotEmpty($codes);
        foreach (array_unique($codes) as $code) {
            $this->assertArrayHasKey($code, Regeln::TITEL, "Regel $code hat keine Kurzbeschreibung");
        }
    }
}
