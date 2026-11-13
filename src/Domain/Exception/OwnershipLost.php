<?php
declare(strict_types=1);

namespace Latra\Domain\Exception;

/** Retain unresolved intent; another connection may now own dispatch. */
final class OwnershipLost extends \RuntimeException {}
