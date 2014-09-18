<?php
/*
   This file is part of CodevTT

   CodevTT is free software: you can redistribute it and/or modify
   it under the terms of the GNU General Public License as published by
   the Free Software Foundation, either version 3 of the License, or
   (at your option) any later version.

   CodevTT is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   GNU General Public License for more details.

   You should have received a copy of the GNU General Public License
   along with CodevTT.  If not, see <http://www.gnu.org/licenses/>.
*/

/**
 * Dashboards can be inserted in many places, each place beeing defined as a Domain.
 * So a Dashboard is associated to (one and only) domain and can display
 * IndicatorPlugins from (one or more) categories.
 * The Dashboard queries the PluginManager singleton for available Plugins.
 *
 * The dashboard saves Indicator specific settings in the database, as well as
 * it's own settings (collapsed, color, ....).
 * These settings are saved in codevtt_config_table and can be specific to [team,user].
 *
 * A Dashboard must have a unique id depending on its domain/categories in order
 * to save the settings in codevtt_config_table with [id,team,user] as key components.
 * the id should be hardcodded in the page Controler (which will also set domain & cat).
 *
 * the Dashboards has a dialogbox to let the user choose the plugins to be displayed.
 * (simple combobox with pluginCandidates).
 *
 * A Dashboard uses a SAMRTY template file (dashboard.html), and provides some smartyVariables.
 * WARN: There can be several dashboards in a same page
 *
 * include/dashboard_ajax.php will allow to save settings.
 *
 *
 * @author lob
 */
class Dashboard {

   const SETTINGS_DISPLAYED_PLUGINS = 'displayedPlugins'; // 
   const SETTINGS_TITLE = 'title'; // 
   
   private static $logger;

   private $id;
   private $domain;
   private $categories;
   private $userid;
   private $teamid;
   private $settings;

   /**
    * Initialize static variables
    * @static
    */
   public static function staticInit() {
      self::$logger = Logger::getLogger(__CLASS__);
   }

   /**
    * WARN: $id must be unique in ALL CodevTT !
    * the id is hardcoded in the CodevTT pages.
    * 
    * @param type $id
    */
   public function __construct($id) {
      $this->id = $id;
   }

   public function setDomain($domain) {
      $this->domain = $domain;
   }
   public function getDomain() {
      $this->domain;
   }
   public function setCategories($categories) {
      $this->categories = $categories;
   }
   public function getCategories() {
      return $this->categories;
   }
   public function setUserid($userid) {
      $this->userid = $userid;
   }
   public function getUserid() {
      return $this->userid;
   }
   public function setTeamid($teamid) {
      $this->teamid = $teamid;
   }
   public function getTeamid() {
      return $this->teamid;
   }



   /**
    * called by include/dashboard_ajax.php
    * 
    * save dashboard settings for [team, user]
    * 
    * Note: the dashboard can contain the same plugin
    * multiple times, each one having specific attributes.
    * ex: ProgressHistoryIndic for Cmd1, Cmd2, Cmd2 
    * 
    *  settings = array (
    *     'title' => 'dashboard title'
    *     'displayedPlugins' => array(
    *        array(
    *           'pluginClassName' => <pluginClassName>,
    *           'plugin_attr1' => 'val',
    *           'plugin_attr2' => 'val',
    *        )
    *     )
    *  )
    *
    * @param type $jsonSettings json containing dashboard & plugin attributes.
    * @param type $teamid
    * @param type $userid if NULL, default settings for team will be saved.
    */
   public function saveSettings($jsonSettings, $teamid, $userid = NULL) {
      
      Config::setValue(Config::id_dashboard.$this->id, $jsonSettings, Config::configType_string, NULL, 0, $userid, $teamid);
   }

