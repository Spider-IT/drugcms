<?php
/**
 * Project:
 * Contenido Content Management System
 *
 * Description:
 * Contenido daabase, session and authentication classes
 *
 * Requirements:
 * @con_php_req 5
 *
 * @package    Contenido core
 * @version    1.7
 * @author     Boris Erdmann, Kristian Koehntopp
 * @copyright  four for business AG <www.4fb.de>
 * @license    http://www.contenido.org/license/LIZENZ.txt
 * @link       http://www.4fb.de
 * @link       http://www.contenido.org
 * 
 *
 * {@internal
 *   created  2000-01-01
 *   modified 2008-07-04, bilal arslan, added security fix
 *   modified 2010-02-02, Ingo van Peeren, added local method connect() in order
 *                                         to allow only one database connection, see [CON-300]
 *   modified 2010-02-17, Ingo van Peeren, only one connection for mysqli too
 *   modified 2011-03-03, Murat Purc, some redesign/improvements (partial adaption to PHP 5)
 *   modified 2011-03-18, Murat Purc, Fixed occuring "Duplicated entry" errors by using CT_Sql, see [CON-370]
 *   modified 2011-03-21, Murat Purc, added Contenido_CT_Session to uses PHP's session implementation
 *
 *   $Id$:
 * }}
 *
 */

if (!defined('CON_FRAMEWORK')) {
    die('Illegal call');
}


class DB_Contenido extends DB_Sql
{
    /**
     * Constructor of database class.
     *
     * @param  array  $options  Optional assoziative options. The value depends
     *                          on used DBMS, but is generally as follows:
     *                          - $options['connection']['host']  (string) Hostname  or ip
     *                          - $options['connection']['database']  (string) Database name
     *                          - $options['connection']['user']  (string) User name
     *                          - $options['connection']['password']  (string)  User password
     *                          - $options['nolock']  (bool)  Optional, not lock table
     *                          - $options['sequenceTable']  (string)  Optional, sequesnce table
     *                          - $options['haltBehavior']  (string)  Optional, halt behavior on occured errors
     *                          - $options['haltMsgPrefix']  (string)  Optional, Text to prepend to the halt message
     *                          - $options['enableProfiling']  (bool)  Optional, flag to enable profiling
     * @return  void
     */
    public function __construct(array $options = array())
    {
        global $cachemeta;

        parent::__construct($options);

        if (!is_array($cachemeta)) {
            $cachemeta = array();
        }

        // TODO check this out
        // HerrB: Checked and disabled. Kills umlauts, if tables are latin1_general.

        // try to use the new connection and get the needed encryption
        //$this->query("SET NAMES 'utf8'");
    }


    /**
     * Fetches the next recordset from result set
     *
     * @param  bool
     */
    public function next_record()
    {
        global $cCurrentModule;
        // FIXME  For what reason is NoRecord used???
        $this->NoRecord = false;
        if (!$this->Query_ID) {
            $this->NoRecord = true;
            if ($cCurrentModule > 0) {
                $this->halt("next_record called with no query pending in Module ID $cCurrentModule.");
            } else {
                $this->halt("next_record called with no query pending.");
            }
            return false;
        }

        return parent::next_record();
    }


    /**
     * Returns the metada of passed table
     *
     * @param   string  $sTable  The tablename of empty string to retrieve metadata of all tables!
     * @return  array|bool   Assoziative metadata array (result depends on used db driver)
     *                       or false in case of an error
     * @deprecated  Use db drivers toArray() method instead
     */
    public function copyResultToArray($sTable = '')
    {
        global $cachemeta;

        $aValues = array();

        if ($sTable != '') {
            if (array_key_exists($sTable, $cachemeta)) {
                $aMetadata = $cachemeta[$sTable];
            } else {
                $cachemeta[$sTable] = $this->metadata($sTable);
                $aMetadata = $cachemeta[$sTable];
            }
        } else {
            $aMetadata = $this->metadata($sTable);
        }

        if (!is_array($aMetadata) || count($aMetadata) == 0) {
            return false;
        }

        foreach ($aMetadata as $entry) {
            $aValues[$entry['name']] = $this->f($entry['name']);
        }

        return $aValues;
    }
}


class Contenido_CT_Sql extends CT_Sql
{
    /**
     * Database class name
     * @var  string
     */
    public $database_class = 'DB_Contenido';

