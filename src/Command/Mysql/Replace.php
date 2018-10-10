<?php
namespace App\Command\Mysql;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Replace extends Command {

    protected function execute ( InputInterface $input , OutputInterface $output ) {
        try {
            $m = $input->getOption ( 'm' );
            if ( $m ) {
                ini_set ( "memory_limit" , $m );
            }
            $limit = (int) $input->getOption ( 'l' );
            if ( !$limit ) {
                $limit = 1000;
            }
            $connection = $input->getOption ( 'connection' );
            if ( !file_exists ( $connection ) ) {
                throw new \RuntimeException( '文件' . $connection . '不存在' );
            }
            $config = $this->getConfigFromFile ( $connection );
            if ( !$config ) {
                throw new \RuntimeException( '文件' . $connection . '不是合法的数据库配置文件' );
            }
            $pk = '';
            $field = $input->getOption ( 'field' );
            $table = $input->getOption ( 'table' );
            $search = $input->getOption ( 'search' );
            $replace = $input->getOption ( 'replace' );
            $fieldExist = false;
            $pdo = new \PDO( 'mysql:host=' . $config[ 'host' ] . ';port=' . $config[ 'port' ] . ';dbname=' . $config[ 'database' ] , $config[ 'username' ] , $config[ 'password' ] , array (
                \PDO::ATTR_TIMEOUT => 1
            ) );
            $query = $pdo->query ( 'show full columns from `' . $table . '`' );
            while ( $result = $query->fetchObject () ) {
                if ( $result->Key == 'PRI' ) {
                    if ( $pk ) {
                        throw new \RuntimeException( $table . '中含有多个主键' );
                    }
                    $pk = $result->Field;
                }
                if ( $result->Field == $field ) {
                    $fieldExist = true;
                }
            }
            if ( !$fieldExist ) {
                throw new \RuntimeException( $table . '中没有' . $field );
            }
            $sql = "SELECT COUNT(`{$pk}`) FROM `{$table}`";
            $total = $pdo->query ( $sql )->fetchColumn ( 0 );
            $sql = "SELECT `{$pk}`, `{$field}` FROM `{$table}`";
            $lastValue = '';
            $current = 1;
            $updated = 0;
            while ( true ) {
                if ( $lastValue === '' ) {
                    $statement = $sql;
                } else {
                    $statement = $sql . " WHERE `{$pk}` > " . $pdo->quote ( $lastValue );
                }
                $items = $pdo->query ( $statement . ' LIMIT ' . $limit )->fetchAll ( \PDO::FETCH_ASSOC );
                if ( empty( $items ) ) {
                    break;
                }
                foreach ( $items as $item ) {
                    $output->write ( $current . '/' . $total . ' ' );
                    $current++;
                    if ( false !== strpos ( $item[ $field ] , $search ) ) {
                        $update = "UPDATE `{$table}` SET `{$field}` = replace(`{$field}`, '{$search}', '{$replace}') WHERE `{$pk}` = " . $pdo->quote ( $item[ $pk ] );
                        if ( $pdo->exec ( $update ) ) {
                            $output->writeln ( "Updated {$item[$pk]} successfully" );
                            $updated++;
                        } else {
                            $output->writeln ( "Failed to update {$item[$pk]}" );
                        }
                    } else {
                        $output->writeln ( "Skipped {$item[$pk]}" );
                    }
                    $lastValue = $item[ $pk ];
                }
            }
            if ( $updated > 0 ) {
                $output->writeln ( "Updated {$updated} rows successfully" );
            }
            $output->writeln ( 'Success' );
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
        $this->setName ( 'mysql:replace' )->setDescription ( '替换数据库中的内容' );
        $this->addOption ( 'connection' , null , InputOption::VALUE_REQUIRED , '数据库配置文件' );
        $this->addOption ( 'table' , null , InputOption::VALUE_REQUIRED , '数据表名' );
        $this->addOption ( 'field' , null , InputOption::VALUE_REQUIRED , '替换的列' );
        $this->addOption ( 'search' , null , InputOption::VALUE_REQUIRED , '搜索内容' );
        $this->addOption ( 'replace' , null , InputOption::VALUE_REQUIRED , '替换内容' );
        $this->addOption ( 'limit' , 'l' , InputOption::VALUE_OPTIONAL , '每次查询的结果集数' );
        $this->addOption ( 'memory' , 'm' , InputOption::VALUE_OPTIONAL , '设置memory_limit的值' );
    }
}