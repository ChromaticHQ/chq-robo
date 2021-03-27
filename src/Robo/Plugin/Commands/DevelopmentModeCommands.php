<?php

namespace ChqRobo\Robo\Plugin\Commands;

use AsyncAws\S3\S3Client;
use DrupalFinder\DrupalFinder;
use Drupal\Component\Serialization\Yaml;
use Robo\Exception\TaskException;
use Robo\Result;
use Robo\Robo;
use Robo\Tasks;

/**
 * Robo commands related to changing development modes.
 */
class DevelopmentModeCommands extends Tasks
{

    /**
     * Drupal root directory.
     *
     * @var string
     */
    protected $drupalRoot;

    /**
     * Path to front-end development services path.
     *
     * @var string
     */
    protected $devServicesPath;

    /**
     * Class constructor.
     */
    public function __construct()
    {
        // Treat this command like bash -e and exit as soon as there's a failure.
        $this->stopOnFail();

        // Find Drupal root path.
        $drupalFinder = new DrupalFinder();
        $drupalFinder->locateRoot(getcwd());
        $this->drupalRoot = $drupalFinder->getDrupalRoot();
        $this->devServicesPath = "$this->drupalRoot/sites/fe.development.services.yml";
    }

    /**
     * Refreshes a development environment.
     *
     * Completely refreshes a development environment including running 'composer install', starting Lando, downloading
     * a database dump, importing it, running 'drush deploy', disabling front-end caches, and providing a login link.
     *
     * @param string $siteDir
     *   The Drupal site directory name.
     *
     * @aliases magic
     *
     * @return \Robo\Result
     *   The result of the set of tasks.
     */
    public function devRefresh($siteDir = 'default'): Result
    {
        $this->io()->title('developer magic. 🦄');
        $this->taskComposerInstall()->run();
        $this->taskExec('lando')->arg('start')->run();
        $result = $this->databaseRefreshLando();
        $result = $this->frontendDevEnable($siteDir, ['yes' => true]);
        $result = $this->drupalLogin($siteDir);
        return $result;
    }

    /**
     * Download the latest database dump for the site.
     *
     * @return string
     *   The path of the last downloaded database.
     *
     * @throws \Robo\Exception\TaskException
     */
    public function databaseDownload()
    {
        $this->io()->title('database download.');

        $awsConfigDirPath = getenv('HOME') . '/.aws';
        $awsConfigFilePath = "$awsConfigDirPath/credentials";
        if (!is_dir($awsConfigDirPath) || !file_exists($awsConfigFilePath)) {
            $result = $this->configureAwsCredentials($awsConfigDirPath, $awsConfigFilePath);
            if ($result->wasCancelled()) {
                return Result::cancelled();
            }
        }

        if (!$bucket = Robo::config()->get('database_s3_bucket')) {
            throw new TaskException($this, 'database_s3_bucket value not set in robo.yml.');
        }

        $s3 = new S3Client();
        $objects = $s3->listObjectsV2(['Bucket' => $bucket]);
        $objects = iterator_to_array($objects);
        // Ensure objects are sorted by last modified date.
        usort($objects, fn($a, $b) => $a->getLastModified()->getTimestamp() <=> $b->getLastModified()->getTimestamp());
        $latestDatabaseDump = array_pop($objects);
        $dbFilename = $latestDatabaseDump->getKey();

        $result = $s3->GetObject([
            'Bucket' => $bucket,
            'Key' => $dbFilename,
        ]);
        $fp = fopen($dbFilename, 'wb');
        stream_copy_to_stream($result->getBody()->getContentAsResource(), $fp);
        $this->say('Database dump file downloaded >>> ' . $dbFilename);
        return $dbFilename;
    }

