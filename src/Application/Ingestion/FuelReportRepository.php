<?php
declare(strict_types=1);

namespace Latra\Application\Ingestion;

interface FuelReportRepository { public function vehicles(): array; }
