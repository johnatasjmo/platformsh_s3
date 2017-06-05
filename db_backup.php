<?php

/*
WHAT IS THIS?
Automated backup script that pulls the database, compresses, and syncs that plus
any user uploaded files over to an S3 bucket. Designed for Platform.sh

SETUP:
- Ensure that monolog is available, if not, add via composer.json - composer require monolog/monolog
- Add the AWS PHP SDK (aws/aws-sdk-php) via composer.json  - composer require aws/aws-sdk-php
- Add composer require platformsh/config-reader
- Ensure that AWS AMI user is created and has access to read/write the ftusa-site-backups S3 bucket
- Add backups directory to .platform.app.yaml
    mounts:
        "/backups": "shared:files/backups"
- Add environmental variables in Platform.sh
    - env:AWS_ACCESS_KEY_ID
    - env:AWS_SECRET_ACCESS_KEY
    - env:LOGGLY_TOKEN (note: get from loggly > source setup > tokens)
    - env:FILES_TO_BACKUP (optional: only add if you have user uploaded files to back up -- if added use, full path [e.g. /app/storage/app/uploads])
- Deploy and test using: php /scripts/db_backup.php
- Add cron task to .platform.app.yml
    db_backup:
        spec: "0 0 * * *"
        cmd: "php ./jobs/db_backup.php"

Adapted by https://github.com/kaypro4 from an example by https://github.com/JGrubb - Thanks John!
*/

$home_dir = getenv('PLATFORM_DIR');

require_once $home_dir . '/vendor/autoload.php';

$bucket = 'courier-platform';
/*$fixedBranch = 'master'*/
$fixedBranch = strtolower(preg_replace('/[\W\s\/]+/', '-', getenv('PLATFORM_BRANCH')));
$baseDirectory = 'platform/' . getenv('PLATFORM_APPLICATION_NAME') . '/' . $fixedBranch;

use Monolog\Logger;
use Monolog\Handler\LogglyHandler;
use Monolog\Formatter\LogglyFormatter;

$logger = new Logger('backup_logger');
$logger->pushHandler(new LogglyHandler(getenv('LOGGLY_TOKEN') . '/tag/backup_logger', Logger::INFO));

$psh = new Platformsh\ConfigReader\Config();
if($psh->isAvailable()) {
    //backup the db
    try {
        $sql_filename = date('Y-m-d_H:i:s') . '.gz';
        $backup_path = $home_dir . "/backups/$sql_filename";

        $database = $psh->relationships['database'][0];
        
        putenv("MYSQL_PWD={$database['password']}");
        exec("mysqldump --opt -h {$database['host']} -u {$database['username']} {$database['path']} | gzip > $backup_path");

        $s3 = new Aws\S3\S3Client([
            'version' => 'latest',
            'region'  => 'us-west-2',
            'credentials' => [
                'key'    => getenv('AWS_ACCESS_KEY_ID'),
                'secret' => getenv('AWS_SECRET_ACCESS_KEY')
            ]
        ]);

        $s3->putObject([
            'Bucket' => $bucket,
            'Key' => "$baseDirectory/database/$sql_filename",
            'Body' => fopen($backup_path, 'r')
        ]);

        $logger->addInfo("Successfully backed up $sql_filename");
    } catch (Exception $e) {
        $logger->addError("Database backup error: " . $e->getMessage());
    }
    
    if (getenv('FILES_TO_BACKUP') !== false) {
        //backup any user uploaded files using sync if the environmental variable
        //exists for the environment
        try {
        
            $s3 = new Aws\S3\S3Client([
                'version' => 'latest',
                'region'  => 'us-west-2',
                'credentials' => [
                    'key'    => getenv('AWS_ACCESS_KEY_ID'),
                    'secret' => getenv('AWS_SECRET_ACCESS_KEY')
                ]
            ]);

            //sync the files from one directory
            $s3->uploadDirectory(getenv('FILES_TO_BACKUP'), "$bucket/$baseDirectory/files");

            $logger->addInfo("Successfully backed up: " . getenv('FILES_TO_BACKUP'));
        } catch (Exception $e) {
            $logger->addError("Files backup error: " . $e->getMessage());
        }
    }

}