   /**
    * get dashboard settings from DB
    * 
    * if user has saved some settings, return them.
    * if none, return team settings.
    * if none, return default settings
    */
   private function getSettings() {

   /*
      settings = array (
        'displayedPlugins' => array(
           array(
              'pluginClassName' => <pluginClassName>,
              'plugin_attr1' => 'val',
              'plugin_attr2' => 'val',
           )
        )
     )
   */   
      if (NULL == $this->settings) {
         // get [team, user] specific settings
         $json = Config::getValue(Config::id_dashboard.$this->id, array($this->userid, 0, $this->teamid, 0, 0, 0), true);

         // if not found, get [team] specific settings
         if (NULL == $json) {
            $json = Config::getValue(Config::id_dashboard.$this->id, array(0, 0, $this->teamid, 0, 0, 0), true);
         }
         // if no specific settings, use default values 
         // TODO default = all/no plugins ?
         if (NULL == $json) {
            $this->settings = array();
            $pm = PluginManager::getInstance();
            $candidates = $pm->getPluginCandidates($this->domain, $this->categories);
            
            $pluginAttributes = array();
            foreach ($candidates as $pChassName) {
               $pluginAttributes[] = array('pluginClassName' => $pChassName);
            }
            $this->settings[self::SETTINGS_DISPLAYED_PLUGINS] = $pluginAttributes;
         } else {
            // convert json to array
            $this->settings = json_decode($json);
            if (is_null($this->settings)) {
               self::$logger->error("Dashboard settings: json could not be decoded !");
               $this->settings = array(); // failover
            }
         }
      }
      return $this->settings;
   }


   public function getSmartyVariables($smartyHelper) {

      // dashboard settings
      $pm = PluginManager::getInstance();
      $candidates = $pm->getPluginCandidates($this->domain, $this->categories);

      // user specific dashboard settings
      if (NULL == $this->settings) { $this->getSettings(); }

      // insert widgets
      $pluginDataProvider = PluginDataProvider::getInstance();
      $idx = 1;
      foreach ($this->settings[self::SETTINGS_DISPLAYED_PLUGINS] as $pluginAttributes) {

         $pClassName = $pluginAttributes['pluginClassName'];
         
         // check that this plugin is allowed to be displayed in this dashboard
         if (!in_array($pClassName, $candidates)) {
            self::$logger->error("Dashboard user settings: ".$pClassName.' is not a candidate !');
            continue;
         }

         // TODO check, Plugin may not exist...
         $r = new ReflectionClass($pClassName);
         $indicator = $r->newInstanceArgs(array($pluginDataProvider));


         // examples: isGraphOnly, dateRange(defaultRange|currentWeek|currentMonth|noDateLimit), ...
         $indicator->setPluginSettings($pluginAttributes);
         $indicator->execute();

         $data = $indicator->getSmartyVariables();
         foreach ($data as $smartyKey => $smartyVariable) {
            $smartyHelper->assign($smartyKey, $smartyVariable);
         }
         
         #self::$logger->error("Indic classname: ".$pClassName);
         #self::$logger->error("Indic SmartyFilename: ".$pClassName::getSmartyFilename());
         $indicatorHtmlContent = $smartyHelper->fetch($pClassName::getSmartyFilename());

         // set indicator result in a dashboard widget
         $widget = array(
            'id' => 'w_'.$idx, // TODO WARN must be unique (if 2 dashboards in same page)
            'color' => 'color-white',
            'title' => $pClassName::getName(),
            'desc' => $pClassName::getDesc(),
            'category' => implode(',', $pClassName::getCategories()),
            'content' => $indicatorHtmlContent,
         );

         $dashboardWidgets[] = $widget;
         $idx += 1;

         $dashboardPluginCandidates[] = array(
            'pluginClassName' => $pClassName,
            'title' => $pClassName::getName(),
         );
      }

      return array(
         'dashboardId' => $this->id,
         'dashboardTitle' => 'title',
         'dashboardPluginCandidates' => $dashboardPluginCandidates,
         'dashboardWidgets' =>  $dashboardWidgets
         );
   }
}

// Initialize static variables
Dashboard::staticInit();