    /**
     * And find our session data in this table.
     * @var  string
     */
    public $database_table = '';

    public function __construct()
    {
        global $cfg;
        $this->database_table = $cfg['tab']['phplib_active_sessions'];
    }

    /**
     * Stores the session data in database table.
     *
     * Overwrites parents and uses MySQLs REPLACE statement, to prevent race 
     * conditions while executing INSERT statements by multiple frames in backend.
     *
     * - Existing entry will be overwritten
     * - Non existing entry will be added
     *
     * @param   string  $id    The session id (hash)
     * @param   string  $name  Name of the session
     * @param   string  $str   The value to store
     * @return  bool
     */
    public function ac_store($id, $name, $str)
    {
        switch ($this->encoding_mode) {
            case 'slashes':
                $str = addslashes($name . ':' . $str);
            break;
            case 'base64':
            default:
                $str = base64_encode($name . ':' . $str);
        }

        $name = addslashes($name);
        $now  = date('YmdHis', time());

        $iquery = sprintf(
            "REPLACE INTO %s (sid, name, val, changed) VALUES ('%s', '%s', '%s', '%s')",
            $this->database_table, $id, $name, $str, $now
        );

        return ($this->db->query($iquery)) ? true : false;
    }
}


/**
 * Implements the interface class for storing session data to disk using file
 * session container of phplib.
 */
class Contenido_CT_File extends CT_File
{
    /**
     * The maximum length for one line in session file.
     * @var int
     */
    public $iLineLength = 999999;

    /**
     * Overrides standard constructor for setting up file path to the one which is
     * configured in php.ini
     *
     * @return Contenido_CT_File
     *
     * @author Holger Librenz <holger.librenz@4fb.de>
     */
    public function __construct()
    {
        global $cfg;

        if (isset($cfg['session_line_length']) && !empty($cfg['session_line_length'])) {
            $this->iLineLength = (int) $cfg['session_line_length'];
        }

        // get php.ini value for session path
        $this->file_path = session_save_path() . '/';
    }

    /**
     * Overrides get method, because standard byte count is not really senseful for
     * contenido!
     *
     * @param   string  $sId
     * @param   string  $sName
     * @return  mixed
     */
    public function ac_get_value($sId, $sName)
    {
        if (file_exists($this->file_path . "$sId$sName")) {
            $f = fopen($this->file_path . "$sId$sName", 'r');
            if ($f<0) {
                return '';
            }

            $s = fgets($f, $this->iLineLength);
            fclose($f);

            return urldecode($s);
        } else {
            return '';
        }
    }
}

class Contenido_CT_Shm extends CT_Shm
{
    public function __construct()
    {
        $this->ac_start();
    }
}


/**
 * Contenido session container, uses PHP's session implementation.
 *
 * NOTE: Is experimental, so don't use this in a production environment.
 *
 * To use this, set session container in drugcms/includes/config.misc.php to
 * $cfg["session_container"] = 'session';
 *
 * @todo  Make session container configurable
 *
 * @author  Murat Purc <murat@purc.de>
 */
class Contenido_CT_Session extends CT_Session
{
    public function __construct()
    {
        $this->ac_start(array(
            'namespace'                        => 'contenido_ct_session_ns',
            'session.hash_function'            => '1', // use sha-1 function
            'session.hash_bits_per_character'  => '5', // and set 5 character to achieve 32 chars
#            'session.save_path'                => 'your path',
#            'session.name'                     => 'your session name',
#            'session.gc_maxlifetime'           => 'your lifetime in seconds',
        ));
    }
}

class Contenido_Session extends Session
{
    public $classname          = 'Contenido_Session';
    public $cookiename         = 'contenido';        ## defaults to classname
    public $magic              = '123Hocuspocus';    ## ID seed
    public $mode               = 'get';              ## We propagate session IDs with cookies
    public $fallback_mode      = 'cookie';
    public $lifetime           = 0;                  ## 0 = do session cookies, else minutes
    public $that_class         = 'Contenido_CT_Sql'; ## name of data storage container
    public $gc_probability     = 5;

