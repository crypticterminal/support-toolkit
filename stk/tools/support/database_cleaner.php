<?php
/**
*
* @package Support Toolkit - Database Cleaner
* @version $Id$
* @copyright (c) 2009 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
 * @ignore
 */
if (!defined('IN_PHPBB'))
{
	exit;
}

class database_cleaner
{
	/**
	* @var database_cleaner_data The database cleaner data object
	*/
	var $data = null;

	/**
	* @var String phpBB version number
	*/
	var $phpbb_version = '';

	/**
	* @var Integer The step that is being ran
	*/
	var $step = 0;

	/**
	* Do we have a datafile for this version?
	*/
	function tool_active()
	{
		global $config;

		// Correctly format the version number. Only RC releases are in uppercase
		$this->phpbb_version = str_replace(array('.', '-', 'rc'), array('_', '_', 'RC'), strtolower($config['version']));

		// Unstable version can only be used when debugging
		if (!defined('DEBUG') && preg_match('#A|B|RC$#i'))
		{
			return false;
		}

		if (file_exists(STK_ROOT_PATH . 'includes/database_cleaner/data/' . $this->phpbb_version . '.' . PHP_EXT) === false)
		{
			return 'DATAFILE_NOT_FOUND';
		}

		// As this method is always called we can use a small hackish way to setup the dbcleaner when needed
		if (request_var('t', '') == 'database_cleaner' && !class_exists('database_cleaner_data'))
		{
			$this->step = request_var('step', 0);

			// include the required file for this version
			include(STK_ROOT_PATH . 'includes/database_cleaner/functions.' . PHP_EXT);
			include(STK_ROOT_PATH . 'includes/database_cleaner/database_cleaner_data.' . PHP_EXT);

			// Load all data for this version
			$this->data = new database_cleaner_data();
			fetch_cleaner_data($this->data, $this->phpbb_version);
		}

		return true;
	}

	/**
	* Display the correct confirmation screen
	*/
	function display_options()
	{
		global $template, $user;

		// Display the correct options screen
		$user->add_lang('acp/common');
		switch ($this->step)
		{
			// Display a quick intro here and make sure they know what they are doing...
			case 0 :
				$template->assign_vars(array(
					'S_NO_INSTRUCTIONS'	=> true,
					'SUCCESS_TITLE'		=> $user->lang['DATABASE_CLEANER'],
					'SUCCESS_MESSAGE'	=> $user->lang['DATABASE_CLEANER_WELCOME'],
					'ERROR_TITLE'		=> ' ',
					'ERROR_MESSAGE'		=> $user->lang['DATABASE_CLEANER_WARNING'],
				));
			break;

			// Validate database tables
			case 1 :
				// The confirm message for step 0
				$template->assign_var('SUCCESS_MESSAGE', $user->lang('BOARD_DISABLE_SUCCESS'));

				$found_tables	= get_phpbb_tables();
				$req_tables		= $this->data->get_tables();
				$tables			= array_unique(array_merge(array_keys($req_tables), $found_tables));
				sort($tables);

				$template->assign_block_vars('section', array(
					'NAME'		=> $user->lang('DATABASE_TABLES'),
					'TITLE'		=> $user->lang('DATABASE_TABLES'),
				));

				foreach ($tables as $table)
				{
					// Table was added or removed
					if (!isset($req_tables[$table]) && in_array($table, $found_tables) || isset($req_tables[$table]) && !in_array($table, $found_tables))
					{
						$template->assign_block_vars('section.items', array(
							'NAME'			=> $table,
							'FIELD_NAME'	=> $table,
							'MISSING'		=> isset($req_tables[$table]) ? true : false,
						));
					}
				}
			break;

			// Display fix config options.
			// @todo will become step 3 once db integerty checks are included
			case 2 :
				// The confirm message for step 1
				$template->assign_var('SUCCESS_MESSAGE', $user->lang('DATABASE_TABLES_SUCCESS'));

				// display extra config variables and let them check/uncheck the ones they want to add/remove
				$template->assign_block_vars('section', array(
					'NAME'		=> $user->lang['CONFIG_SETTINGS'],
					'TITLE'		=> $user->lang['ROWS'],
				));

				$config_rows = $existing_config = array();
				get_config_rows($this->data->config_data, $config_rows, $existing_config);
				foreach ($config_rows as $name)
				{
					// Skip ones that are in the default install and are in the existing config
					if (isset($this->data->config_data[$name]) && in_array($name, $existing_config))
					{
						continue;
					}

					$template->assign_block_vars('section.items', array(
						'NAME'			=> $name,
						'FIELD_NAME'	=> $name,
						'MISSING'		=> (!in_array($name, $existing_config)) ? true : false,
					));
				}
			break;
		}

		// Output the page
		page_header($user->lang['DATABASE_CLEANER'], false);

		$template->assign_vars(array(
			'STEP'			=> $this->step,

			// Create submit link, always set "submit" so we'll continue in the run_tool method
			'U_NEXT_STEP'	=> append_sid(STK_INDEX, array('c' => 'support', 't' => 'database_cleaner', 'step' => $this->step, 'submit' => true)),
		));

		$template->set_filenames(array(
			'body' => 'tools/database_cleaner.html',
		));

		page_footer();
	}

