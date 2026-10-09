<?php

namespace App\Fiskal\Rksv;

use RuntimeException;

/** Der QR-Inhalt lässt sich nicht als RKSV-Code zerlegen. */
final class RksvParseFehler extends RuntimeException {}