    /**
     * Configure AWS credentials.
     *
     * @param string $awsConfigDirPath
     *   Path to the AWS configuration directory.
     * @param string $awsConfigFilePath
     *   Path to the AWS configuration file.
     */
    protected function configureAwsCredentials(string $awsConfigDirPath, string $awsConfigFilePath)
    {
        $yes = $this->io()->confirm('AWS S3 credentials not detected. Do you wish to configure them?');
        if (!$yes) {
            return Result::cancelled();
        }

        if (!is_dir($awsConfigDirPath)) {
            $this->_mkdir($awsConfigDirPath);
        }

        if (!file_exists($awsConfigFilePath)) {
            $this->_touch($awsConfigFilePath);
        }

        $awsKeyId = $this->ask("AWS Access Key ID:");
        $awsSecretKey = $this->askHidden("AWS Secret Access Key:");
        return $this->taskWriteToFile($awsConfigFilePath)
            ->line('[default]')
            ->line("aws_access_key_id = $awsKeyId")
            ->line("aws_secret_access_key = $awsSecretKey")
            ->run();
    }

    /**
     * Refresh a site database in Lando.
     *
     * @param array $opts
     *   The options passed in.
     *
     * @return \Robo\Result
     *   The result of the set of tasks.
     */
    public function databaseRefreshLando(): Result
    {
        $this->io()->title('chq database refresh.');

        $dbPath = $this->databaseDownload();

        $this->io()->section('importing chq database.');
        $this->say("Importing $dbPath");
        $this->taskExec('lando')
            ->arg('db-import')
            ->arg($dbPath)
            ->run();

        $this->say("Deleting $dbPath");
        $this->taskExec('rm')->args($dbPath)->run();
        return $this->drushDeployLando();
    }

    /**
     * Generate Drupal login link.
     *
     * @param string $siteDir
     *   The Drupal site directory name.
     * @param bool $lando
     *   Use lando to call drush, else call drush directly.
     *
     * @return \Robo\Result
     *   The result of the set of tasks.
     */
    public function drupalLogin($siteDir = 'default', $lando = true): Result
    {
        $this->io()->section("create login link.");
        if ($lando) {
            $uri = $this->landoUri($siteDir);
            $this->say("Lando URI detected: $uri");
            return $this->taskExec('lando')
                ->arg('drush')
                ->arg('user:login')
                ->option('--uri', $uri)
                ->dir("web/sites/$siteDir")
                ->run();
        }
        return $this->taskExec('../../../drush')
            ->arg('user:login')
            ->dir("web/sites/$siteDir")
            ->run();
    }

    /**
     * Detect Lando URI.
     *
     * @param string $siteDir
     *   The Drupal site directory name.
     *
     * @return string
     *   The Lando URI.
     */
    protected function landoUri($siteDir): string
    {
        $landoConfig = Yaml::decode(file_get_contents('.lando.yml'));
        $uri = $landoConfig['services']['appserver']['overrides']['environment']['DRUSH_OPTIONS_URI'] ?? null;
        if ($uri) {
            return $uri;
        } elseif (isset($landoConfig['proxy']['appserver'])) {
            // Detect multi-site configurations.
            $siteDomains = array_filter($landoConfig['proxy']['appserver'], function ($domain) {
                if (strpos($domain, '$siteDir') !== false) {
                    return true;
                }
            });
            if (count($siteDomains > 1)) {
                throw new TaskException($this, 'Unable to determine URI.');
            } elseif (count($siteDomains == 1)) {
                return array_pop($siteDomains);
            }
        } else {
            // Our final fallback.
            return 'http://' . $landoConfig['name'] . 'lndo.site';
        }
    }

    /**
     * Refresh database on Tugboat.
     *
     * @return null|\Robo\Result
     *   The task result.
     */
    public function databaseRefreshTugboat(): Result
    {
        $this->io()->title('refresh tugboat databases.');
        $dbPath = $this->databaseDownload();
        if (empty($dbPath)) {
            throw new TaskException($this, 'Database download failed.');
        }

        $result = $this->taskExec('mysql')
            ->option('-h', 'mariadb')
            ->option('-u', 'tugboat')
            ->option('-ptugboat')
            ->option('-e', 'drop database if exists tugboat; create database tugboat;')
            ->run();

        $this->io()->section('import chromatichq.com database.');
        $result = $this->taskExec("zcat $dbPath | mysql -h mariadb -u tugboat -ptugboat tugboat")
            ->run();
        $this->taskExec('rm')->args($dbPath)->run();
        return $result;
    }