    public function __construct()
    {
        global $cfg;

        $sFallback = 'sql';
        $sClassPrefix = 'Contenido_CT_';

        $sStorageContainer = strtolower($cfg['session_container']);

        if (class_exists ($sClassPrefix . ucfirst($sStorageContainer))) {
            $sClass = $sClassPrefix . ucfirst($sStorageContainer);
        } else {
            $sClass = $sClassPrefix . ucfirst($sFallback);
        }

        $this->that_class = $sClass;
    }

    public function delete()
    {
        $oCol = new InUseCollection();
        $oCol->removeSessionMarks($this->id);
        parent::delete();
    }
}


class Contenido_Frontend_Session extends Session
{
    public $classname      = 'Contenido_Frontend_Session';
    public $cookiename     = 'sid';              ## defaults to classname
    public $magic          = 'Phillipip';        ## ID seed
    public $mode           = 'cookie';           ## We propagate session IDs with cookies
    public $fallback_mode  = 'cookie';
    public $lifetime       = 0;                  ## 0 = do session cookies, else minutes
    public $that_class     = 'Contenido_CT_Sql'; ## name of data storage container
    public $gc_probability = 5;

    public function __construct()
    {
        global $load_lang, $load_client, $cfg;

        $this->cookiename = 'sid_' . $load_client . '_' . $load_lang;

        $this->setExpires(time() + (60 * $cfg['frontend']['timeout']));

        // added 2007-10-11, H. Librenz
        // bugfix (found by dodger77): we need alternative session containers
        //                             also in frontend
        $sFallback = 'sql';
        $sClassPrefix = 'Contenido_CT_';

        $sStorageContainer = strtolower($cfg['session_container']);

        if (class_exists($sClassPrefix . ucfirst($sStorageContainer))) {
            $sClass = $sClassPrefix . ucfirst($sStorageContainer);
        } else {
            $sClass = $sClassPrefix . ucfirst($sFallback);
        }

        $this->that_class = $sClass;
    }
}

class Contenido_Auth extends Auth
{
    public $classname      = 'Contenido_Auth';
    public $lifetime       =  60;
    public $database_class = 'DB_Contenido';
    public $database_table = 'con_phplib_auth_user';

    public function auth_loginform()
    {
        global $sess, $_PHPLIB;
        include($_PHPLIB['libdir'] . 'loginform.ihtml');
    }

    public function auth_validatelogin()
    {
        global $username, $password;

        if ($password == '') {
            return false;
        }

        if (isset($username)) {
            $this->auth['uname'] = $username;     ## This provides access for 'loginform.ihtml'
        } elseif ($this->nobody){                      ##  provides for 'default login cancel'
            $uid = $this->auth['uname'] = $this->auth['uid'] = 'nobody';
            return $uid;
        }
        $uid = false;

        $this->db->query(
            sprintf("SELECT user_id, perms FROM %s WHERE username = '%s' AND password = '%s'",
            $this->database_table, addslashes($username), addslashes($password))
        );

        while ($this->db->next_record()) {
            $uid = $this->db->f('user_id');
            $this->auth['perm'] = $this->db->f('perms');
        }
        return $uid;
    }
}


class Contenido_Default_Auth extends Contenido_Auth
{
    public $classname = 'Contenido_Default_Auth';
    public $lifetime  =  1;
    public $nobody    = true;

    public function auth_loginform()
    {
        global $sess, $_PHPLIB;
        include($_PHPLIB['libdir'] . 'defloginform.ihtml');
    }
}


class Contenido_Challenge_Auth extends Auth
{
    public $classname      = 'Contenido_Challenge_Auth';
    public $lifetime       =  1;
    public $magic          = 'Simsalabim';  ## Challenge seed
    public $database_class = 'DB_Contenido';
    public $database_table = 'con_phplib_auth_user';

    public function auth_loginform()
    {
        global $sess, $challenge, $_PHPLIB;

        $challenge = md5(uniqid($this->magic));
        $sess->register('challenge');

        include($_PHPLIB['libdir'] . 'crloginform.ihtml');
    }

