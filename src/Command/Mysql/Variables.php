<?php

namespace App\Command\Mysql;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Variables extends Command
{

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $path = realpath($input->getArgument('path'));
            if (!$path || !file_exists($path)) {
                throw new \Exception($path . '不存在');
            }
            $configs = array();
            if (is_file($path)) {
                if ($config = $this->getConfigFromFile($path)) {
                    $configs[] = $config;
                }
            } else {
                $path .= DIRECTORY_SEPARATOR;
                foreach (scandir($path) as $f) {
                    if (is_file($path . $f)) {
                        if ($config = $this->getConfigFromFile($path . $f)) {
                            $configs[] = $config;
                        }
                    }
                }
            }
            $table = new Table($output);
            $headers = [
                'file',
                'host',
                'port',
                'sql_mode',
                'connect_timeout',
                'wait_timeout',
                'max_execution_time',
                'max_connections',
                'max_user_connections'
            ];
            $hosts = [];
            $body = [];
            foreach ($configs as $idx => $config) {
                try {
                    if (in_array($config['host'] . ":" . $config['port'], $hosts)) {
                        continue;
                    }
                    array_push($hosts, $config['host'] . ":" . $config['port']);
                    $output->write("connecting to " . $config['host'] . ":" . $config['port'] . "......");
                    $db = new \PDO('mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['database'], $config['username'], $config['password'], array(
                        \PDO::ATTR_TIMEOUT => 1
                    ));
                    $rows = $db->query("show global variables")->fetchAll(\PDO::FETCH_KEY_PAIR);
                    $body[$idx] = [
                        'file' => $config['file'],
                        'host' => $config['host'],
                        'port' => $config['port'],
                    ];

                    $body[$idx]['sql_mode'] = $rows['sql_mode'];
                    $body[$idx]['connect_timeout'] = $rows['connect_timeout'];
                    $body[$idx]['wait_timeout'] = $rows['wait_timeout'];
                    $body[$idx]['max_execution_time'] = $rows['max_execution_time'];
                    $body[$idx]['max_connections'] = $rows['max_connections'];
                    $body[$idx]['max_user_connections'] = $rows['max_user_connections'];
                    $output->writeln("Ok");
                } catch (\Exception $e) {
                    $output->writeln("Fail");
                }
            }
            print_r($body);
            $table->setHeaders($headers)->setRows($body)->render();
        } catch (\Exception $e) {
            $output->writeln($e->getMessage());
        }
    }

    function getConfigFromFile($file)
    {
        $json = json_decode(file_get_contents($file), true);
        if (!$json) {
            return null;
        }
        $json['file'] = $file;
        return [
            'file' => basename($file),
            'driver' => isset($json['driver']) ? $json['driver'] : '--',
            'host' => isset($json['host']) ? $json['host'] : '3306',
            'port' => isset($json['port']) ? $json['port'] : '--',
            'username' => isset($json['username']) ? $json['username'] : '--',
            'password' => isset($json['password']) ? $json['password'] : '--',
            'database' => isset($json['database']) ? $json['database'] : '--',
            'character' => isset($json['character']) ? $json['character'] : '--',
        ];
    }

    protected function configure()
    {
        $this->setName('mysql:variables');
        $this->setDescription('列出所有Mysql的variables');
        $this->addArgument('path', InputArgument::REQUIRED, 'JSON文件的目录');
    }
}