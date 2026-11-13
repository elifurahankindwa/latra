<?php
declare(strict_types=1);

namespace Latra\Domain\Exception;

/** Invalid identity or payload supplied by an entry-point caller. */
final class InvalidSubmission extends \InvalidArgumentException {}