    public function auth_validatelogin()
    {
        global $username, $password, $challenge, $response, $timestamp;

        if ($password == '') {
            return false;
        }

        if (isset($username)) {
            // This provides access for 'loginform.ihtml'
            $this->auth['uname'] = $username;
        }

        // Sanity check: If the user presses 'reload', don't allow a login with the data
        // again. Instead, prompt again.
        if ($timestamp < (time() - 60 * 15)) {
            return false;
        }
        $this->db->query(
            sprintf("SELECT user_id, perms, password FROM %s WHERE username = '%s'",
            $this->database_table, addslashes($username))
        );

        while ($this->db->next_record()) {
            $uid   = $this->db->f('user_id');
            $perm  = $this->db->f('perms');
            $pass  = $this->db->f('password');
        }
        $exspected_response = md5("$username:$pass:$challenge");

        // True when JS is disabled
        if ($response == '') {
            if ($password != $pass) {
                return false;
            } else {
                $this->auth['perm'] = $perm;
                return $uid;
            }
        }

        // Response is set, JS is enabled
        if ($exspected_response != $response) {
            return false;
        } else {
            $this->auth['perm'] = $perm;
            return $uid;
        }
    }
}

##
## Contenido_Challenge_Crypt_Auth: Keep passwords in md5 hashes rather
##                           than cleartext in database
## Author: Jim Zajkowski <jim@jimz.com>

class Contenido_Challenge_Crypt_Auth extends Auth
{
    public $classname      = 'Contenido_Challenge_Crypt_Auth';
    public $lifetime       =  60;
    public $magic          = 'Frrobo123xxica';  ## Challenge seed
    public $database_class = 'DB_Contenido';
    public $database_table = '';
    public $group_table    = '';
    public $member_table   = '';

    public function __construct()
    {
        global $cfg;
        $this->database_table = $cfg['tab']['phplib_auth_user_md5'];
        $this->group_table = $cfg['tab']['groups'];
        $this->member_table = $cfg['tab']['groupmembers'];
        $this->lifetime = $cfg['backend']['timeout'];

        if ($this->lifetime == 0) {
            $this->lifetime = 60;
        }
    }

    public function auth_loginform()
    {
        global $sess, $challenge, $_PHPLIB, $cfg;

        $challenge = md5(uniqid($this->magic));
        $sess->register('challenge');

        include($cfg['path']['contenido'] . 'main.loginform.php');
    }

    public function auth_loglogin($uid)
    {
        global $cfg, $client, $lang, $auth, $sess, $saveLoginTime;

        $perm      = new Contenido_Perm();
        $timestamp = date('Y-m-d H:i:s');
        $idcatart  = '0';

        /* Find the first accessible client and language for the user */
        // All the needed information should be available in clients_lang - but the previous code was designed with a
        // reference to the clients table. Maybe fail-safe technology, who knows...
        $sql = 'SELECT tblClientsLang.idclient, tblClientsLang.idlang FROM ' .
            $cfg['tab']['clients'] . ' AS tblClients, ' . $cfg['tab']['clients_lang'] . ' AS tblClientsLang ' .
            'WHERE tblClients.idclient = tblClientsLang.idclient ORDER BY idclient ASC, idlang ASC';
        $this->db->query($sql);

        $bFound = false;
        while ($this->db->next_record() && !$bFound) {
            $iTmpClient = $this->db->f('idclient');
            $iTmpLang   = $this->db->f('idlang');

            if ($perm->have_perm_client_lang($iTmpClient, $iTmpLang)) {
                $client = $iTmpClient;
                $lang   = $iTmpLang;
                $bFound = true;
            }
        }

        if (isset($idcat) && isset($idart)) {
            //            SECURITY FIX
            $sql = "SELECT idcatart
                    FROM
                        ". $cfg['tab']['cat_art'] ."
                    WHERE
                        idcat = '".Contenido_Security::toInteger($idcat)."' AND
                        idart = '".Contenido_Security::toInteger($idart)."'";

            $this->db->query($sql);
            $this->db->next_record();
            $idcatart = $this->db->f('idcatart');
        }

        if (!is_numeric($client) || !is_numeric($lang)) {
            return;
        }

        $idaction   = $perm->getIDForAction('login');
        $lastentry  = $this->db->nextid($cfg['tab']['actionlog']);

        $sql = "INSERT INTO
            ". $cfg['tab']['actionlog']."
        SET
            idlog = $lastentry,
            user_id = '" . $uid . "',
            idclient = '".Contenido_Security::toInteger($client)."',
            idlang = '".Contenido_Security::toInteger($lang)."',
            idaction = $idaction,
            idcatart = $idcatart,
            logtimestamp = '$timestamp'";

        $this->db->query($sql);
        $sess->register('saveLoginTime');
        $saveLoginTime = true;
    }

