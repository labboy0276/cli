<?php
use \Terminus\User;
use \Terminus\Utils;
use \Terminus\Auth;
use \Terminus\SiteFactory;
use \Terminus\Organization;
use \Terminus\Helpers\Input;
use \Guzzle\Http\Client;
use \Terminus\Loggers\Regular as Logger;

/**
 * Show information for your Pantheon organizations
 *
 */
class Organizations_Command extends Terminus_Command {

  public function __construct() {
    parent::__construct();
  }

  /**
   * Show a list of your organizations on Pantheon
   *
   * @subcommand list
   *
   */
  public function all($args, $assoc_args) {
     $user = new User();
     $data = array();
     foreach ( $user->organizations() as $org_id => $org) {
       $data[] = array(
         'name' => $org->name,
         'id' => $org_id,
       );
     }

     $this->handleDisplay($data);
  }

  /**
   * List an organizations sites
   *
   * ## OPTIONS
   *
   * [--org=<org>]
   * : Organization name or Id
   *
   * [--tag=<tag>]
   * : Tag name to filter sites list by
   *
   * [--add=<site>]
   * : Site to add to organization
   *
   * [--remove=<site>]
   * : Site to remove from organization
   *
   * @subcommand sites
   *
   */
  public function sites($args, $assoc_args) {
    $orgs = array();
    $user = new User();

    foreach ($user->organizations() as $id => $org) {
      $orgs[$id] = $org->name;
    }

    if (!isset($assoc_args['org']) OR empty($assoc_args['org'])) {
      $selected_org = Terminus::menu($orgs,false,"Choose an organization");
    } else {
      $selected_org = $assoc_args['org'];
    }

    $org = new Organization($selected_org);

    if (isset($assoc_args['add'])) {
        $add = SiteFactory::instance(Input::sitename($assoc_args,'add'));
        Terminus::confirm("Are you sure you want to add %s to %s ?", $assoc_args, array($add->getName(), $org->name));
        $org->addSite($add);
        Terminus::success("Added site!");
        return true;
    }

    if (isset($assoc_args['remove'])) {
      $remove = SiteFactory::instance(Input::sitename($assoc_args,'remove'));
      Terminus::confirm("Are you sure you want to remove %s to %s ?", $assoc_args, array($remove->getName(), $org->name));
      $org->removeSite($remove);
      Terminus::success("Removed site!");
      return true;
    }

    $sites = $org->getSites();
    $data = array();
    foreach ($sites as $site) {
      if (isset($assoc_args['tag']) && !(in_array($assoc_args['tag'], $site->tags))) {
        continue;
      }
      $data[] = array(
        'name' => $site->site->name,
        'id' => $site->site->id,
        'service_level' => isset($site->site->service_level) ? $site->site->service_level : '',
        'framework' => isset($site->site->framework) ? $site->site->framework : '',
        'created' => date('Y-m-d H:i:s', $site->site->created),
        'tags' => $site->tags
      );
    }
    $this->handleDisplay($data);
  }

}
Terminus::add_command( 'organizations', 'Organizations_Command' );
