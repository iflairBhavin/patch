<?php
/**
 * Copyright © 2017 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\MagentoCloud\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\MagentoCloud\Environment;

/**
 * CLI command for deploy hook. Responsible for installing/updating/configuring Magento
 */
class Deploy extends Command
{
    const MAGIC_ROUTE = '{default}';

    const PREFIX_SECURE = 'https://';
    const PREFIX_UNSECURE = 'http://';

    const GIT_MASTER_BRANCH_RE = '/^master(?:-[a-z0-9]+)?$/i';

    const MAGENTO_PRODUCTION_MODE = 'production';
    const MAGENTO_DEVELOPER_MODE = 'developer';

    private $urls = ['unsecure' => [], 'secure' => []];

    private $defaultCurrency = 'USD';

    private $amqpHost;
    private $amqpPort;
    private $amqpUser;
    private $amqpPasswd;
    private $amqpVirtualhost = '/';
    private $amqpSsl = '';

    private $dbHost;
    private $dbName;
    private $dbUser;
    private $dbPassword;

    private $adminUsername;
    private $adminFirstname;
    private $adminLastname;
    private $adminEmail;
    private $adminPassword;
    private $adminUrl;
    private $enableUpdateUrls;

    private $redisHost;
    private $redisPort;
    private $redisSessionDb = '0';
    private $redisCacheDb = '1'; // Value hard-coded in pre-deploy.php

    private $isMasterBranch = null;
    private $magentoApplicationMode;
    private $cleanStaticViewFiles;
    private $staticDeployThreads;
    private $staticDeployExcludeThemes = [];
    private $adminLocale;
    private $doDeployStaticContent;

    private $verbosityLevel;