    public function auth_validatelogin()
    {
        global $username, $password, $challenge, $response, $formtimestamp, $auth_handlers;

        $gperm = array();

        if ($password == '') {
            return false;
        }

        if (($formtimestamp + (60*15)) < time()) {
            return false;
        }

        if (isset($username)) {
            $this->auth['uname'] = $username;     ## This provides access for 'loginform.ihtml'
        } elseif ($this->nobody) {                      ##  provides for 'default login cancel'
            $uid = $this->auth['uname'] = $this->auth['uid'] = 'nobody';
            return $uid;
        }

        $uid  = false;
        $perm = false;
        $pass = false;

        $sDate = date('Y-m-d');

        $this->db->query(sprintf("SELECT user_id, perms, password FROM %s WHERE username = '%s' AND
            (valid_from <= '".$sDate."' OR valid_from = '0000-00-00' OR valid_from is NULL) AND
            (valid_to >= '".$sDate."' OR valid_to = '0000-00-00' OR valid_to is NULL)",
            $this->database_table,
            Contenido_Security::escapeDB($username, $this->db)
        ));

        $sMaintenanceMode = getSystemProperty('maintenance', 'mode');
        while($this->db->next_record()) {
            $uid   = $this->db->f('user_id');
            $perm  = $this->db->f('perms');
            $pass  = $this->db->f('password');   ## Password is stored as a md5 hash

            $bInMaintenance = false;
            if ($sMaintenanceMode == 'enabled') {
                #sysadmins are allowed to login every time
                if (!preg_match('/sysadmin/', $perm)) {
                    $bInMaintenance = true;
                }
            }

            if ($bInMaintenance) {
                unset($uid);
                unset($perm);
                unset($pass);
            }

            if (is_array($auth_handlers) && !$bInMaintenance) {
                if (array_key_exists($pass, $auth_handlers)) {
                    $success = call_user_func($auth_handlers[$pass], $username, $password);

                    if ($success) {
                        $uid = md5($username);
                        $pass = md5($password);
                    }
                }
            }
        }

        if ($uid == false) {
            ## No user found, sleep and exit
            sleep(5);
            return false;
        } else {
            $this->db->query(sprintf("SELECT a.group_id AS group_id, a.perms AS perms ".
                "FROM %s AS a, %s AS b WHERE a.group_id = b.group_id AND b.user_id = '%s'",
                $this->group_table,
                $this->member_table,
                $uid
            ));

            if ($perm != '') {
                $gperm[] = $perm;
            }

            while ($this->db->next_record()) {
                $gperm[] = $this->db->f('perms');
            }

            if (is_array($gperm)) {
                $perm = implode(',',$gperm);
            }

            if ($response == '') {                    ## True when JS is disabled
                if (md5($password) != $pass) {       ## md5 hash for non-JavaScript browsers
                    sleep(5);
                    return false;
                } else {
                    $this->auth['perm'] = $perm;
                    $this->auth_loglogin($uid);
                    return $uid;
                }
            }

            $expected_response = md5("$username:$pass:$challenge");

            if ($expected_response != $response) {   ## Response is set, JS is enabled
                sleep(5);
                return false;
            } else {
                $this->auth['perm'] = $perm;
                $this->auth_loglogin($uid);
                return $uid;
            }
        }
    }
}

class Contenido_Frontend_Challenge_Crypt_Auth extends Auth
{
    public $classname      = 'Contenido_Frontend_Challenge_Crypt_Auth';
    public $lifetime       =  60;
    public $magic          = 'Frrobo123xxica';  ## Challenge seed
    public $database_class = 'DB_Contenido';
    public $database_table = '';
    public $fe_database_table = '';
    public $group_table    = '';
    public $member_table   = '';
    public $nobody         = true;

    public function __construct()
    {
        global $cfg;
        $this->database_table = $cfg['tab']['phplib_auth_user_md5'];
        $this->fe_database_table = $cfg['tab']['frontendusers'];
        $this->group_table = $cfg['tab']['groups'];
        $this->member_table = $cfg['tab']['groupmembers'];
        $this->lifetime = $cfg['frontend']['timeout'];

        if ($this->lifetime == 0) {
            $this->lifetime = 60;
        }
    }

