<?php
namespace App\Command\Mysql;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Config extends Command {

    protected function execute ( InputInterface $input , OutputInterface $output ) {
        try {
            $path = realpath ( $input->getArgument ( 'path' ) );
            if ( !$path || !file_exists ( $path ) ) {
                throw new \Exception( $path . '不存在' );
            }
            $configs = array ();
            if ( is_file ( $path ) ) {
                if ( $config = $this->getConfigFromFile ( $path ) ) {
                    $configs[] = $config;
                }
            } else {
                $path .= DIRECTORY_SEPARATOR;
                foreach ( scandir ( $path ) as $f ) {
                    if ( is_file ( $path . $f ) ) {
                        if ( $config = $this->getConfigFromFile ( $path . $f ) ) {
                            $configs[] = $config;
                        }
                    }
                }
            }
            $table = new Table( $output );
            $headers = [
                'file' ,
                'driver' ,
                'host' ,
                'port' ,
                'username' ,
                'password' ,
                'database' ,
                'character'
            ];
            if ( $input->getOption ( 'connect' ) ) {
                $headers[] = 'connect';
                foreach ( $configs as $idx => $config ) {
                    try {
                        $output->write ( "connecting to " . $config[ 'host' ] . ":" . $config[ 'port' ] . "......" );
                        new \PDO( 'mysql:host=' . $config[ 'host' ] . ';port=' . $config[ 'port' ] . ';dbname=' . $config[ 'database' ] , $config[ 'username' ] , $config[ 'password' ] , array (
                            \PDO::ATTR_TIMEOUT => 1
                        ) );
                        $configs[ $idx ][ 'connect' ] = 'Successful';
                        $output->writeln ( "Ok" );
                    } catch ( \Exception $e ) {
                        $configs[ $idx ][ 'connect' ] = $e->getMessage ();
                        $output->writeln ( "Fail" );
                    }
                }
            }
            $table->setHeaders ( $headers )->setRows ( $configs )->render ();
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
        return [
            'file' => basename ( $file ) ,
            'driver' => isset( $json[ 'driver' ] ) ? $json[ 'driver' ] : '--' ,
            'host' => isset( $json[ 'host' ] ) ? $json[ 'host' ] : '3306' ,
            'port' => isset( $json[ 'port' ] ) ? $json[ 'port' ] : '--' ,
            'username' => isset( $json[ 'username' ] ) ? $json[ 'username' ] : '--' ,
            'password' => isset( $json[ 'password' ] ) ? $json[ 'password' ] : '--' ,
            'database' => isset( $json[ 'database' ] ) ? $json[ 'database' ] : '--' ,
            'character' => isset( $json[ 'character' ] ) ? $json[ 'character' ] : '--' ,
        ];
    }

    protected function configure () {
        $this->setName ( 'mysql:config' );
        $this->setDescription ( '列出所有Mysql的配置信息' );
        $this->addArgument ( 'path' , InputArgument::REQUIRED , 'JSON文件的目录' );
        $this->addOption ( 'connect' , 'c' , InputOption::VALUE_NONE , '尝试连接数据库' );
    }
}