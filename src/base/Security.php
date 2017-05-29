<?php
namespace PSFS\base;

use PSFS\base\types\traits\SecureTrait;
use PSFS\base\types\traits\SingletonTrait;

/**
 * Class Security
 * @package PSFS
 */
class Security
{
    // sha1('user')
    const USER_ID_TOKEN = '12dea96fec20593566ab75692c9949596833adc9';
    // sha1('admin')
    const MANAGER_ID_TOKEN = 'd033e22ae348aeb5660fc2140aec35850c4da997';
    // sha1('superadmin')
    const ADMIN_ID_TOKEN = '889a3a791b3875cfae413574b53da4bb8a90d53e';
    // sha1('FLASHES')
    const FLASH_MESSAGE_TOKEN = '4680c68435db1bfbf17c3fcc4f7b39d2c6122504';

    use SecureTrait;
    use SingletonTrait;
    /**
     * @var array $user
     */
    private $user = null;

    /**
     * @var array $admin
     */
    private $admin = null;

    /**
     * @var bool $authorized
     */
    private $authorized = FALSE;

    /**
     * @var bool $checked
     */
    private $checked = false;

    /**
     * @var array $session
     */
    protected $session;

    /**
     * Constructor por defecto
     */
    public function __construct()
    {
        $this->initSession();
        $this->session = (is_null($_SESSION)) ? array() : $_SESSION;
        if (NULL === $this->getSessionKey('__FLASH_CLEAR__')) {
            $this->clearFlashes();
            $this->setSessionKey('__FLASH_CLEAR__', microtime(TRUE));
        }
        $this->user = (array_key_exists(self::USER_ID_TOKEN, $this->session)) ? unserialize($this->session[self::USER_ID_TOKEN]) : NULL;
        $this->admin = (array_key_exists(self::ADMIN_ID_TOKEN, $this->session)) ? unserialize($this->session[self::ADMIN_ID_TOKEN]) : NULL;
        if (null === $this->admin) {
            $this->checkAdmin();
        }
    }

    /**
     * Initializator for SESSION
     */
    private function initSession() {
        if (PHP_SESSION_NONE === session_status() && !headers_sent()) {
            session_start();
        }
        // Fix for phpunits
        if(!isset($_SESSION)) {
            $_SESSION = [];
        }
    }

    /**
     * Método estático que devuelve los perfiles de la plataforma
     * @return array
     */
    public static function getProfiles()
    {
        return array(
            self::ADMIN_ID_TOKEN => _('Administrador'),
            self::MANAGER_ID_TOKEN => _('Gestor'),
            self::USER_ID_TOKEN => _('Usuario'),
        );
    }

    /**
     * Method that returns all the available profiles
     * @return array
     */
    public function getAdminProfiles()
    {
        return static::getProfiles();
    }

    /**
     * Método estático que devuelve los perfiles disponibles
     * @return array
     */
    public static function getCleanProfiles()
    {
        return array(
            '__SUPER_ADMIN__' => self::ADMIN_ID_TOKEN,
            '__ADMIN__' => self::MANAGER_ID_TOKEN,
            '__USER__' => self::USER_ID_TOKEN,
        );
    }

    /**
     * Método estático que devuelve los perfiles disponibles
     * @return array
     */
    public function getAdminCleanProfiles()
    {
        return static::getCleanProfiles();
    }

    /**
     * Método que guarda los administradores
     *
     * @param array $user
     *
     * @return bool
     */
    public static function save($user)
    {
        $saved = true;
        try {
            $admins = Cache::getInstance()->getDataFromFile(CONFIG_DIR . DIRECTORY_SEPARATOR . 'admins.json', Cache::JSONGZ, true) ?: [];
            $admins[$user['username']]['hash'] = sha1($user['username'] . $user['password']);
            $admins[$user['username']]['profile'] = $user['profile'];

            Cache::getInstance()->storeData(CONFIG_DIR . DIRECTORY_SEPARATOR . 'admins.json', $admins, Cache::JSONGZ, true);
        } catch(\Exception $e) {
            Logger::log($e->getMessage(), LOG_ERR);
            $saved = false;
        }
        return $saved;
    }

    /**
     * Method to save a new admin user
     * @param array $user
     * @return bool
     */
    public function saveUser($user)
    {
        $saved = false;
        if (!empty($user)) {
            $saved = static::save($user);
        }
        return $saved;
    }

    /**
     * Servicio que actualiza los datos del usuario
     *
     * @param $user
     */
    public function updateUser($user)
    {
        $this->user = $user;
    }

    /**
     * Método que devuelve los administradores de una plataforma
     * @return array|null
     */
    public function getAdmins()
    {
        return Cache::getInstance()->getDataFromFile(CONFIG_DIR . DIRECTORY_SEPARATOR . 'admins.json', Cache::JSONGZ, true);
    }