    /**
     * @var Environment
     */
    private $env;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('magento-cloud:deploy')
            ->setDescription('Deploy an instance of Magento on the Magento Cloud');
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->env = new Environment();
        $this->deploy();
    }

    /**
     * Deploy application: copy writable directories back, install or update Magento data.
     */
    private function deploy()
    {
        if (file_exists(Environment::PRE_DEPLOY_FLAG)) {
            $this->env->log("Error: pre-deploy flag still exists. This means pre-deploy hook did not execute "
                    . "successfully. Aborting the rest of the deploy hook! Flag is located at: "
                    . realpath(Environment::PRE_DEPLOY_FLAG)
                );
            $this->env->log("Enabling Maintenance mode.");
            //not relying on bin/magento in case it is broken due to some reason.
            $this->env->execute('touch ' . realpath(Environment::MAGENTO_ROOT . 'var') . '.maintenance.flag' );
            throw new \RuntimeException("Predeploy flag still exists!");
        }
        $this->env->log($this->env->startingMessage("deploy"));
        $this->saveEnvironmentData();

        if (!$this->isInstalled()) {
            $this->installMagento();
        } else {
            $this->updateMagento();
        }
        $this->processMagentoMode();
        $this->disableGoogleAnalytics();
        $this->cleanupOldAssets();
        $this->env->log("Deployment complete.");
    }

    /**
     * Parse and save information about environment configuration and variables.
     */
    private function saveEnvironmentData()
    {
        $this->env->log("Preparing environment specific data.");

        $this->initRoutes();

        $relationships = $this->env->getRelationships();
        $var = $this->env->getVariables();

        $this->dbHost = $relationships["database"][0]["host"];
        $this->dbName = $relationships["database"][0]["path"];
        $this->dbUser = $relationships["database"][0]["username"];
        $this->dbPassword = $relationships["database"][0]["password"];

        $this->adminUsername = isset($var["ADMIN_USERNAME"]) ? $var["ADMIN_USERNAME"] : "admin";
        $this->adminFirstname = isset($var["ADMIN_FIRSTNAME"]) ? $var["ADMIN_FIRSTNAME"] : "John";
        $this->adminLastname = isset($var["ADMIN_LASTNAME"]) ? $var["ADMIN_LASTNAME"] : "Doe";
        $this->adminEmail = isset($var["ADMIN_EMAIL"]) ? $var["ADMIN_EMAIL"] : "john@example.com";
        $this->adminPassword = isset($var["ADMIN_PASSWORD"]) ? $var["ADMIN_PASSWORD"] : "admin12";
        $this->adminUrl = isset($var["ADMIN_URL"]) ? $var["ADMIN_URL"] : "admin";
        $this->enableUpdateUrls = isset($var["UPDATE_URLS"]) && $var["UPDATE_URLS"] == 'disabled' ? false : true;

        $this->cleanStaticViewFiles = isset($var["CLEAN_STATIC_FILES"]) && $var["CLEAN_STATIC_FILES"] == 'disabled' ? false : true;
        $this->staticDeployExcludeThemes = isset($var["STATIC_CONTENT_EXCLUDE_THEMES"])
            ? $var["STATIC_CONTENT_EXCLUDE_THEMES"]     : [];
        $this->adminLocale = isset($var["ADMIN_LOCALE"]) ? $var["ADMIN_LOCALE"] : "en_US";

        if (isset($var["STATIC_CONTENT_THREADS"])) {
            $this->staticDeployThreads = (int)$var["STATIC_CONTENT_THREADS"];
        } else if (isset($_ENV["STATIC_CONTENT_THREADS"])) {
            $this->staticDeployThreads = (int)$_ENV["STATIC_CONTENT_THREADS"];
        } else if (isset($_ENV["MAGENTO_CLOUD_MODE"]) && $_ENV["MAGENTO_CLOUD_MODE"] === 'enterprise') {
            $this->staticDeployThreads = 3;
        } else { // if Paas environment
            $this->staticDeployThreads = 1;
        }
        $this->doDeployStaticContent = isset($var["DO_DEPLOY_STATIC_CONTENT"]) && $var["DO_DEPLOY_STATIC_CONTENT"] == 'disabled' ? false : true;
        // Can use environment variable to always disable. Default is to deploy static content if it was not deployed in the build step.
        if (isset($var["DO_DEPLOY_STATIC_CONTENT"]) && $var["DO_DEPLOY_STATIC_CONTENT"] == 'disabled') {
            $this->doDeployStaticContent = false;
            $this->env->log(' Flag DO_DEPLOY_STATIC_CONTENT is set to disabled');
        } else {
            $this->doDeployStaticContent = !$this->env->isStaticDeployInBuild();
            $this->env->log(' Flag DO_DEPLOY_STATIC_CONTENT is set to ' . $this->doDeployStaticContent);
        }

        $this->magentoApplicationMode = isset($var["APPLICATION_MODE"]) ? $var["APPLICATION_MODE"] : false;
        $this->magentoApplicationMode =
            in_array($this->magentoApplicationMode, array(self::MAGENTO_DEVELOPER_MODE, self::MAGENTO_PRODUCTION_MODE))
                ? $this->magentoApplicationMode
                : self::MAGENTO_PRODUCTION_MODE;

        if (isset($relationships['redis']) && count($relationships['redis']) > 0) {
            $this->redisHost = $relationships['redis'][0]['host'];
            $this->redisPort = $relationships['redis'][0]['port'];
        }

        $this->setAmqpData($relationships);

        $this->verbosityLevel = isset($var['VERBOSE_COMMANDS']) && $var['VERBOSE_COMMANDS'] == 'enabled' ? ' -vvv ' : '';
    }

    /**
     * Verifies is Magento installed based on install date in env.php
     *
     * @return bool
     */
    public function isInstalled()
    {
        $configFile = 'app/etc/env.php';
        $installed = false;

        //1. from environment variables check if db exists and has tables
        //2. check if core_config_data and setup_module tables exist
        //3. check install date

        $this->env->log('Checking if db exists and has tables');
        $output = $this->executeDbQuery('SHOW TABLES');
        if (is_array($output) && count($output) > 1) {
            if (!in_array('core_config_data', $output) || !in_array('setup_module', $output)) {
                $this->env->log('Missing either core_config_data or setup_module table');
                exit(5);
            } elseif (file_exists($configFile)) {
                $data = include $configFile;
                if (isset($data['install']) && isset($data['install']['date'])) {
                    $this->env->log("Magento was installed on " . $data['install']['date']);
                    $installed = true;
                } else {
                    $config['install']['date'] = date('r');
                    $updatedConfig = '<?php' . "\n" . 'return ' . var_export($config, true) . ';';
                    file_put_contents($configFile, $updatedConfig);
                    $installed = true;
                }
            } else {
                $this->env->execute('touch ' . Environment::MAGENTO_ROOT . $configFile);
                $config['install']['date'] = date('r');
                $updatedConfig = '<?php' . "\n" . 'return ' . var_export($config, true) . ';';
                file_put_contents($configFile, $updatedConfig);
                $installed = true;
            }
        }
        return $installed;
    }

    /**
     * Run Magento installation
     */
    public function installMagento()
    {
        $this->env->log("Installing Magento.");

        $urlUnsecure = $this->urls['unsecure'][''];
        $urlSecure = $this->urls['secure'][''];

        $command =
            "php ./bin/magento setup:install \
            --session-save=db \
            --cleanup-database \
            --currency=$this->defaultCurrency \
            --base-url=$urlUnsecure \
            --base-url-secure=$urlSecure \
            --use-secure=1 \
            --use-secure-admin=1 \
            --language=$this->adminLocale \
            --timezone=America/Los_Angeles \
            --db-host=$this->dbHost \
            --db-name=$this->dbName \
            --db-user=$this->dbUser \
            --backend-frontname=$this->adminUrl \
            --admin-user=$this->adminUsername \
            --admin-firstname=$this->adminFirstname \
            --admin-lastname=$this->adminLastname \
            --admin-email=$this->adminEmail \
            --admin-password=$this->adminPassword";

        if (strlen($this->dbPassword)) {
            $command .= " \
            --db-password=$this->dbPassword";
        }

        $command .= $this->verbosityLevel;

        $this->env->execute($command);
        $this->updateConfig();
    }


    /**
     * Update Magento configuration
     */
    private function updateMagento()
    {
        $this->env->log("File env.php contains installation date. Updating configuration.");
        $this->updateConfig();
        $this->setupUpgrade();
        $this->clearCache();
    }

    private function updateConfig()
    {
        $this->env->log("Updating configuration from environment variables.");
        $this->updateEnvironmentConfiguration();
        $this->updateSharedConfiguration();
        $this->updateAdminCredentials();
        $this->updateUrls();
    }

    /**
     * Update admin credentials
     */
    private function updateAdminCredentials()
    {
        $this->env->log("Updating admin credentials.");

        $this->executeDbQuery("update admin_user set firstname = '$this->adminFirstname', lastname = '$this->adminLastname', email = '$this->adminEmail', username = '$this->adminUsername', password='{$this->generatePassword($this->adminPassword)}' where user_id = '1';");
    }

    /**
     * Returns SOLR configuration
     *
     * @param array $config Solr connection configuration
     * @return array
     */
    private function getSolrConfiguration(array $config)
    {
        $this->env->log("Updating SOLR configuration.");
        return [
            'engine' => 'solr',
            'solr_server_hostname' => $config['host'],
            'solr_server_port' => $config['port'],
            'solr_server_username' => $config['scheme'],
            'solr_server_path' => $config['path'],
        ];
    }

    /**
     * Returns ElasticSearch configuration
     *
     * @param array $config Elasticsearch connection configuration
     * @return array
     */
    private function getElasticSearchConfiguration(array $config)
    {
        $this->env->log("Updating elasticsearch configuration.");
        return [
            'engine' => 'elasticsearch',
            'elasticsearch_server_hostname' => $config['host'],
            'elasticsearch_server_port' => $config['port'],
        ];
    }

    /**
     * Returns search engine configuration depends on relationships
     *
     * @return array
     */
    private function getSearchEngineConfiguration()
    {
        $relationships = $this->env->getRelationships();
        if (isset($relationships['elasticsearch'])) {
            $searchConfig = $this->getElasticSearchConfiguration($relationships['elasticsearch'][0]);
        } else if (isset($relationships['solr'])) {
            $searchConfig = $this->getSolrConfiguration($relationships['solr'][0]);
        } else {
            $searchConfig = ['engine' => 'mysql'];
        }
        return $searchConfig;
    }

    /**
     * Update secure and unsecure URLs
     */
    private function updateUrls()
    {
        if ($this->enableUpdateUrls) {
            $this->env->log("Updating secure and unsecure URLs.");
            foreach ($this->urls as $urlType => $urls) {
                foreach ($urls as $route => $url) {
                    $prefix = 'unsecure' === $urlType ? self::PREFIX_UNSECURE : self::PREFIX_SECURE;
                    if (!strlen($route)) {
                        $this->executeDbQuery("update core_config_data set value = '$url' where path = 'web/$urlType/base_url' and scope_id = '0';");
                        continue;
                    }
                    $likeKey = $prefix . $route . '%';
                    $likeKeyParsed = $prefix . str_replace('.', '---', $route) . '%';
                    $this->executeDbQuery("update core_config_data set value = '$url' where path = 'web/$urlType/base_url' and (value like '$likeKey' or value like '$likeKeyParsed');");
                }
            }
            // The following config values contain the domain names and are reset to default values on deploy.
            // We recommend using environment variables to set these per environment and override the values in the db.
            $this->executeDbQuery("DELETE FROM core_config_data WHERE path = 'web/cookie/cookie_domain';");
            $this->executeDbQuery("DELETE FROM core_config_data WHERE path = 'admin/url/use_custom';");
            $this->executeDbQuery("DELETE FROM core_config_data WHERE path = 'admin/url/custom';");

        } else {
            $this->env->log("Skipping URL updates");
        }
    }


    /**
     * Run Magento setup upgrade
     */
    private function setupUpgrade()
    {
        $this->env->log("Saving disabled modules.");
        $configFile = 'app/etc/config.php';
        $disabledModules = [];
        if (file_exists($configFile)) {
            $this->env->execute("cp -f app/etc/config.php app/etc/config.php.bak");
            $moduleData = include $configFile;
            $disabledModules = array_filter($moduleData['modules'], function ($v){return $v == 0;});
        }
        $this->env->log("Enabling all modules");
        $this->env->execute("php ./bin/magento module:enable --all");

        if (file_exists(Environment::REGENERATE_FLAG)) {
            $this->env->log("Removing .regenerate flag");
            unlink(Environment::REGENERATE_FLAG);
        }

        try {
            /* Enable maintenance mode */
            $this->env->log("Enabling Maintenance mode.");
            $this->env->execute("php ./bin/magento maintenance:enable {$this->verbosityLevel}");

            $this->env->log("Running setup upgrade.");
            $this->env->execute("php ./bin/magento setup:upgrade --keep-generated {$this->verbosityLevel}");

            /* Disable maintenance mode */
            $this->env->execute("php ./bin/magento maintenance:disable {$this->verbosityLevel}");
            $this->env->log("Maintenance mode is disabled.");

        }catch (\RuntimeException $e) {
            if (file_exists($configFile . '.bak')) {
                $this->env->log("Rollback config.php");
                $this->env->execute("cp -f app/etc/config.php.bak app/etc/config.php");
            } else {
                $this->env->log("No backup config file to perform rollback");
            }
            $this->env->log($e->getMessage());
            //Rollback required by database
            exit(6);
        }
        if (count($disabledModules) > 0) {
            $this->env->execute("php ./bin/magento module:disable  -f " . implode(' ' ,array_keys($disabledModules)));
        }
        if (file_exists(Environment::REGENERATE_FLAG)) {
            $this->env->log("Removing .regenerate flag");
            unlink(Environment::REGENERATE_FLAG);
        }
        if (file_exists($configFile . '.bak')) {
            $this->env->log("Deleting backup file");
            $this->env->execute("rm app/etc/config.php.bak");
        }
    }

    /**
     * Clear Magento file based cache
     */
    private function clearCache()
    {
        $this->env->log("Clearing application cache.");

        $this->env->execute(
            "php ./bin/magento cache:flush {$this->verbosityLevel}"
        );
    }

    /**
     * Update env.php file content
     */
    private function updateEnvironmentConfiguration()
    {
        $this->env->log("Updating env.php database configuration.");

        $configFileName = "app/etc/env.php";

        $config = include $configFileName;

        $config['db']['connection']['default']['username'] = $this->dbUser;
        $config['db']['connection']['default']['host'] = $this->dbHost;
        $config['db']['connection']['default']['dbname'] = $this->dbName;
        $config['db']['connection']['default']['password'] = $this->dbPassword;

        $config['db']['connection']['indexer']['username'] = $this->dbUser;
        $config['db']['connection']['indexer']['host'] = $this->dbHost;
        $config['db']['connection']['indexer']['dbname'] = $this->dbName;
        $config['db']['connection']['indexer']['password'] = $this->dbPassword;

        if ($this->amqpHost !== null && $this->amqpPort !== null
            && $this->amqpUser !== null && $this->amqpPasswd !== null) {
            $config['queue']['amqp']['host'] = $this->amqpHost;
            $config['queue']['amqp']['port'] = $this->amqpPort;
            $config['queue']['amqp']['user'] = $this->amqpUser;
            $config['queue']['amqp']['password'] = $this->amqpPasswd;
            $config['queue']['amqp']['virtualhost'] = $this->amqpVirtualhost;
            $config['queue']['amqp']['ssl'] = $this->amqpSsl;
        } else {
            $config = $this->removeAmqpConfig($config);
        }

        if ($this->redisHost !== null && $this->redisPort !== null) {
            $this->env->log("Updating env.php Redis cache configuration.");
            if (empty($config['cache'])) {
                $config['cache'] = $this->getRedisCacheConfiguration();
            } else {
                $config['cache'] = array_replace_recursive($config['cache'], $this->getRedisCacheConfiguration());
            }
            $config['session']['save'] = "redis";
            $config['session']['redis']['host'] = $this->redisHost;
            $config['session']['redis']['port'] = $this->redisPort;
            $config['session']['redis']['database'] = $this->redisSessionDb;
        }
        $config['backend']['frontName'] = $this->adminUrl;

        $config['resource']['default_setup']['connection'] = 'default';

        $updatedConfig = '<?php'  . "\n" . 'return ' . var_export($config, true) . ';';

        file_put_contents($configFileName, $updatedConfig);
    }

    /**
     * Update config.php content
     */
    private function updateSharedConfiguration()
    {
        $configFileName = "app/etc/config.php";
        $this->env->log("Updating {$configFileName} configuration.");
        $config = include $configFileName;

        // MAGECLOUD-1317 avoid key collision on system between env.php and config.local.php
        $config['system']['default']['catalog']['search'] = array_replace_recursive(
            $config['system']['default']['catalog']['search'],
            $this->getSearchEngineConfiguration()
        );
        $updatedConfig = '<?php'  . "\n" . 'return ' . var_export($config, true) . ';';
        file_put_contents($configFileName, $updatedConfig);
    }

    /**
    * Remove AMQP configuration from env.php
    *
    * @param array $config
    * @return array
    */
    private function removeAmqpConfig(array $config)
    {
        $this->env->log("Removing AMQP configuration from env.php.");
        if (isset($config['queue']['amqp'])) {
            if (count($config['queue']) > 1) {
                unset($config['queue']['amqp']);
            } else {
                unset($config['queue']);
            }
        }

        return $config;
    }

    /**
     * Generates admin password using default Magento settings
     */
    private function generatePassword($password)
    {
        $saltLenght = 32;
        $charsLowers = 'abcdefghijklmnopqrstuvwxyz';
        $charsUppers = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charsDigits = '0123456789';
        $randomStr = '';
        $chars = $charsLowers . $charsUppers . $charsDigits;

        // use openssl lib
        for ($i = 0, $lc = strlen($chars) - 1; $i < $saltLenght; $i++) {
            $bytes = openssl_random_pseudo_bytes(PHP_INT_SIZE);
            $hex = bin2hex($bytes); // hex() doubles the length of the string
            $rand = abs(hexdec($hex) % $lc); // random integer from 0 to $lc
            $randomStr .= $chars[$rand]; // random character in $chars
        }
        $salt = $randomStr;
        $version = 1;
        $hash = hash('sha256', $salt . $password);

        return implode(
            ':',
            [
                $hash,
                $salt,
                $version
            ]
        );
    }

    /**
     * If current deploy is about master branch
     *
     * @return boolean
     */
    private function isMasterBranch()
    {
        if (is_null($this->isMasterBranch)) {
            if (isset($_ENV["MAGENTO_CLOUD_ENVIRONMENT"]) && preg_match(self::GIT_MASTER_BRANCH_RE, $_ENV["MAGENTO_CLOUD_ENVIRONMENT"])) {
                $this->isMasterBranch = true;
            } else {
                $this->isMasterBranch = false;
            }
        }
        return $this->isMasterBranch;
    }

    /**
     * If branch is not master then disable Google Analytics
     */
    private function disableGoogleAnalytics()
    {
        if (!$this->isMasterBranch()) {
            $this->env->log("Disabling Google Analytics");
            $this->executeDbQuery("update core_config_data set value = 0 where path = 'google/analytics/active';");
        }
    }

    /**
     * Executes database query
     *
     * @param string $query
     * $query must be completed, finished with semicolon (;)
     * @return mixed
     */
    private function executeDbQuery($query)
    {
        $password = strlen($this->dbPassword) ? sprintf('-p%s', $this->dbPassword) : '';
        return $this->env->execute("mysql -u $this->dbUser -h $this->dbHost -e \"$query\" $password $this->dbName");
    }


    /**
     * Based on variable APPLICATION_MODE. Production mode by default
     */
    private function processMagentoMode()
    {
        $this->env->log("Set Magento application mode to '{$this->magentoApplicationMode}'");

        /* Enable application mode */
        if ($this->magentoApplicationMode == self::MAGENTO_PRODUCTION_MODE) {
            /* Workaround for MAGETWO-58594: disable redis cache before running static deploy, re-enable after */
            if ($this->doDeployStaticContent) {
                $this->deployStaticContent();
            }

            $this->env->log("Enable production mode");
            $configFileName = "app/etc/env.php";
            $config = include $configFileName;
            $config['MAGE_MODE'] = 'production';
            $updatedConfig = '<?php'  . "\n" . 'return ' . var_export($config, true) . ';';
            file_put_contents($configFileName, $updatedConfig);
        } else {
            $this->env->log("Enable developer mode");
            $this->env->execute(
                "php ./bin/magento deploy:mode:set " . self::MAGENTO_DEVELOPER_MODE . $this->verbosityLevel
            );
        }
    }

    private function deployStaticContent()
    {
        // Clear old static content if necessary
        if ($this->cleanStaticViewFiles) {
            $this->env->removeStaticContent();
        }
        $this->env->log("Generating fresh static content");
        $this->generateFreshStaticContent();
    }

    private function generateFreshStaticContent()
    {
        $this->env->execute('touch ' . Environment::MAGENTO_ROOT . 'pub/static/deployed_version.txt');
        /* Enable maintenance mode */
        $this->env->log("Enabling Maintenance mode.");
        $this->env->execute("php ./bin/magento maintenance:enable {$this->verbosityLevel}");

        /* Generate static assets */
        $this->env->log("Extract locales");

        $excludeThemesOptions = '';
        if ($this->staticDeployExcludeThemes) {
            $themes = preg_split("/[,]+/", $this->staticDeployExcludeThemes);
            if (count($themes) > 1) {
                $excludeThemesOptions = "--exclude-theme=" . implode(' --exclude-theme=', $themes);
            } elseif (count($themes) === 1){
                $excludeThemesOptions = "--exclude-theme=" .  $themes[0];
            }
        }

        $jobsOption = $this->staticDeployThreads
            ? "--jobs={$this->staticDeployThreads}"
            : '';

        $locales = implode(' ', $this->getLocales());
        $logMessage = $locales ? "Generating static content for locales: $locales" : "Generating static content.";
        $this->env->log($logMessage);

        $this->env->execute(
            "php ./bin/magento setup:static-content:deploy $jobsOption $excludeThemesOptions $locales {$this->verbosityLevel}"
        );

        /* Disable maintenance mode */
        $this->env->execute("php ./bin/magento maintenance:disable {$this->verbosityLevel}");
        $this->env->log("Maintenance mode is disabled.");
    }

    /**
     * Gets locales from DB which are set to stores and admin users.
     * Adds additional default 'en_US' locale to result, if it does't exist yet in defined list.
     *
     * @return array List of locales. Returns empty array in case when no locales are defined in DB
     * ```php
     * [
     *     'en_US',
     *     'fr_FR'
     * ]
     * ```
     */
    private function getLocales()
    {
        $locales = [];

        $query = 'SELECT value FROM core_config_data WHERE path=\'general/locale/code\' '
            . 'UNION SELECT interface_locale FROM admin_user;';
        $output = $this->executeDbQuery($query);

        if (is_array($output) && count($output) > 1) {
            //first element should be shifted as it is the name of column
            array_shift($output);
            $locales = $output;

            if (!in_array($this->adminLocale, $locales)) {
                $locales[] = $this->adminLocale;
            }
        }
        return $locales;
    }

    /**
     * Parse MagentoCloud routes to more readable format.
     */
    private function initRoutes()
    {
        $this->env->log("Initializing routes.");

        $routes = $this->env->getRoutes();

        foreach($routes as $key => $val) {
            if ($val["type"] !== "upstream") {
                continue;
            }

            $urlParts = parse_url($val['original_url']);
            $originalUrl = str_replace(self::MAGIC_ROUTE, '', $urlParts['host']);

            if(strpos($key, self::PREFIX_UNSECURE) === 0) {
                $this->urls['unsecure'][$originalUrl] = $key;
                continue;
            }

            if(strpos($key, self::PREFIX_SECURE) === 0) {
                $this->urls['secure'][$originalUrl] = $key;
                continue;
            }
        }

        if (!count($this->urls['secure'])) {
            $this->urls['secure'] = $this->urls['unsecure'];
        }

        $this->env->log(sprintf("Routes: %s", var_export($this->urls, true)));
    }

    private function getRedisCacheConfiguration()
    {
        return [
            'frontend' => [
                'default' => [
                    'backend' => 'Cm_Cache_Backend_Redis',
                    'backend_options' => [
                        'server' => $this->redisHost,
                        'port' => $this->redisPort,
                        'database' => $this->redisCacheDb
                    ]
                ],
                'page_cache' => [
                    'backend' => 'Cm_Cache_Backend_Redis',
                    'backend_options' => [
                        'server' => $this->redisHost,
                        'port' => $this->redisPort,
                        'database' => $this->redisCacheDb
                    ]
                ]
            ]
        ];
    }

    /**
     * Clean up any "old" assets that were atomically moved out of place, by deleting them in the background
     */
    private function cleanupOldAssets()
    {
        $this->env->log("Removing old generated code in the background");
        // Must match filename of old generated assets directory in pre-deploy.php
        $this->env->backgroundExecute("rm -rf " . realpath(Environment::MAGENTO_ROOT . 'var') . '/generation_old_*');
        // Remove the flag to clean up for next deploy
        $this->env->setStaticDeployInBuild(false);
    }

    /**
     * Set amqp configuration if it exists for one of possible amqp relationship names,
     * amqp relationship can have different name on different environment.
     *
     * @param array $relationships
     */
    private function setAmqpData(array $relationships)
    {
        $possibleRelationshipNames = ['rabbitmq', 'mq', 'amqp'];

        foreach ($possibleRelationshipNames as $relationshipName) {
            if (isset($relationships[$relationshipName]) && count($relationships[$relationshipName]) > 0) {
                $amqpConfig = $relationships[$relationshipName][0];
                $this->amqpHost = $amqpConfig['host'];
                $this->amqpUser = $amqpConfig['username'];
                $this->amqpPasswd = $amqpConfig['password'];
                $this->amqpPort = $amqpConfig['port'];
                if (isset($amqpConfig['vhost'])) {
                    $this->amqpVirtualhost = $amqpConfig['vhost'];
                }
                return;
            }
        }
    }
}
