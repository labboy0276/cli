<?php
namespace Terminus;
use \ReflectionClass;
use \Terminus\Request;
use \Terminus\Collections\Bindings;

class Environment {
  public $id;
  public $attributes;
  public $bindings;

  public $name = 'dev';
  public $site = false;
  public $diffstat;
  public $dns_zone;
  public $environment_created;
  public $lock;
  public $on_server_development;
  public $randseed;
  public $styx_cluster;
  public $target_commit;
  public $target_ref;
  public $watchers;
  public $backups;

  public function __construct(Site $site, $data = null) {
    $this->site = $site;
    if (property_exists($data, 'id')) {
      $this->name = $this->id = $data->id;
    }
    $this->attributes = $data;

    $this->bindings = new Bindings(array('environment' => $this));

    if (is_object($data)) {
      // if we receive an environment object from the api hydrate the vars
      $environment_properties = get_object_vars($data);
      // iterate our local properties setting them where available in the imported object
      foreach (get_object_vars($this) as $key => $value) {
        if(array_key_exists($key,$environment_properties)) {
          $this->$key = $environment_properties[$key];
        }
      }
    }
  }

  public function wipe() {
    $path = sprintf("environments/%s/wipe", $this->name);
    return \Terminus_Command::request('sites', $this->site->getId(), $path, 'POST');
  }

  public function diffstat() {
    $path = sprintf('environments/%s/on-server-development/diffstat', $this->name);
    $data = \Terminus_Command::request('sites', $this->site->getId(), $path, 'GET');
    return $data['data'];
  }


  /**
   * Create a backup
   *
   * @param $args
  **/
  public function createBackup($args) {
    $type = 'backup';
    if (array_key_exists('type',$args)) {
      $type = $args['type'];
    }

    $ttl = 86400*365;
    if (array_key_exists('keep-for', $args)) {
      $ttl = 86400 * (int) $args['keep-for'];
    }

    switch ($args['element']) {
      case 'db':
        $args['database'] = true;
        break;
      case 'code':
        $args['code'] = true;
        break;
      case 'files':
        $args['files'] = true;
        break;
      case 'all':
        $args['files'] = true;
        $args['code'] = true;
        $args['database'] = true;
        break;
    }

    $params = array(
      'entry_type' => $type,
      'code' => isset($args['code']),
      'database' => isset($args['database']),
      'files' => isset($args['files']),
      'ttl' => $ttl
    );

    $workflow = $this->site->workflows->create('do_export', array(
      'environment' => $this->id,
      'params' => $params
    ));
    $workflow->wait();

    return $workflow;
  }

  /**
   * @param null $element string -- code, file, db
   * @param bool $latest_only
   * @return array
   */
  public function backups($element = null, $latest_only = false) {
    if (null === $this->backups) {
      $path = sprintf("environments/%s/backups/catalog", $this->name);
      $response = \Terminus_Command::request('sites', $this->site->getId(), $path, 'GET');
      $this->backups = $response['data'];
    }
    $backups = (array) $this->backups;
    ksort($backups);
    if ($element) {
      $element = $this->element_as_database($element);
      foreach ($this->backups as $id => $backup) {
        if (!isset($backup->filename)) {
          unset($backups[$id]);
          continue;
        }
        if (!preg_match("#.*$element\.\w+\.gz$#", $backup->filename)) {
          unset($backups[$id]);
          continue;
        }
      }
    }
    if ($latest_only) {
      return array(array_pop($backups));
    }
    return $backups;
  }

  /**
   * @param $bucket string -- backup folder
   * @param $element string -- files,code,database
   */
  public function backupUrl($bucket, $element) {
    $element = $this->element_as_database($element);
    $path = sprintf("environments/%s/backups/catalog/%s/%s/s3token", $this->name, $bucket, $element);
    $data = array('method'=>'GET');
    $options = array('body'=>json_encode($data), 'headers'=> array('Content-type'=>'application/json') );
    $response = \Terminus_Command::request('sites', $this->site->getId(), $path, 'POST', $options);
    return $response['data'];
  }

  /**
   * Start a work flow
   * @param $workflow string work flow "slot"
   */
  public function workflow($workflow) {
    $path = sprintf("environments/%s/workflows", $this->name);
    $data = array(
      'type' => $workflow,
      'environment' => $this->name,
    );
    $options = array('body'=>json_encode($data), 'headers'=> array('Content-type'=>'application/json'));
    $response = \Terminus_Command::request('sites', $this->site->getId(), $path, 'POST', $options);

    return $response['data'];
  }

  /**
  * OnServer Dev Handler
  *
  * @param $value string optional -- git or sftp, connection mode to set
  * @param $commit string optional -- should be the commit message to use if
  * committing on server changes
  */
  public function onServerDev($value = null, $commit = null ) {
    $path = sprintf("environments/%s/on-server-development", $this->name);
    if ($commit) {
      $path = sprintf("%s/commit", $path);
      $data = ($commit) ? array('message' => $commit, 'user' => Session::getValue('user_uuid')) : NULL;
      $options = array('body'=>json_encode($data), 'headers'=> array('Content-type'=>'application/json'));
      $data = \Terminus_Command::request('sites', $this->site->getId(), $path, 'POST', $options);
    } else {
      if (null == $value) {
        $data = \Terminus_Command::request('sites', $this->site->getId(), $path, 'GET');
      } else {
        $enabled = ($value == 'sftp') ? true : false;
        $data = array(
          'enabled' => $enabled,
        );
        $options = array('body'=>json_encode($data), 'headers'=> array('Content-type'=>'application/json'));
        $data = \Terminus_Command::request('sites', $this->site->getId(), $path, 'PUT', $options);
      }
    }

    if (empty($data)) {
      return false;
    }
    return $data['data'];
  }