    /**
     * Método que devuelve si un usuario tiene privilegios para acceder a la zona de administración
     *
     * @param null $user
     * @param null $pass
     * @param boolean $force
     *
     * @return bool
     * @throws \HttpException
     */
    public function checkAdmin($user = NULL, $pass = NULL, $force = false)
    {
        Logger::log('Checking admin session');
        if ((!$this->authorized && !$this->checked) || $force) {
            $admins = $this->getAdmins();
            if (null !== $admins) {
                $request = Request::getInstance();
                //Sacamos las credenciales de la petición
                $user = $user ?: $request->getServer('PHP_AUTH_USER');
                $pass = $pass ?: $request->getServer('PHP_AUTH_PW');
                if (NULL === $user || (array_key_exists($user, $admins) && empty($admins[$user]))) {
                    list($user, $pass) = $this->getAdminFromCookie();
                }
                if (!empty($user) && !empty($admins[$user])) {
                    $auth = $admins[$user]['hash'];
                    $this->authorized = ($auth == sha1($user . $pass));
                    if ($this->authorized) {
                        $this->admin = array(
                            'alias' => $user,
                            'profile' => $admins[$user]['profile'],
                        );
                        $this->setSessionKey(self::ADMIN_ID_TOKEN, serialize($this->admin));
                    }
                }
                $this->checked = true;
            }
        }

        return $this->authorized;
    }

    /**
     * Método que obtiene el usuario y contraseña de la cookie de sesión de administración
     * @return array
     */
    protected function getAdminFromCookie()
    {
        $auth_cookie = Request::getInstance()->getCookie($this->getHash());
        $user = $pass = array();
        if (!empty($auth_cookie)) {
            list($user, $pass) = explode(':', base64_decode($auth_cookie));
        }

        return array($user, $pass);
    }

    /**
     * Método privado para la generación del hash de la cookie de administración
     * @return string
     */
    public function getHash()
    {
        return substr(self::MANAGER_ID_TOKEN, 0, 8);
    }

    /**
     * Método que devuelve el usuario logado
     * @return array
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Método que devuelve el usuario administrador logado
     * @return array
     */
    public function getAdmin()
    {
        return $this->admin;
    }

    /**
     * Método que calcula si se está logado o para acceder a administración
     * @return bool
     */
    public function canAccessRestrictedAdmin()
    {
        return null !== $this->admin && !preg_match('/^\/admin\/login/i', Request::requestUri());
    }

    /**
     * Servicio que devuelve una pantalla de error porque se necesita estar authenticado
     *
     * @param string|null $route
     *
     * @return string|null
     */
    public function notAuthorized($route)
    {
        return Template::getInstance()->render('notauthorized.html.twig', array(
            'route' => $route,
        ));
    }

    /**
     * Servicio que chequea si un usuario es super administrador o no
     * @return bool
     */
    public function isSuperAdmin()
    {
        $users = $this->getAdmins();
        $logged = $this->getAdmin();
        if ($users[$logged['alias']]) {
            $security = $users[$logged['alias']]['profile'];
            return self::ADMIN_ID_TOKEN === $security;
        }

        return FALSE;
    }

    /**
     * Servicio que devuelve un dato de sesión
     *
     * @param string $key
     *
     * @return mixed
     */
    public function getSessionKey($key)
    {
        $data = NULL;
        if (array_key_exists($key, $this->session)) {
            $data = $this->session[$key];
        }

        return $data;
    }

    /**
     * Servicio que setea una variable de sesión
     *
     * @param string $key
     * @param mixed $data
     *
     * @return Security
     */
    public function setSessionKey($key, $data = NULL)
    {
        $this->session[$key] = $data;

        return $this;
    }

    /**
     * Servicio que devuelve los mensajes flash de sesiones
     * @return mixed
     */
    public function getFlashes()
    {
        $flashes = $this->getSessionKey(self::FLASH_MESSAGE_TOKEN);

        return (NULL !== $flashes) ? $flashes : array();
    }

    /**
     * Servicio que limpia los mensajes flash
     * @return $this
     */
    public function clearFlashes()
    {
        $this->setSessionKey(self::FLASH_MESSAGE_TOKEN, NULL);

        return $this;
    }

    /**
     * Servicio que inserta un flash en sesión
     *
     * @param string $key
     * @param mixed $data
     */
    public function setFlash($key, $data = NULL)
    {
        $flashes = $this->getFlashes();
        if (!is_array($flashes)) {
            $flashes = array();
        }
        $flashes[$key] = $data;
        $this->setSessionKey(self::FLASH_MESSAGE_TOKEN, $flashes);
    }

    /**
     * Servicio que devuelve un flash de sesión
     *
     * @param string $key
     *
     * @return mixed
     */
    public function getFlash($key)
    {
        $flashes = $this->getFlashes();

        return (NULL !== $key && array_key_exists($key, $flashes)) ? $flashes[$key] : NULL;
    }

    /**
     * Servicio que actualiza
     *
     * @param boolean $closeSession
     *
     * @return Security
     */
    public function updateSession($closeSession = FALSE)
    {
        Logger::log('Update session');
        $_SESSION = $this->session;
        $_SESSION[self::USER_ID_TOKEN] = serialize($this->user);
        $_SESSION[self::ADMIN_ID_TOKEN] = serialize($this->admin);
        if ($closeSession) {
            Logger::log('Close session');
            @session_write_close();
            @session_start();
        }
        Logger::log('Session updated');
        return $this;
    }

    /**
     * Servicio que limpia la sesión
     */
    public function closeSession()
    {
        unset($_SESSION);
        @session_destroy();
        @session_regenerate_id(TRUE);
        @session_start();
    }

}
