<?php
/** ---------------------------------------------------------------------
 * app/lib/core/Search/SearchBase.php : Base class for search
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2008-2014 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of 
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  
 *
 * This source code is free and modifiable under the terms of 
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * @package CollectiveAccess
 * @subpackage Search
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 *
 * ----------------------------------------------------------------------
 */
 
 /**
  *
  */

require_once(__CA_LIB_DIR__."/core/BaseFindEngine.php");
require_once(__CA_LIB_DIR__.'/core/Configuration.php');
require_once(__CA_LIB_DIR__."/core/Datamodel.php");
require_once(__CA_LIB_DIR__."/core/Db.php");
	
	class SearchBase extends BaseFindEngine {
		# ------------------------------------------------
		protected $opo_datamodel;
		protected $opo_db;
		protected $opo_app_config;
		protected $opo_search_config;
		protected $opo_search_indexing_config;
		protected $opo_engine;
		
		static $s_fields_to_index_cache = array();
		# ------------------------------------------------
		/**
		 * @param Db $opo_db A database client object to use rather than creating a new connection. [Default is to create a new database connection]
		 * @param string $ps_engine Name of the search engine to use. [Default is the engine configured using "search_engine_plugin" in app.conf]
		 */
		public function __construct($opo_db=null, $ps_engine=null) {			
			$this->opo_datamodel = Datamodel::load();
			$this->opo_app_config = Configuration::load();
			$this->opo_search_config = Configuration::load($this->opo_app_config->get("search_config"));
			$this->opo_search_indexing_config = Configuration::load($this->opo_search_config->get("search_indexing_config"));			

			// load search engine plugin as configured by the 'search_engine_plugin' directive in the main app config file
			if (!($this->opo_engine = SearchBase::newSearchEngine($ps_engine))) {
				die("Couldn't load configured search engine plugin. Check your application configuration and make sure 'search_engine_plugin' directive is set properly.");
			}
	
			$this->opo_db = $opo_db ? $opo_db : new Db();
		}
		# ------------------------------------------------
		/** 
		 * Get search engine instance
		 *
		 * @param string $ps_plugin_name A valid plugin file name (eg. 'Solr'), not the actual class name (eg. WLPlugSearchEngineSolr)
		 * @return WLPlugSearchEngine instance or null if engine is invalid
		 */
		static public function newSearchEngine($ps_plugin_name=null) {		
			if (!$ps_plugin_name) {
				$o_config = Configuration::load();
				$ps_plugin_name = $o_config->get('search_engine_plugin');
			}
			if (!file_exists(__CA_LIB_DIR__.'/core/Plugins/SearchEngine/'.$ps_plugin_name.'.php')) { return null; }
			
			require_once(__CA_LIB_DIR__.'/core/Plugins/SearchEngine/'.$ps_plugin_name.'.php');
			
			$ps_classname = 'WLPlugSearchEngine'.$ps_plugin_name;
			return new $ps_classname;
		}
		# ------------------------------------------------
		/**
		 * Set the database client 
		 *
		 * @param Db $po_db
		 * @return void
		 */
		public function setDb($po_db) {
			$this->opo_db = $po_db;
		}
		# ------------------------------------------------
		/**
		 * Get the current database client
		 *
		 * @return Db
		 */
		public function getDb() {
			return $this->opo_db;
		}
		# ------------------------------------------------
		# Utils
		# ------------------------------------------------
		/**
		 * Fetch list of fields to index for the subject table
		 *
		 * @param mixed $pm_subject_table Name or number of table indexing is to be applied to
		 * @param mixed $pm_content_table Name or number of table containing content being indexed. [Default is $pm_subject_table]
		 *
		 * @return array
		 */
		public function getFieldsToIndex($pm_subject_table, $pm_content_table=null) {
			if (isset(SearchBase::$s_fields_to_index_cache[$pm_subject_table.'/'.$pm_content_table])) {
				return SearchBase::$s_fields_to_index_cache[$pm_subject_table.'/'.$pm_content_table];
			}
			if (is_numeric($pm_subject_table)) {
				$vs_subject_table = $this->opo_datamodel->getTableName($pm_subject_table);
			} else {
				$vs_subject_table = $pm_subject_table;
			}
	
			if ($pm_content_table == null) {
				$vs_content_table = $vs_subject_table;
			} else {
				if (is_numeric($pm_content_table)) {
					$vs_content_table = $this->opo_datamodel->getTableName($pm_content_table);
				} else {
					$vs_content_table = $pm_content_table;
				}
			}
			if(!($va_info = $this->opo_search_indexing_config->getAssoc($vs_subject_table))) {
				return SearchBase::$s_fields_to_index_cache[$pm_subject_table.'/'.$pm_content_table] = SearchBase::$s_fields_to_index_cache[$vs_subject_table.'/'.$vs_content_table] = null;
			}
	
			$va_fields_to_index = $va_info[$vs_content_table]['fields'];
			
			$t_subject = $this->opo_datamodel->getInstanceByTableName($vs_content_table, false);
			if (is_array($va_fields_to_index)) {
				foreach($va_fields_to_index as $vs_f => $va_info) {
					if ((substr($vs_f, 0, 14) === '_ca_attribute_') && preg_match('!^_ca_attribute_([A-Za-z]+[A-Za-z0-9_]*)$!', $vs_f, $va_matches)) {
						$vn_element_id = $t_subject->_getElementID($va_matches[1]);
						unset($va_fields_to_index[$vs_f]);
						$va_fields_to_index['_ca_attribute_'.$vn_element_id] = $va_info;
					}
				}
			}
			
			if (isset($va_fields_to_index['_metadata'])) {
				$va_data = $va_fields_to_index['_metadata'];
				unset($va_fields_to_index['_metadata']);
				
				
				$va_field_data = $t_subject->getApplicableElementCodes(null, false, false);
				foreach($va_field_data as $vn_element_id => $vs_element_code) {
					$va_fields_to_index['_ca_attribute_'.$vn_element_id] = $va_data;
				}
			}
			return SearchBase::$s_fields_to_index_cache[$pm_subject_table.'/'.$pm_content_table] = SearchBase::$s_fields_to_index_cache[$vs_subject_table.'/'.$vs_content_table] = $va_fields_to_index;
	
		}
		# ------------------------------------------------
		/**
		 * Returns list of tables which provide indexing for the specified subject table
		 *
		 * @param mixed $pm_subject_table Name or number of table indexing is to be applied to
		 *
		 * @return array
		 */
		public function getRelatedIndexingTables($pm_subject_table) {
			if (is_numeric($pm_subject_table)) {
				$pm_subject_table = $this->opo_datamodel->getTableName($pm_subject_table);
			}
			if(!$va_info = $this->opo_search_indexing_config->get($pm_subject_table)) {
				return null;
			}
	
			unset($va_info['_access_points']);
			unset($va_info[$pm_subject_table]);
			$va_tables = array_keys($va_info);
			return $va_tables;
		}
		# ------------------------------------------------
		/**
		 * Fetch list of tables to traverse when indexing content in the content table against the subject table
		 *
		 * @param mixed $pm_subject_table Name or number of table indexing is to be applied to
		 * @param mixed $pm_content_table Name or number of table containing content being indexed. [Default is $pm_subject_table]
		 *
		 * @return array
		 */
		public function getTableIndexingInfo($pm_subject_table, $pm_content_table) {
			if (is_numeric($pm_subject_table)) {
				$pm_subject_table = $this->opo_datamodel->getTableName($pm_subject_table);
			}
			if (is_numeric($pm_content_table)) {
				$pm_content_table = $this->opo_datamodel->getTableName($pm_content_table);
			}
			if(!$va_info = $this->opo_search_indexing_config->get($pm_subject_table)) {
				return null;
			}
			// 'tables' is optional for one-many relations but its absence would be felt upstream
			// so we add it here as an empty array when it's not already present
			if (!isset($va_info[$pm_content_table]['tables']) || !$va_info[$pm_content_table]['tables']) { $va_info[$pm_content_table]['tables'] = array(); }
			return $va_info[$pm_content_table];
		}
		# ------------------------------------------------
		/**
		 * Fetch list of all tables to be indexed
		 *
		 * @return array
		 */
		public function getIndexedTables() {
			return $this->opo_search_indexing_config->getAssocKeys();
		}
		# ------------------------------------------------
		/**
		 * Fetch options to field being indexed for content against subject
		 *
		 * @param mixed $pm_subject_table Name or number of table indexing is to be applied to
		 * @param mixed $pm_content_table Name or number of table containing content being indexed.
		 * @param string $ps_field_name The name of the field to be indexed
		 *
		 * @return array
		 */
		public function getFieldOptions($pm_subject_table, $pm_content_table, $ps_fieldname) {
			if (is_numeric($pm_subject_table)) {
				$pm_subject_table = $this->opo_datamodel->getTableName($pm_subject_table);
			}
			if (is_numeric($pm_content_table)) {
				$pm_content_table = $this->opo_datamodel->getTableName($pm_content_table);
			}
			if(!$va_info = $this->opo_search_indexing_config->get($pm_subject_table)) {
				return null;
			}
	
			return $va_info[$pm_content_table]['fields'][$ps_fieldname];
	
		}
		# -------------------------------------------------
		/**
		 * Fetch list of access points for subject table
		 *
		 * @param 
		 */
		public function getAccessPoints($pm_subject_table) {
			if (is_numeric($pm_subject_table)) {
				$pm_subject_table = $this->opo_datamodel->getTableName($pm_subject_table);
			}
			if(!$va_info = $this->opo_search_indexing_config->get($pm_subject_table)) {
				return null;
			}
			$va_access_points =  $va_info['_access_points'];
			foreach($va_access_points as $vs_k => $va_v) {
				$va_access_points[mb_strtolower($vs_k)] = $va_v;
			}
			return is_array($va_access_points) ? $va_access_points : array();
		}
		# -------------------------------------------------
		/**
		 * Fetch info about an access point
		 *
		 * @param mixed $pm_subject_table Name or number of table indexing is to be applied to
		 * @param string $ps_access_point The name of the access point
		 *
		 * @return array
		 */
		public function getAccessPointInfo($pm_subject_table, $ps_access_point) {
			if (is_numeric($pm_subject_table)) {
				$pm_subject_table = $this->opo_datamodel->getTableName($pm_subject_table);
			}
			if(!$va_info = $this->opo_search_indexing_config->get($pm_subject_table)) {
				return null;
			}
	
			return $va_info['_access_points'][$ps_access_point];
		}
		# -------------------------------------------------
		/**
		 * Provides a model instance for the specified table (table name or number are 
		 * both accepted) using datamodel instance caching
		 *
		 * @param mixed $pm_table_name_or_num A valid table name or number
		 * @return BaseModel A model instance or null if the table is invalid
		 */
		public function getTableInstance($pm_table_name_or_num) {
			return $this->opo_datamodel->getInstance($pm_table_name_or_num, true);
		}
		# ------------------------------------------------------------------
	}
?>