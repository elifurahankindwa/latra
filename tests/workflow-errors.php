<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
use Latra\Infrastructure\EntryPoint\WorkflowFailurePresenter;
foreach ([
    [new Latra\Domain\Exception\InvalidSubmission('bad payload'),400,'invalid_submission',false],
    [new Latra\Domain\Exception\OwnershipLost('lost'),409,'ownership_lost',true],
    [new Latra\Domain\Exception\CapacityExceeded('full'),409,'capacity_exceeded',false],
    [new Latra\Domain\Exception\SourceUnavailable('partial page',1001),503,'source_unavailable',true],
    [new Latra\Domain\Exception\NotEnrolled('enroll first'),409,'not_enrolled',false],
] as [$error,$status,$code,$retryable]) {
    $result=WorkflowFailurePresenter::present($error);
    if ($result!==['status'=>$status,'body'=>['success'=>false,'error'=>$error->getMessage(),'code'=>$code,'retryable'=>$retryable]]) {
        throw new RuntimeException('Workflow error mapping changed');
    }
}
echo "PASS typed workflow failures map to stable entry-point outcomes\n";
