<?php

require __DIR__.'/vendor/autoload.php';

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

$FORCE = false;
$CONFIG = null;

$PARAMS = getOpt('', ['force', 'config::']);

if (isset($PARAMS['force'])) {
    $FORCE = true;
}

if (!isset($PARAMS['config']) || !$PARAMS['config']) {
    echo 'Parameter "config" required'.PHP_EOL;
    exit(1);
}

if (!file_exists($PARAMS['config'])) {
    echo 'File does not exist.'.PHP_EOL;
    exit(1);
}

try {
    $CONFIG = Yaml::parse(file_get_contents($PARAMS['config']));
}
catch (ParseException $e) {
    echo 'Invalid Yaml file.'.PHP_EOL;
    exit(1);
}

if (!isset($CONFIG['mode']) || !in_array($CONFIG['mode'], ['password', 'ssl'])) {
    echo 'Invalid config "mode".'.PHP_EOL;
    exit(1);
}

$invalid = [];
foreach (['host', 'port', 'user'] as $required) {
    if (isset($CONFIG[$required])) {
        continue;
    }

    $invalid[] = $required;
}

if (!empty($invalid)) {
    echo sprintf('Config required "%s".', implode('", "', $invalid)).PHP_EOL;
    exit(1);
}

if (($CONFIG['mode'] == 'password') && !isset($CONFIG['password'])) {
    echo 'Config "password" required.'.PHP_EOL;
    exit(1);
}

if (($CONFIG['mode'] == 'ssl') && !isset($CONFIG['private_key'])) {
    echo 'Config "private_key" required.'.PHP_EOL;
    exit(1);
}

if (($CONFIG['mode'] == 'ssl') && !is_file($CONFIG['private_key'])) {
    echo 'Private key file does not exist.'.PHP_EOL;
    exit(1);
}

$files = [];

if (isset($CONFIG['files']) && is_array($CONFIG['files'])) {
    $files = $CONFIG['files'];
}

if (empty($files)) {
    echo 'No file to upload..'.PHP_EOL;
    exit(1);
}

if ($CONFIG['mode'] == 'password') {
    $password = $CONFIG['password'];
}
else {
    $password = PublicKeyLoader::load(
        file_get_contents($CONFIG['private_key']),
        isset($CONFIG['passphrase']) ? $CONFIG['passphrase'] : false
    );
}

$sftp = new SFTP($CONFIG['host'], $CONFIG['port']);

if (!$sftp->login($CONFIG['user'], $password)) {
    echo 'Unable to authenticate.'.PHP_EOL;
    exit(1);
}

echo 'STAR: Process start.'.PHP_EOL;

foreach ($files as $row) {
    if (!strpos($row, ':')) {
        echo sprintf('WARN: %s', $row).PHP_EOL;
        continue;
    }

    $part = explode(':', $row);

    $local = trim($part[0]);
    $remote = rtrim(trim($part[1]), '/');

    if (!file_exists($local)) {
        echo sprintf('ERRO: %s', $local).PHP_EOL;
        continue;
    }

    if (pathinfo($remote, PATHINFO_EXTENSION) === '') {
        echo sprintf('ERRO: %s', $remote).PHP_EOL;
        continue; 
    }

    $dir = dirname($remote);

    if (!$sftp->is_dir($dir) && !$sftp->mkdir($dir, -1, true)) {
        echo sprintf('WARN: %s', $dir).PHP_EOL;
        continue;
    }

    $exists = ($sftp->stat($remote) !== false);

    if ($exists && !$FORCE) {
        echo sprintf('EXIS: %s', $remote).PHP_EOL;
        continue;
    }
    else if (!$sftp->put($remote, $local, SFTP::SOURCE_LOCAL_FILE)) {
        echo sprintf('FAIL: %s -> %s', $local, $remote).PHP_EOL;
        continue;
    }

    echo sprintf('SUCC: %s -> %s', $local, $remote).PHP_EOL;
}

echo 'DONE: Process done.'.PHP_EOL;
exit(0);