    /**
     * Deploy Drush via Lando.
     *
     * @return \Robo\Result
     *   The result of the set of tasks.
     *
     * @see https://www.drush.org/deploycommand
     */
    protected function drushDeployLando(): Result
    {
        $this->io()->section('drush deploy.');
        return $this->taskExecStack()
            ->dir('web/sites/default')
            ->exec("lando drush deploy --yes")
            // Import the latest configuration again. This includes the latest
            // configuration_split configuration. Importing this twice ensures that
            // the latter command enables and disables modules based upon the most up
            // to date configuration. Additional information and discussion can be
            // found here:
            // https://github.com/drush-ops/drush/issues/2449#issuecomment-708655673
            ->exec("lando drush config:import --yes")
            ->run();
    }

    /**
     * Enable front-end development mode.
     *
     * @param string $siteDir
     *   The Drupal site directory name.
     * @param array $opts
     *   The options.
     *
     * @option boolean $yes Default answers to yes.
     * @aliases fede
     *
     * @return \Robo\Result
     *   The result of the set of tasks.
     */
    public function frontendDevEnable($siteDir = 'default', array $opts = ['yes|y' => false])
    {
        $devSettingsPath = "$this->drupalRoot/sites/$siteDir/settings.local.php";

        // @todo: undefined index when called with magic.
        if (!$opts['yes']) {
            $this->yell("This command will overwrite any customizations you have made to $devSettingsPath and
                $this->devServicesPath.");
            $yes = $this->io()->confirm('This command is destructive. Do you wish to continue?');
            if (!$yes) {
                return Result::cancelled();
            }
        }

        $this->io()->title('enabling front-end development mode.');
        $this->say("copying settings.local.php and development.services.yml into sites/$siteDir.");

        $result = $this->taskFilesystemStack()
            ->copy("$this->drupalRoot/sites/example.settings.local.php", $devSettingsPath, true)
            ->copy("$this->drupalRoot/sites/development.services.yml", $this->devServicesPath, true)
            ->run();

        $this->say("enablig twig.debug in development.services.yml.");
        $devServices = Yaml::decode(file_get_contents($this->devServicesPath));
        $devServices['parameters']['twig.config'] = [
            'debug' => true,
            'auto_reload' => true,
        ];
        file_put_contents($this->devServicesPath, Yaml::encode($devServices));

        $this->say("disabling render and dynamic_page_cache in settings.local.php.");
        $result = $this->collectionBuilder()
            ->taskReplaceInFile($devSettingsPath)
            ->from('/sites/development.services.yml')
            ->to("/sites/fe.development.services.yml")
            ->taskReplaceInFile($devSettingsPath)
            ->from('# $settings[\'cache\'][\'bins\'][\'render\']')
            ->to('$settings[\'cache\'][\'bins\'][\'render\']')
            ->taskReplaceInFile($devSettingsPath)
            ->from('# $settings[\'cache\'][\'bins\'][\'dynamic_page_cache\'] = ')
            ->to('$settings[\'cache\'][\'bins\'][\'dynamic_page_cache\'] = ')
            ->taskReplaceInFile($devSettingsPath)
            ->from('# $settings[\'cache\'][\'bins\'][\'page\'] = ')
            ->to('$settings[\'cache\'][\'bins\'][\'page\'] = ')
            ->run();
        return $result;
    }

    /**
     * Disable front-end development mode.
     *
     * @param string $siteDir
     *   The Drupal site directory name.
     * @param array $opts
     *   The options.
     *
     * @option boolean $yes Default answers to yes.
     * @aliases fedd
     *
     * @return \Robo\Result
     *   The result of the set of tasks.
     */
    public function frontendDevDisable($siteDir = 'default', array $opts = ['yes|y' => false])
    {
        $devSettingsPath = "$this->drupalRoot/sites/$siteDir/settings.local.php";
        if (!$opts['yes']) {
            $this->yell("This command will overwrite any customizations you have made to $devSettingsPath and
                $this->devServicesPath.");
            $yes = $this->io()->confirm('This command is destructive. Do you wish to continue?');
            if (!$yes) {
                return Result::cancelled();
            }
        }

        $this->io()->title('disabling front-end development mode.');
        return $this->collectionBuilder()
            ->taskFilesystemStack()
            ->remove($devSettingsPath)
            ->remove($this->devServicesPath)
            ->run();
    }
}