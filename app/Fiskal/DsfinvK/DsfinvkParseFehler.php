<?php

namespace App\Fiskal\DsfinvK;

use RuntimeException;

/** Der QR-Inhalt lässt sich nicht als DSFinV-K-Code zerlegen. */
final class DsfinvkParseFehler extends RuntimeException {}
