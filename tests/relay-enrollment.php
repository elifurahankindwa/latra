<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../includes/autoload.php';

function enrollmentFails(callable $action, string $message): void
{
    try {
        $action();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
}

$repository = new class implements \Latra\Application\Collection\RelayEnrollmentRepository {
    public ?array $saved = null;

    public function saveEnrollment(int $vehicleId, int $unitId, string $profile, array $budgets): void
    {
        $this->saved = [$vehicleId, $unitId, $profile, $budgets];
    }
};
$useCase = new \Latra\Application\Collection\EnrollRelayVehicle($repository);
$budgets = [
    'max_records' => 100,
    'max_bytes' => 1048576,
    'max_pending_records' => 200,
    'max_pending_bytes' => 2097152,
];
$useCase->enroll(7, 123, 'teltonika-fmb', $budgets);
if ($repository->saved !== [7, 123, 'teltonika-fmb', $budgets]) {
    throw new RuntimeException('Valid enrollment was not passed to persistence unchanged');
}

enrollmentFails(
    static fn() => $useCase->enroll(7, 123, 'unknown', $budgets),
    'Unsupported profile was accepted',
);
$invalidBudget = $budgets;
$invalidBudget['max_bytes'] = 0;
enrollmentFails(
    static fn() => $useCase->enroll(7, 123, 'generic', $invalidBudget),
    'Nonpositive budget was accepted',
);
$invalidBudget = $budgets;
$invalidBudget['max_records'] = '100';
enrollmentFails(
    static fn() => $useCase->enroll(7, 123, 'generic', $invalidBudget),
    'Non-integer budget was accepted',
);
enrollmentFails(
    static fn() => $useCase->enroll(0, 123, 'generic', $budgets),
    'Invalid vehicle identity was accepted',
);

$blocked = false;
try {
    \Latra\Domain\Collection\RelayEnrollmentPolicy::assertProfileChangeAllowed(true);
} catch (RuntimeException $error) {
    $blocked = str_contains($error->getMessage(), 'explicit state migration');
}
if (!$blocked) {
    throw new RuntimeException('Profile change with collected state was not blocked');
}
\Latra\Domain\Collection\RelayEnrollmentPolicy::assertProfileChangeAllowed(false);

echo "PASS relay enrollment policy validates identities, profiles, budgets and stateful profile changes\n";