	/**
	* Perform the right actions
	*/
	function run_tool()
	{
		global $db, $umil;

		$continue = (isset($_POST['continue'])) ? true : false;
		$selected = request_var('items', array('' => ''));

		if ($this->step > 0)
		{
			// Kick them if bad form key
			check_form_key('database_cleaner', false, append_sid(STK_INDEX, array('c' => 'support', 't' => 'database_cleaner')), true);
		}

		// Do the thing
		switch ($this->step)
		{
			// Start the cleaner
			case 0 :
				// Redirect if they selected quit
				if (isset($_POST['quit']))
				{
					redirect(append_sid(STK_ROOT_PATH . 'index.' . PHP_EXT));
				}

				// Start by disabling the board
				set_config('board_disable', 1);
			break;

			// Fix tables
			case 1 :
				$found_tables	= get_phpbb_tables();
				$req_tables		= $this->data->get_tables();
				$tables			= array_unique(array_merge(array_keys($req_tables), $found_tables));
				sort($tables);

				// Loop through selected and fix them
				foreach (array_keys($selected) as $table)
				{
					if (isset($req_tables[$table]) && !in_array($table, $found_tables))
					{
						$result = $umil->table_add($table, $req_tables[$table]);
						if (stripos($result, 'SQL ERROR'))
						{
							$error[] = $result;
						}
					}
					else if (!isset($req_tables[$table]) && in_array($table, $found_tables))
					{
						$result = $umil->table_remove($table);
						if (stripos($result, 'SQL ERROR'))
						{
							$error[] = $result;
						}
					}
				}
			break;

			// Fix config
			case 2 :
				$config_rows = $existing_config = array();
				get_config_rows($this->data->config_data, $config_rows, $existing_config);
				foreach ($config_rows as $name)
				{
					if (isset($this->data->config_data[$name]) && in_array($name, $existing_config))
					{
						continue;
					}

					if (isset($selected[$name]))
					{
						if (isset($this->data->config_data[$name]) && !in_array($name, $existing_config))
						{
							// Add it with the default settings we've got...
							set_config($name, $this->data->config_data[$name]['config_value'], $this->data->config_data[$name]['is_dynamic']);
						}
						else if (!isset($this->data->config_data[$name]) && in_array($name, $existing_config))
						{
							// Remove it
							$db->sql_query('DELETE FROM ' . CONFIG_TABLE . " WHERE config_name = '" . $db->sql_escape($name) . "'");
						}
					}
				}

			// Last step *never* has a break

			case 'final' :
				// Enable and purge cache
				$umil->cache_purge();
				$umil->cache_purge('auth');
				set_config('board_disable', 0);

				// Finished!
				trigger_error('DATABASE_CLEANER_SUCCESS');
			break;
		}

		// Redirect to the next step
		redirect(append_sid(STK_INDEX, array('c' => 'support', 't' => 'database_cleaner', 'step' => $this->step + 1)));
	}
}