<?php
// Dependency-free compatibility test: php tests/known-notes-contract.php
require __DIR__ . '/../app/Support/KnownNotes.php';

use App\Support\KnownNotes;

$notes = [
    ['id' => 'unchanged', 'last_modified' => 100, 'deleted' => false],
    ['id' => 'updated', 'last_modified' => 200, 'deleted' => false],
    ['id' => 'new', 'last_modified' => 50, 'deleted' => false],
    ['id' => 'deleted', 'last_modified' => 100, 'deleted' => true],
];
$cases = [
    'legacy request' => [null, ['unchanged', 'updated', 'new', 'deleted']],
    'empty manifest' => [[], ['unchanged', 'updated', 'new', 'deleted']],
    'invalid manifest' => ['invalid', ['unchanged', 'updated', 'new', 'deleted']],
    'delta request' => [['unchanged' => 100, 'updated' => 100, 'deleted' => 100], ['updated', 'new', 'deleted']],
    'invalid versions' => [['unchanged' => null, 'updated' => '200'], ['unchanged', 'updated', 'new', 'deleted']],
    'local newer still receives server' => [['unchanged' => 200], ['unchanged', 'updated', 'new', 'deleted']],
];
foreach ($cases as $name => [$known, $expected]) {
    $actual = array_column(array_values(array_filter($notes, fn($n) => KnownNotes::shouldDownload(
        $known, $n['id'], $n['last_modified'], $n['deleted']
    ))), 'id');
    if ($actual !== $expected) throw new RuntimeException($name);
    echo "PASS: $name\n";
}
if (!KnownNotes::shouldDownload(['zero' => 0], 'zero', 0, false)) throw new RuntimeException('unknown timestamp');
echo "PASS: unknown timestamp\n";
