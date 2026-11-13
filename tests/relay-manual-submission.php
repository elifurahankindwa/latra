<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
use Latra\Application\Collection\QueueManualRelayPayload;
use Latra\Application\Collection\RelayWindowCollector;
$collector = new class implements RelayWindowCollector {
    public array $calls = [];
    public function collectWindow(string $unit, int $from, int $to, int $pageSize, int $maxRecords, int $maxBytes, callable $fetchPage, callable $map): array {
        $page = $fetchPage(0, 1);
        $mapped = $map($page['messages'][0], []);
        $this->calls[] = [$unit, $from, $to, $pageSize, $maxRecords, $maxBytes, $mapped];
        return ['queued'=>1];
    }
};
$queue = new QueueManualRelayPayload($collector, 8192);
$vehicle = ['id'=>7, 'wialon_unit_id'=>123, 'imei'=>'123456789012345','vehicle_reg_no'=>'SYNTHETIC'];
$payload = ['imei'=>$vehicle['imei'],'vehicle_reg_no'=>'SYNTHETIC','items'=>[['activity_id'=>'1',
    'timestamp'=>(string)((time()-1)*1000),'latitude'=>-6.8,'longitude'=>39.2,'altitude'=>50,'speed'=>20,
    'bearing'=>90,'satellite_count'=>12]]];
$result = $queue->queue($vehicle, $payload);
if ($result['success'] !== true || $result['queued'] !== 1 || count($collector->calls) !== 1
    || !str_starts_with($collector->calls[0][0], 'manual:wialon:123:')
    || $collector->calls[0][6]['payload'] !== $payload) throw new RuntimeException('Manual submission contract changed');
foreach ([$payload+[], array_replace($payload,['imei'=>'wrong']), array_replace($payload,['items'=>[]])] as $i=>$candidate) {
    if ($i === 0) { $queue->queue($vehicle, $candidate); continue; }
    try { $queue->queue($vehicle,$candidate); throw new LogicException('Invalid manual payload queued'); }
    catch (Latra\Domain\Exception\InvalidSubmission) {}
}
if ($collector->calls[0][0] !== $collector->calls[1][0]) throw new RuntimeException('Manual replay identity changed');
echo "PASS manual submission identity, immutable source mapping and invalid-payload rejection\n";
