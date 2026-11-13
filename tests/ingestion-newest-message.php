<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../includes/autoload.php';

use Latra\Domain\Ingestion\NewestSourceMessagePolicy;

if (NewestSourceMessagePolicy::select([]) !== null) {
    throw new RuntimeException('Empty batch selected a source message');
}

$older = ['t' => 1_800_000_100, 'label' => 'older'];
$newer = ['t' => 1_800_000_200, 'label' => 'newer'];
foreach ([[$older, $newer], [$newer, $older]] as $order) {
    $selected = NewestSourceMessagePolicy::select($order);
    if (($selected['label'] ?? null) !== 'newer') {
        throw new RuntimeException('Selection depended on array order instead of source time');
    }
}

// Equal source times keep the later element, matching the previous coordinator behavior.
$tie = NewestSourceMessagePolicy::select([
    ['t' => 1_800_000_300, 'label' => 'first'],
    ['t' => 1_800_000_300, 'label' => 'second'],
]);
if (($tie['label'] ?? null) !== 'second') {
    throw new RuntimeException('Equal source times did not keep the later element');
}

// A missing source time reads as epoch zero and must not outrank a real timestamp.
$zero = NewestSourceMessagePolicy::select([
    ['label' => 'missing'],
    ['t' => 0, 'label' => 'zero'],
]);
if (($zero['label'] ?? null) !== 'zero') {
    throw new RuntimeException('Missing source time was not treated as epoch zero');
}
if ((NewestSourceMessagePolicy::select([['label' => 'missing']])['label'] ?? null) !== 'missing') {
    throw new RuntimeException('Single message without a source time was dropped');
}

echo "PASS newest source message selection by time, tie order and missing timestamps\n";
