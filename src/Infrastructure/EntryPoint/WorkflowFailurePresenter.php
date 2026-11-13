<?php
declare(strict_types=1);

namespace Latra\Infrastructure\EntryPoint;

use Latra\Domain\Exception\{InvalidSubmission,OwnershipLost,CapacityExceeded,SourceUnavailable,NotEnrolled,RequiresReview};
use Throwable;

final class WorkflowFailurePresenter
{
    public static function present(Throwable $error): array
    {
        [$status,$code,$retryable]=match(true) {
            $error instanceof InvalidSubmission => [400,'invalid_submission',false],
            $error instanceof OwnershipLost => [409,'ownership_lost',true],
            $error instanceof CapacityExceeded => [409,'capacity_exceeded',false],
            $error instanceof SourceUnavailable => [503,'source_unavailable',true],
            $error instanceof NotEnrolled => [409,'not_enrolled',false],
            $error instanceof RequiresReview => [409,'requires_review',false],
            default => [500,'internal_error',false],
        };
        return ['status'=>$status,'body'=>['success'=>false,'error'=>$error->getMessage(),'code'=>$code,'retryable'=>$retryable]];
    }
}
