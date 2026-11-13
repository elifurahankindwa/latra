<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
use Latra\Application\Ingestion\PollScheduledVehicles;
use Latra\Application\Ingestion\ScheduledVehicleRepository;
$repo = new class implements ScheduledVehicleRepository {
    public function ordinaryVehicles(): array { return [['id'=>1],['id'=>2],['id'=>3]]; }
    public function emergencyVehicles(): array { return [['id'=>9],['id'=>10]]; }
};
$calls=[];$warnings=[];$flushes=[];
$job = new PollScheduledVehicles($repo,
    static function(array $v,int $window,int $limit) use (&$calls):array {
        $calls[]=[$v['id'],$window,$limit];
        if ($v['id']===2 || $v['id']===10) throw new RuntimeException('fixture');
        return ['inserted'=>2,'rejected'=>1];
    },
    static function(string $event,array $ctx) use (&$warnings):void { $warnings[]=$event; },
    static function(bool $emergency) use (&$flushes):int { $flushes[]=$emergency; return 4; });
if ($job->ordinary(180)!==6 || $calls!==[[1,180,80],[2,180,80],[3,180,80]]
    || $warnings!==['poll.vehicle_failed']) throw new RuntimeException('Ordinary polling contracts changed');
if ($job->emergency()!==6 || array_slice($calls,3)!==[[9,5,20],[10,5,20]]
    || $flushes!==[true] || end($warnings)!=='emergency.poll_failed') throw new RuntimeException('Emergency polling contracts changed');
echo "PASS scheduled polling bounds, accounting, independent failures and emergency flush\n";
