<?php
namespace App\Command\Redis;

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
                'host' ,
                'port' ,
                'auth' ,
                'db'
            ];
            if ( $input->getOption ( 'connect' ) ) {
                $headers[] = 'connect';
                foreach ( $configs as $idx => $config ) {
                    try {
                        $redis = new \Redis();
                        if ( !$redis->connect ( $config[ 'host' ] , $config[ 'port' ] , 1 ) ) {
                            $configs[ $idx ][ 'connect' ] = '连接失败';
                        }
                        if ( $config[ 'auth' ] ) {
                            if ( !$redis->auth ( $config[ 'auth' ] ) ) {
                                $configs[ $idx ][ 'connect' ] = '授权失败';
                                continue;
                            }
                        }
                        $configs[ $idx ][ 'connect' ] = '连接成功';
                    } catch ( \Exception $e ) {
                        $configs[ $idx ][ 'connect' ] = $e->getMessage ();
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
        if ( !isset( $json[ 'db' ] ) ) {
            $json[ 'db' ] = 0;
        }
        return [
            'file' => basename ( $file ) ,
            'host' => isset( $json[ 'host' ] ) ? $json[ 'host' ] : '--' ,
            'port' => isset( $json[ 'port' ] ) ? $json[ 'port' ] : 6379 ,
            'auth' => isset( $json[ 'auth' ] ) ? $json[ 'auth' ] : null ,
            'db' => isset( $json[ 'db' ] ) ? $json[ 'db' ] : 0 ,
        ];
    }

    protected function configure () {
        $this->setName ( 'redis:config' );
        $this->setDescription ( '列出所有Redis的配置信息' );
        $this->addArgument ( 'path' , InputArgument::REQUIRED , 'JSON文件的目录' );
        $this->addOption ( 'connect' , 'c' , InputOption::VALUE_NONE , '尝试连接数据库' );
    }
}