    public function auth_preauth()
    {
        global $password;

        if ($password == '') {
            /* Stay as nobody when an empty password is passed */
            $uid = $this->auth['uname'] = $this->auth['uid'] = 'nobody';
            return false;
        }

        return $this->auth_validatelogin();
    }

    public function auth_loginform()
    {
        global $sess, $challenge, $_PHPLIB, $client, $cfgClient;

        $challenge = md5(uniqid($this->magic));
        $sess->register('challenge');

        include($cfgClient[$client]['path']['frontend'].'front_crcloginform.inc.php');
    }

    public function auth_validatelogin()
    {
        global $username, $password, $challenge, $response, $auth_handlers, $client;

        $client = (int)$client;

        if(isset($username)) {
            $this->auth['uname'] = $username;     ## This provides access for 'loginform.ihtml'
        } else if ($this->nobody) {                      ##  provides for 'default login cancel'
            $uid = $this->auth['uname'] = $this->auth['uid'] = 'nobody';
            return $uid;
        }

        $uid = false;

        /* Authentification via frontend users */
        $this->db->query(sprintf("SELECT idfrontenduser, password FROM %s WHERE username = '%s' AND idclient='$client' AND active='1'",
            $this->fe_database_table,
            Contenido_Security::escapeDB(urlencode($username), $this->db)
        ));

        if ($this->db->next_record()) {
            $uid  = $this->db->f('idfrontenduser');
            $perm = 'frontend';
            $pass = $this->db->f('password');
        }

        if ($uid == false) {
            /* Authentification via backend users */
            $this->db->query(sprintf("SELECT user_id, perms, password FROM %s WHERE username = '%s'",
            $this->database_table,
            Contenido_Security::escapeDB($username, $this->db) ));

            while($this->db->next_record()) {
                $uid   = $this->db->f('user_id');
                $perm  = $this->db->f('perms');
                $pass  = $this->db->f('password');   ## Password is stored as a md5 hash

                if (is_array($auth_handlers)) {
                    if (array_key_exists($pass, $auth_handlers)) {
                        $success = call_user_func($auth_handlers[$pass], $username, $password);
                        if ($success) {
                            $uid  = md5($username);
                            $pass = md5($password);
                        }
                    }
                }
            }

            if ($uid !== false) {
                $this->db->query(sprintf("SELECT a.group_id AS group_id, a.perms AS perms ".
                    "FROM %s AS a, %s AS b WHERE a.group_id = b.group_id AND ".
                    "b.user_id = '%s'",
                    $this->group_table,
                    $this->member_table,
                    $uid
                ));

                /* Deactivated: Backend user would be sysadmin when logged on as frontend user
                *  (and perms would be checked), see http://www.contenido.org/forum/viewtopic.php?p=85666#85666
                $perm = 'sysadmin'; */
                if ($perm != '') {
                    $gperm[] = $perm;
                }

                while ($this->db->next_record()) {
                    $gperm[] = $this->db->f('perms');
                }

                if (is_array($gperm)) {
                    $perm = implode(',',$gperm);
                }
            }
        }

        if ($uid == false) {
            ## User not found, sleep and exit
            sleep(5);
            return false;
        } else {
            if ($response == '') {                   ## True when JS is disabled
                if (md5($password) != $pass) {       ## md5 hash for non-JavaScript browsers
                    sleep(5);
                    return false;
                } else {
                    $this->auth['perm'] = $perm;
                    return $uid;
                }
            }

            $expected_response = md5("$username:$pass:$challenge");
            if ($expected_response != $response) {   ## Response is set, JS is enabled
                sleep(5);
                return false;
            } else {
                $this->auth['perm'] = $perm;
                return $uid;
            }
        }
    }
}

/**
 * Registers an external auth handler
 */
function register_auth_handler($aHandlers)
{
    global $auth_handlers;

    if (!is_array($auth_handlers)) {
        $auth_handlers = array();
    }

    if (!is_array($aHandlers)) {
        $aHandlers = Array($aHandlers);
    }

    foreach ($aHandlers as $sHandler) {
        if (!in_array($sHandler, $auth_handlers)) {
            $auth_handlers[md5($sHandler)] = $sHandler;
        }
    }
}

?>