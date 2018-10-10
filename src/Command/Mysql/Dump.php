<?php
namespace App\Command\Mysql;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Dump extends Command {

    protected function execute ( InputInterface $input , OutputInterface $output ) {
        try {
            $connection = $input->getOption ( 'connection' );
            if ( !file_exists ( $connection ) ) {
                throw new \RuntimeException( '文件' . $connection . '不存在' );
            }
            $config = $this->getConfigFromFile ( $connection );
            if ( !$config ) {
                throw new \RuntimeException( '文件' . $connection . '不是合法的数据库配置文件' );
            }
            $table = trim ( $input->getOption ( 'tables' ) );
            $skipData = $input->getOption ( 'skip-data' );
            $pdo = new \PDO( 'mysql:host=' . $config[ 'host' ] . ';port=' . $config[ 'port' ] . ';dbname=' . $config[ 'database' ] , $config[ 'username' ] , $config[ 'password' ] , array (
                \PDO::ATTR_TIMEOUT => 1
            ) );
            if ( !$table ) {
                foreach ( $pdo->query ( 'show tables' )->fetchAll () as $item ) {
                    $tables[] = $item[ 0 ];
                }
            } elseif ( stripos ( $table , '%' ) ) {
                foreach ( $pdo->query ( 'show tables like "' . $table . '"' )->fetchAll () as $item ) {
                    $tables[] = $item[ 0 ];
                }
            } else {
                $tables = explode ( ',' , $table );
            }
            foreach ( $tables as $table ) {
                $sqltext = 'DROP TABLE IF EXISTS ' . $table . ';';
                echo $sqltext . PHP_EOL;
                $sql = "SHOW CREATE TABLE `{$table}`";
                $row = $pdo->query ( $sql )->fetch ( \PDO::FETCH_ASSOC );
                echo $row[ 'Create Table' ] . ';' . PHP_EOL;
                if ( $skipData == 'Y' || $skipData == 'yes' ) {
                } else {
                    $start = 0;
                    $basesql = "select * from `{$table}`";
                    while ( true ) {
                        $sql = $basesql . ' limit ' . $start . ', 1000';
                        $rows = $pdo->query ( $sql )->fetchAll ( \PDO::FETCH_ASSOC );
                        if ( empty( $rows ) ) {
                            break;
                        }
                        $start += 1000;
                        foreach ( $rows as $row ) {
                            $head = array_keys ( $row );
                            foreach ( $head as $i => $v ) {
                                $head[ $i ] = '`' . $v . '`';
                            }
                            $body = array_values ( $row );
                            foreach ( $body as $i => $v ) {
                                $body[ $i ] = '"' . addslashes ( $v ) . '"';
                            }
                            echo "INSERT INTO `{$table}` (" . implode ( "," , $head ) . ") VALUES(" . implode ( "," , $body ) . ");" . PHP_EOL;
                        }
                    }
                }
            }
        } catch ( \Exception $e ) {
            $output->writeln ( $e->getMessage () );
        }
    }

    function getConfigFromFile ( $file ) {
        $json = json_decode ( file_get_contents ( $file ) , true );
        if ( !$json ) {
            return null;
        }
        $json[ 'file' ] = $file;
        if ( !isset( $json[ 'db' ] ) ) {
            $json[ 'db' ] = 0;
        }
        return [
            'file' => $file ,
            'driver' => $json[ 'driver' ] ,
            'host' => $json[ 'host' ] ,
            'port' => $json[ 'port' ] ,
            'username' => $json[ 'username' ] ,
            'password' => $json[ 'password' ] ,
            'database' => $json[ 'database' ] ,
            'character' => $json[ 'character' ] ,
        ];
    }

    protected function configure () {
        $this->setName ( 'mysql:dump' )->setDescription ( 'dump数据库' );
        $this->addOption ( 'connection' , null , InputOption::VALUE_REQUIRED , '数据库配置文件' );
        $this->addOption ( 'tables' , null , InputOption::VALUE_REQUIRED , '要导出的数据表名,多个以,分开' );
        $this->addOption ( 'skip-data' , null , InputOption::VALUE_REQUIRED , '是否跳过表中的数据' , 'no' );
    }
}