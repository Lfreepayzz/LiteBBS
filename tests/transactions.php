<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit;
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "Transaction tests skipped (pdo_sqlite unavailable).\n";
    exit;
}

require_once dirname(__DIR__).'/app/core.php';

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE operations (id INTEGER PRIMARY KEY)');
$transactions = new \LiteBBS\Application\TransactionManager($db);
$transactions->run(static fn(PDO $connection) => $connection->exec('INSERT INTO operations VALUES (1)'));

$halt = new \LiteBBS\Framework\ResponseHalt(new \LiteBBS\Framework\Response('', 409));
try {
    $transactions->run(static function (PDO $connection) use ($halt): void {
        $connection->exec('INSERT INTO operations VALUES (2)');
        throw $halt;
    });
    throw new RuntimeException('Transaction swallowed the failure.');
} catch (\LiteBBS\Framework\ResponseHalt $error) {
    if ($error !== $halt) throw $error;
}

$db->beginTransaction();
$transactions->run(static fn(PDO $connection) => $connection->exec('INSERT INTO operations VALUES (3)'));
if (!$db->inTransaction()) throw new RuntimeException('Nested call committed the caller transaction.');
$db->rollBack();

$ids = $db->query('SELECT id FROM operations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
if (array_map('intval', $ids) !== [1]) throw new RuntimeException('Unexpected committed operations.');

echo "Transaction tests passed.\n";
