<?php
/** Only a fresh, explicitly resettable synthetic relay database is accepted. */
if (PHP_SAPI !== 'cli') exit(1);
$dsn=getenv('LATRA_RELAY_TEST_DSN') ?: '';
if (getenv('LATRA_RELAY_TEST_ALLOW_RESET')!=='1'
    || !preg_match('/^mysql:.*;dbname=(latra_test_relay_app_[a-z0-9_]+)(?:;|$)/',$dsn,$match)) {
    throw new RuntimeException('Explicit disposable latra_test_relay_app_* database required');
}
require __DIR__ . '/../includes/autoload.php';
const DATE_FORMAT='Y-m-d H:i:s';
function nativeRepositoryDatabase(): PDO {
    return new PDO(getenv('LATRA_RELAY_TEST_DSN'),getenv('LATRA_RELAY_TEST_USER') ?: 'root',
        getenv('LATRA_RELAY_TEST_PASSWORD') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function nativeRepositoryExpect(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$db=nativeRepositoryDatabase();
nativeRepositoryExpect($db->query('SELECT DATABASE()')->fetchColumn()===$match[1],'Wrong database');
// Native repositories share the actual application table definitions and foreign keys.
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $db->exec('DROP TABLE `'.str_replace('`','``',$table).'`');
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');
$sql=file_get_contents(__DIR__.'/../database.sql');
$sql=preg_replace('/^(?:CREATE DATABASE[^;]*;|USE\s+latra_wialon;)\s*$/mi','',$sql);
$db->exec($sql);
(new ReflectionProperty(Database::class,'instance'))->setValue(null,$db);
(new ReflectionProperty(Database::class,'sensitiveConfigScrubbed'))->setValue(null,true);
Schema::ensure();
$db->exec("INSERT INTO vehicles (id,wialon_unit_id,vehicle_reg_no,imei) VALUES (7,123,'SYNTHETIC-NATIVE','123456789012345')");
$state=new Latra\Infrastructure\Persistence\PdoVehicleStateRepository($db);
$evidence=new Latra\Infrastructure\Persistence\PdoValidationEvidenceRepository($db);
$db->beginTransaction();
$state->save(7,['engine_status'=>'on','last_speed_kmh'=>45.5]);
$state->save(7,['engine_status'=>'off']);
nativeRepositoryExpect($state->find(7)['engine_status']==='off' && (float)$state->find(7)['last_speed_kmh']===45.5,
    'Native partial state update failed');
$count=$evidence->recordErrors(['vehicle_id'=>7,'vehicle_reg_no'=>'SYNTHETIC-NATIVE','activity_id'=>1,'wialon_msg_time'=>100],
    [['field'=>'lat','value'=>['bad'=>true],'reason'=>'synthetic','code'=>'bad_lat']]);
$row=$db->query('SELECT * FROM validation_errors')->fetch();
nativeRepositoryExpect($count===1 && $row['message_log_id']===null && $row['invalid_value']==='{"bad":true}'
    && (int)$row['gps_timestamp']===100,'Native evidence shape failed');
$db->rollBack();
nativeRepositoryExpect($state->find(7)===[] && (int)$db->query('SELECT COUNT(*) FROM validation_errors')->fetchColumn()===0,
    'Native state/evidence escaped rollback');
$state->save(7,['engine_status'=>'on']);
nativeRepositoryExpect($state->find(7)['engine_status']==='on','Native standalone state save failed');
echo "PASS native validation evidence, vehicle state, partial writes and transaction rollback\n";
$scheduled=new Latra\Infrastructure\Persistence\PdoScheduledVehicleRepository($db);
nativeRepositoryExpect(array_column($scheduled->ordinaryVehicles(),'id')===[7] && $scheduled->emergencyVehicles()===[],
    'Native scheduled vehicle selection failed');
$db->exec("INSERT INTO emergency_events (event_id,vehicle_id,started_at) VALUES ('synthetic-event',7,NOW())");
nativeRepositoryExpect($scheduled->ordinaryVehicles()===[] && array_column($scheduled->emergencyVehicles(),'id')===[7],
    'Native emergency polling selection failed');
$db->exec("UPDATE vehicles SET fuel_level_sensor='fuel' WHERE id=7");
$fuelRepository=new Latra\Infrastructure\Persistence\PdoFuelReportRepository($db);
nativeRepositoryExpect(array_column($fuelRepository->vehicles(),'id')===[7], 'Native fuel sensor selection failed');
echo "PASS native scheduled polling and fuel-report vehicle selection\n";

foreach (['009_durable_relay','010_relay_review_recovery','011_relay_review_schedule'] as $migration) {
    $db->exec(file_get_contents(__DIR__.'/../migrations/'.$migration.'.sql'));
    $db->exec(file_get_contents(__DIR__.'/../migrations/'.$migration.'.sql'));
}
$relay=new DurableRelay($db,8,static fn(int $n):int=>5,100,1048576);
$relay->collect('native-lock',100,100,1,1,4096,static fn():array=>['count'=>1,'messages'=>[['t'=>100]]],
    static fn():array=>['payload'=>['items'=>[['activity_id'=>1]]],'state'=>[]]);
$observer=nativeRepositoryDatabase();
$connection=(int)$db->query('SELECT CONNECTION_ID()')->fetchColumn();
$senderCalled=false;
$delivery=new Latra\Application\Delivery\DeliverPendingReport(new Latra\Infrastructure\Persistence\PdoRelayDeliveryRepository($db),
    8,static fn(int $n):int=>5,static function(string $stage) use ($observer,$connection):void {
        if ($stage==='attempt') $observer->exec('KILL '.$connection);
    });
try {
    $delivery->deliver('native-lock',1000,static function() use (&$senderCalled):array { $senderCalled=true; return []; });
    throw new LogicException('Dead lock connection was not detected');
} catch (Latra\Domain\Exception\OwnershipLost) {}
nativeRepositoryExpect(!$senderCalled && (int)$observer->query('SELECT COUNT(*) FROM relay_pending')->fetchColumn()===1,
    'Connection death dispatched or deleted pending intent');
$restart=new DurableRelay($observer,8,static fn(int $n):int=>5,100,1048576);
nativeRepositoryExpect($restart->deliver('native-lock',1001,static fn():array=>['code'=>200,'data'=>['status'=>'1']])==='sent',
    'New connection failed to recover durable intent');
echo "PASS killed native advisory-lock connection stops dispatch and a fresh owner recovers\n";

(new ReflectionProperty(Database::class,'instance'))->setValue(null,$observer);
$vehicle=$observer->query('SELECT * FROM vehicles WHERE id=7')->fetch();
RelayRuntime::enroll($vehicle,'generic',['max_records'=>20,'max_bytes'=>65536,'max_pending_records'=>20,'max_pending_bytes'=>65536]);
$payload=['vehicle_reg_no'=>$vehicle['vehicle_reg_no'],'imei'=>$vehicle['imei'],
    'items'=>[['activity_id'=>'1','timestamp'=>'100000','latitude'=>'-6.8','longitude'=>'39.2']]];
$first=RelayRuntime::queueForVehicle($payload,7);
$again=RelayRuntime::queueForVehicle($payload,7);
nativeRepositoryExpect($first['queued']===1 && $again['queued']===0
    && (int)$observer->query("SELECT COUNT(*) FROM relay_pending WHERE unit LIKE 'manual:wialon:123:%'")->fetchColumn()===1,
    'Native manual submission lost owner identity or replay deduplication');
echo "PASS native manual-submission composition, owner resolution and replay identity\n";