  /**
   * Get the code log
   */
  public function log() {
    $path = sprintf("environments/%s/code-log",$this->name);
    $response = \Terminus_Command::request('sites', $this->site->getId(), $path, 'GET');
    return $response['data'];
  }

  /**
   * Enable HTTP Basic Access authentication on the web environment
   */
  public function lock($options = array()) {
    $username = $options['username'];
    $password = $options['password'];

    $workflow = $this->site->workflows->create('lock_environment', array(
      'environment' => $this->id,
      'params' => array(
        'username' => $username,
        'password' => $password
      )
    ));
    return $workflow;
  }

  /**
   * Disable HTTP Basic Access authentication on the web environment
   */
  public function unlock() {
    $workflow = $this->site->workflows->create('unlock_environment', array(
      'environment' => $this->id,
    ));
    return $workflow;
  }

  /**
   * Get Info on an environment lock
   */
  public function lockinfo() {
    $info = $this->attributes->lock;
    return $info;
  }

  /**
   * list hotnames for environment
   */
  public function hostnames() {
    $response = \Terminus_Command::request("sites", $this->site->getId(), 'environments/' . $this->name . '/hostnames', 'GET');
    return $response['data'];
  }

  /**
   * Add hostname to environment
   */
  public function hostnameadd($hostname) {
    $response = \Terminus_Command::request("sites", $this->site->getId(), 'environments/' . $this->name . '/hostnames/' . rawurlencode($hostname), "PUT");
    return $response['data'];
  }

  /**
   * Delete hostname from environment
   */
  public function hostnamedelete($hostname) {
    $response = \Terminus_Command::request("sites", $this->site->getId(), 'environments/' . $this->name . '/hostnames/' . rawurlencode($hostname), "DELETE");
    return $response['data'];
  }

  /**
   * Generate environment URL
   */
  public function domain() {
    $host = sprintf( "%s-%s.%s", $this->name, $this->site->getName(), $this->dns_zone );
    return $host;
  }

  /**
   * creates a new environment
   *
  */
  public function create($env_name) {
    $path = sprintf('environments/%s', $env_name);
    $OPTIONS = array(
      'headers'=> array('Content-type'=>'application/json')
    );
    $response = \Terminus_Command::request('sites', $site_id, $path, 'POST', $OPTIONS);
    return $response['data'];
  }

  /**
   * "Wake" a site
   */
  public function wake() {
    $hostnames = $this->hostnames();
    $target = key($hostnames);
    $response = Request::send( "http://$target/pantheon_healthcheck", 'GET');
    $return_data = array(
      'success'  => $response->isSuccessful(),
      'time' => $response->getInfo('total_time'),
      'styx' => $response->getHeader('X-Pantheon-Styx-Hostname'),
      'response' => $response,
      'target' => $target,
    );
    return $return_data;
  }

  /**
   * Return the SFTP connection URL for this environment
   */
  public function sftp_url() {
    $username = sprintf("%s.%s", $this->id, $this->site->getId());
    $host = sprintf("appserver.%s.%s.drush.in", $this->id, $this->site->getId());
    $port = 2222;

    return sprintf("sftp://%s@%s:%s", $username, $host, $port);
  }

  /**
   * Return the GIT connection URL for this environment
   */
  public function git_url() {
    $username = sprintf("codeserver.dev.%s", $this->site->getId());
    $host = sprintf("codeserver.dev.%s.drush.in", $this->site->getId());
    $port = 2222;
    return sprintf("git://%s@%s:%s", $username, $host, $port);
  }

  /**
   * Returns its argument unless that argument is "db", then returns "database"
   * @param element $string -- Represents the request element
   * @return string
   */
  private function element_as_database($element) {
    if ($element == 'db') {
      return 'database';
    }
    return $element;
  }

  /**
   * Deploys the given environment
   *
   * @param [array] $args Arguments for deployment
   *        [string] $args['from'] Environment from which to deploy
   *        [string] $args['annotation'] Commit message
   * @return [array] Data from the request
   */
  public function deploy($args) {
    $default_params = array(
      'annotation'     => 'Terminus deploy',
      'clone_database' => array('from_environment' => 'dev'),
      'clone_files'    => array('from_environment' => 'dev'),
    );
    $params = array_merge($default_params, $args);

    $workflow = $this->site->workflows->create('deploy', array(
      'environment' => $this->id,
      'params'      => $params
    ));
    return $workflow;
  }

  /**
   * Initializes the Test/Live environments on a newly created Site
   * and clones content from previous environment
   * (e.g. Test clones Dev content, Live clones Test content)
   *
   * @return [Workflow] in-progress workflow
   */
  public function initializeBindings() {
    if ($this->id == 'test') {
      $from_env_id = 'dev';
    } elseif ($this->id == 'live') {
      $from_env_id = 'test';
    }

    $workflow = $this->site->workflows->create('deploy', array(
      'environment' => $this->id,
      'params' => array(
        'annotation' => sprintf('Create the %s environment', $this->id),
        'clone_database' => array('from_environment' => $from_env_id),
        'clone_files' => array('from_environment' => $from_env_id)
      )
    ));
    return $workflow;
  }

  /**
   * Returns the environment's name
   *
   * @return [string] $this->name
   */
  public function getName() {
    return $this->name;
  }
}
