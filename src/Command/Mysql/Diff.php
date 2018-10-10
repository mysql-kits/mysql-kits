<?php

namespace App\Command\Mysql;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Diff extends Command
{

    protected $sqls = [];


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $left = $this->getConfigFromFile($input->getArgument('left'));
            $right = $this->getConfigFromFile($input->getArgument('right'));
            $pdoLeft = new \PDO('mysql:host=' . $left['host'] . ';port=' . $left['port'] . ';dbname=' . $left['database'], $left['username'], $left['password'], array(
                \PDO::ATTR_TIMEOUT => 1
            ));
            $pdoRight = new \PDO('mysql:host=' . $right['host'] . ';port=' . $right['port'] . ';dbname=' . $right['database'], $right['username'], $right['password'], array(
                \PDO::ATTR_TIMEOUT => 1
            ));
            $table = trim($input->getOption('tables'));
            if (!$table) {
                foreach ($pdoLeft->query('show tables')->fetchAll() as $item) {
                    $tables[] = $item[0];
                }
            } elseif (stripos($table, '%')) {
                foreach ($pdoLeft->query('show tables like "' . $table . '"')->fetchAll() as $item) {
                    $tables[] = $item[0];
                }
            } else {
                $tables = explode(',', $table);
            }
            $tableOutput = new Table($output);
            $headers = [
                'Left',
                'Right',
                'Structure',
                'Index',
                'Rows'
            ];
            $bodies = [];
            foreach ($tables as $table) {
                $output->writeln("analyzing " . $table);
                $leftTable = $this->getTableInfo($table, $pdoLeft);
                if (!$leftTable) {
                    throw new RuntimeException('左表' . $table . '不存在!');
                }
                $rightTable = $this->getTableInfo($table, $pdoRight);
                $bodies[] = [
                    'Left' => $table,
                    'Right' => false === $rightTable ? 'missing' : $table,
                    'Structure' => $this->compareStructrue($leftTable, $rightTable),
                    'Index' => $this->compareIndex($leftTable, $rightTable),
                    'Rows' => $this->compareRows($leftTable, $rightTable)
                ];
            }
            $tableOutput->setHeaders($headers)->setRows($bodies)->render();
        } catch (\Exception $e) {
            $output->writeln($e->getMessage());
        }
    }

    function getTableInfo($table, \PDO $pdo)
    {
        try {
            $stmt1 = $pdo->query('show full columns from ' . $table . '');
            if (!$stmt1) {
                return false;
            }
            $stmt2 = $pdo->query('select count(*) from ' . $table);
            if (!$stmt2) {
                return false;
            }
            $stmt3 = $pdo->query('show create table ' . $table);
            if (!$stmt3) {
                dump($pdo->errorInfo());
                return false;
            }
            $stmt4 = $pdo->query('show index from ' . $table);
            if (!$stmt4) {
                dump($pdo->errorInfo());
                return false;
            }
            $info = [
                'name' => $table,
                'columns' => $stmt1->fetchAll(\PDO::FETCH_ASSOC),
                'rows' => $stmt2->fetchColumn(),
                'create' => $stmt3->fetchColumn(1),
                'index' => $stmt4->fetchAll(\PDO::FETCH_ASSOC),
                'drop' => 'DROP TABLE ' . $table
            ];
            return $info;
        } catch (\PDOException $e) {
            return false;
        }
    }

    function compareStructrue($leftTable, $rightTable)
    {
        $missing = $needless = $different = [];
        if (!$rightTable) {
            $this->sqls[] = $leftTable['create'];
            return '-';
        }
        $leftColumns = $rightColumns = [];
        foreach ($leftTable['columns'] as $idx => $column) {
            $leftColumns[] = $column['Field'];
        }
        foreach ($rightTable['columns'] as $idx => $column) {
            $rightColumns[] = $column['Field'];
        }
        foreach ($leftTable['columns'] as $idx => $column) {
            if (!in_array($column['Field'], $rightColumns)) {
                $this->sqls[] = 'ALTER TABLE ' . $rightTable['name'] . ' ADD ' . $column['Field'] . ' ' . $column['Type'] . ' NOT NULL  COMMENT "' . $column['Comment'] . '"';
                $missing[] = 'M:' . $column['Field'];
                continue;
            }
            if (json_encode($column) != json_encode($rightTable['columns'][$idx])) {
                $this->sqls[] = 'ALTER TABLE ' . $rightTable['name'] . ' CHANGE ' . $column['Field'] . ' ' . $column['Field'] . ' ' . $column['Type'] . ' NOT NULL  COMMENT "' . $column['Comment'] . '"';
                $different[] = 'D:' . $column['Field'];
                continue;
            }
        }
        foreach ($rightTable['columns'] as $idx => $column) {
            if (!in_array($column['Field'], $leftColumns)) {
                $this->sqls[] = 'ALTER TABLE ' . $rightTable['name'] . ' DROP ' . $column['Field'];
                $needless[] = 'N:' . $column['Field'];
            }
        }
        $ret = '';
        if (!empty($missing)) {
            $ret .= implode(PHP_EOL, $missing) . PHP_EOL;
        }
        if (!empty($different)) {
            $ret .= implode(PHP_EOL, $different) . PHP_EOL;
        }
        if (!empty($needless)) {
            $ret .= implode(PHP_EOL, $needless) . PHP_EOL;
        }
        return $ret;
    }

    function compareIndex($leftTable, $rightTable)
    {
        $missing = $needless = $different = [];
        if (!$rightTable) {
            return '-';
        }
        $leftIndexs = $rightIndexs = [];
        foreach ($leftTable['index'] as $idx => $column) {
            if (isset($leftTable[$idx]['Cardinality'])) {
                unset($leftTable[$idx]['Cardinality']);
            }
            $leftIndexs[] = $column['Key_name'];
        }
        foreach ($rightTable['index'] as $idx => $column) {
            if (isset($rightTable[$idx]['Cardinality'])) {
                unset($rightTable[$idx]['Cardinality']);
            }
            $rightIndexs[] = $column['Key_name'];
        }
        $this->sqls = [];
        foreach ($leftTable['index'] as $idx => $index) {
            if (!in_array($index['Key_name'], $rightIndexs)) {
                $this->sqls[] = 'ALTER TABLE ' . $rightTable['name'] . ' ADD INDEX `' . $index['Key_name'] . '`(....)';
                $missing[] = 'M:' . $index['Key_name'];
                continue;
            }
            if (array_diff($index, $rightTable['index'][$idx]) != []) {
                $this->sqls[] = 'ALTER TABLE ' . $rightTable['name'] . ' CHANGE INDEX `' . $index['Key_name'] . '`(....)';
                $different[] = 'D:' . $index['Key_name'];
                continue;
            }
        }
        foreach ($rightTable['index'] as $idx => $index) {
            if (!in_array($column['Key_name'], $leftIndexs)) {
                $this->sqls[] = 'ALTER TABLE ' . $rightTable['name'] . ' DROP INDEX `' . $index['Key_name'] . '`';
                $needless[] = 'N:' . $index['Key_name'];
            }
        }
        $ret = '';
        if (!empty($missing)) {
            $ret .= implode(PHP_EOL, $missing) . PHP_EOL;
        }
        if (!empty($different)) {
            $ret .= implode(PHP_EOL, $different) . PHP_EOL;
        }
        if (!empty($needless)) {
            $ret .= implode(PHP_EOL, $needless) . PHP_EOL;
        }
        return $ret;
    }

    function compareRows($leftTable, $rightTable)
    {
        if (false === $leftTable && false === $rightTable) {
            return '-';
        }
        if ($leftTable && false === $rightTable) {
            return $leftTable['rows'] . '/-';
        }
        if (false === $leftTable && $rightTable) {
            return '-/' . $rightTable['rows'];
        }
        if ($leftTable['rows'] === $rightTable['rows']) {
            return $leftTable['rows'];
        }
        return $leftTable['rows'] . '/' . $rightTable['rows'];
    }

    function getConfigFromFile($file)
    {
        $json = json_decode(file_get_contents($file), true);
        if (!$json) {
            return null;
        }
        $json['file'] = $file;
        if (!isset($json['db'])) {
            $json['db'] = 0;
        }
        return [
            'file' => $file,
            'driver' => $json['driver'],
            'host' => $json['host'],
            'port' => $json['port'],
            'username' => $json['username'],
            'password' => $json['password'],
            'database' => $json['database'],
            'character' => $json['character'],
        ];
    }

    protected function configure()
    {
        $this->setName('mysql:diff')->setDescription('比较数据库');
        $this->addArgument('left', InputArgument::REQUIRED, '左侧待比较的数据库配置,请指定完整的JSON文件');
        $this->addArgument('right', InputArgument::REQUIRED, '右侧待比较的数据库,请指定完整的JSON文件');
        $this->addOption('tables', null, InputOption::VALUE_OPTIONAL, '要比较的表名,多个以,分开,默认比较全部');
        $this->addOption('dump-sql', null, InputOption::VALUE_OPTIONAL, '要比较的表名,多个以,分开,默认比较全部');
    }
}