<?php

namespace PSFS\base\config;


use PSFS\base\Cache;
use PSFS\base\exception\ConfigException;
use PSFS\base\Logger;
use PSFS\base\Request;
use PSFS\base\types\SingletonTrait;

/**
 * Class Config
 * @package PSFS\base\config
 */
class Config
{
    use SingletonTrait;
    const DEFAULT_LANGUAGE = "es";
    const DEFAULT_ENCODE = "UTF-8";
    const DEFAULT_CTYPE = "text/html";
    const DEFAULT_DATETIMEZONE = "Europe/Madrid";

    const CONFIG_FILE = 'config.json';

    protected $config = array();
    static public $defaults = array(
        "db_host" => "localhost",
        "db_port" => "3306",
        "default_language" => "es_ES",
        "debug" => true,
        "front.version" => "v1",
        "version" => "v1",
    );
    static public $required = array('db_host', 'db_port', 'db_name', 'db_user', 'db_password', 'home_action', 'default_language', 'debug');
    static public $encrypted = array('db_password');
    static public $optional = [
        'platform_name', // Platform name
        'restricted', // Restrict the web access
        'admin_login', // Enable web login for admin
        'logger.phpFire', // Enable phpFire to trace the logs in the browser
        'logger.memory', // Enable log memory usage un traces
        'poweredBy', // Show PoweredBy header customized
        'author', // Author for auto generated files
        'author_email', // Author email for auto generated files
        'version', // Platform version(for cache purposes)
        'front.version', // Static resources version
        'cors.enabled', // Enable CORS (regex with the domains, * for all)
        'pagination.limit', // Pagination limit for autogenerated api admin
        'api.secret', // Secret passphrase to securize the api
        'api.admin', // Enable de autogenerated api admin(wok)
        'log.level', // Max log level(default INFO)
        'admin_action', // Default admin url when access to /admin
        'cache.var', // Static cache var
        'twig.auto_reload', // Enable or disable auto reload templates for twig
        'modules.extend', // Variable for extending the current functionality
        'psfs.auth', // Variable for extending PSFS with the AUTH module
    ];
    protected $debug = false;

    /**
     * Config Constructor
     */
    public function __construct()
    {
        $this->init();
    }

    /**
     * Method that load the configuration data into the system
     * @return Config
     */
    protected function init()
    {
        if (file_exists(CONFIG_DIR . DIRECTORY_SEPARATOR . self::CONFIG_FILE)) {
            $this->loadConfigData();
        }
        return $this;
    }

    /**
     * Method that saves the configuration
     * @param array $data
     * @param array $extra
     * @return array
     */
    protected static function saveConfigParams(array $data, array $extra)
    {
        Logger::log('Saving required config parameters');
        //En caso de tener parámetros nuevos los guardamos
        if (array_key_exists('label', $extra) && is_array($extra['label'])) {
            foreach ($extra['label'] as $index => $field) {
                if (array_key_exists($index, $extra['value']) && !empty($extra['value'][$index])) {
                    /** @var $data array */
                    $data[$field] = $extra['value'][$index];
                }
            }
        }
        return $data;
    }

    /**
     * Method that saves the extra parameters into the configuration
     * @param array $data
     * @return array
     */
    protected static function saveExtraParams(array $data)
    {
        $final_data = array();
        if (count($data) > 0) {
            Logger::log('Saving extra configuration parameters');
            foreach ($data as $key => $value) {
                if (null !== $value || $value !== '') {
                    $final_data[$key] = $value;
                }
            }
        }
        return $final_data;
    }

    /**
     * Method that returns if the system is in debug mode
     * @return boolean
     */
    public function getDebugMode()
    {
        return $this->debug;
    }

    /**
     * Method that checks if the platform is proper configured
     * @return boolean
     */
    public function isConfigured()
    {
        Logger::log('Checking configuration');
        $configured = (count($this->config) > 0);
        if ($configured) {
            foreach (static::$required as $required) {
                if (!array_key_exists($required, $this->config)) {
                    $configured = false;
                    break;
                }
            }
        }
        return ($configured || $this->checkTryToSaveConfig());
    }

    /**
     * Method that check if the user is trying to save the config
     * @return bool
     */
    public function checkTryToSaveConfig()
    {
        $uri = Request::getInstance()->getRequestUri();
        $method = Request::getInstance()->getMethod();
        return (preg_match('/^\/admin\/(config|setup)$/', $uri) !== false && strtoupper($method) === 'POST');
    }

    /**
     * Method that saves all the configuration in the system
     *
     * @param array $data
     * @param array|null $extra
     * @return boolean
     */
    public static function save(array $data, array $extra = null)
    {
        $data = self::saveConfigParams($data, $extra);
        $final_data = self::saveExtraParams($data);
        $saved = false;
        try {
            $final_data = array_filter($final_data, function($value) {
                return !empty($value);
            });
            Cache::getInstance()->storeData(CONFIG_DIR . DIRECTORY_SEPARATOR . self::CONFIG_FILE, $final_data, Cache::JSON, true);
            Config::getInstance()->loadConfigData();
            $saved = true;
        } catch (ConfigException $e) {
            Logger::log($e->getMessage(), LOG_ERR);
        }
        return $saved;
    }

    /**
     * Method that returns a config value
     * @param string $param
     * @param mixed $defaultValue
     *
     * @return mixed|null
     */
    public function get($param, $defaultValue = null)
    {
        return array_key_exists($param, $this->config) ? $this->config[$param] : $defaultValue;
    }

    /**
     * Method that returns all the configuration
     * @return array
     */
    public function dumpConfig()
    {
        return $this->config ?: [];
    }

    /**
     * Method that reloads config file
     */
    public function loadConfigData()
    {
        $this->config = Cache::getInstance()->getDataFromFile(CONFIG_DIR . DIRECTORY_SEPARATOR . self::CONFIG_FILE,
            Cache::JSON,
            TRUE) ?: [];
        $this->debug = (array_key_exists('debug', $this->config)) ? (bool)$this->config['debug'] : FALSE;
    }

    /**
     * Clear configuration set
     */
    public function clearConfig()
    {
        $this->config = [];
    }

    /**
     * Static wrapper for extracting params
     * @param string $key
     * @param mixed|null $defaultValue
     * @return mixed|null
     */
    public static function getParam($key, $defaultValue = null)
    {
        $param = Config::getInstance()->get($key);
        return (null !== $param) ? $param : $defaultValue;
    }
}
