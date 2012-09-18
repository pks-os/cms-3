<?php
namespace Blocks;

/**
 *
 */
class App extends \CWebApplication
{
	public $componentAliases;

	private $_templatePath;
	private $_isInstalled;

	/**
	 * Processes resource requests before anything else has a chance to initialize.
	 */
	public function init()
	{
		// Set default timezone to UTC
		date_default_timezone_set('UTC');

		foreach ($this->componentAliases as $alias)
		{
			Blocks::import($alias);
		}

		blx()->getComponent('request');
		blx()->getComponent('log');

		parent::init();
	}

	/**
	 * Processes the request.
	 *
	 * @throws HttpException
	 */
	public function processRequest()
	{
		// Let's set the target language from the browser's language preferences.
		$this->_processBrowserLanguage();

		// If this is a resource request, we should respond with the resource ASAP
		$this->_processResourceRequest();

		// Config validation
		$this->_validateConfig();

		// We add the DbLogRoute *after* we have validated the config.
		$this->_addDbLogRoute();

		// Process install requests
		$this->_processInstallRequest();

		// Are we in the middle of a manual update?
		if ($this->isDbUpdateNeeded())
		{
			// Let's let all CP requests through.
			if ($this->request->getMode() == HttpRequestMode::CP)
			{
				$this->runController('dbupdate');
				$this->end();
			}
			// We'll also let action requests to dbupdate through as well.
			else if ($this->request->getMode() == HttpRequestMode::Action && (($actionPath = $this->request->getActionPath()) !== null) && isset($actionPath[0]) && $actionPath[0] == 'dbupdate')
			{
				$controller = $actionPath[0];
				$action = isset($actionPath[1]) ? $actionPath[1] : 'index';
				$this->runController($controller.'/'.$action);
				$this->end();
			}
			else
				throw new HttpException(404);
		}

		// If it's not a CP request OR the system is on, let's continue processing.
		if (Blocks::isSystemOn() || (!Blocks::isSystemOn() && ($this->request->getMode() == HttpRequestMode::CP || ($this->request->getMode() == HttpRequestMode::Action && BLOCKS_CP_REQUEST))))
		{
			/* BLOCKS ONLY */
			// Set the target language to the site language
			$this->setLanguage(Blocks::getLanguage());
			/* end BLOCKS ONLY */
			/* BLOCKSPRO ONLY */
			// Attempt to set the target language from user preferences.
			$this->_processUserPreferredLanguage();
			/* end BLOCKSPRO ONLY */

			// Otherwise maybe it's an action request?
			$this->_processActionRequest();

			// Otherwise run the template controller
			$this->runController('templates');
		}
		else
		{
			// Display the offline template for the front-end.
			$this->runController('templates/offline');
		}
	}

	/**
	 * Processes install requests.
	 *
	 * @access private
	 * @throws HttpException
	 */
	private function _processInstallRequest()
	{
		// Are they requesting an installer template/action specifically?
		if ($this->request->getMode() == HttpRequestMode::CP && $this->request->getPathSegment(1) === 'install')
		{
			$action = $this->request->getPathSegment(2, 'index');
			$this->runController('install/'.$action);
			$this->end();
		}
		else if (BLOCKS_CP_REQUEST && $this->request->getMode() == HttpRequestMode::Action)
		{
			$actionPath = $this->request->getActionPath();
			if (isset($actionPath[0]) && $actionPath[0] == 'install')
				$this->_processActionRequest();
		}

		// Should they be?
		else if (!$this->isInstalled())
		{
			// Give it to them if accessing the CP
			if ($this->request->getMode() == HttpRequestMode::CP)
			{
				$url = UrlHelper::getUrl('install');
				$this->request->redirect($url);
			}
			// Otherwise return a 404
			else
				throw new HttpException(404);
		}
	}

	/**
	 * Get's the browser's preferred languages, checks to see if we have translation data for it and set the target language.
	 */
	private function _processBrowserLanguage()
	{
		$browserLanguages = blx()->request->getBrowserLanguages();
		foreach ($browserLanguages as $language)
		{
			// Check to see if we have translation data for the language.  If it doesn't exist, it will default to en_us.
			if (Locale::exists($language))
			{
				$this->setLanguage($language);
				break;
			}
		}
	}

	/* BLOCKSPRO ONLY */

	/**
	 * See if the user is logged in and they have a preferred language.  If so, use it.
	 */
	private function _processUserPreferredLanguage()
	{
		// See if the user is logged in.
		if (blx()->user->isLoggedIn())
		{
			$user = blx()->accounts->getCurrentUser();
			$userLanguage = Locale::getCanonicalID($user->language);

			// If the user has a preferred language saved and we have translation data for it, set the target language.
			if (($userLanguage !== $this->getLanguage()) && Locale::exists($userLanguage))
				$this->setLanguage($userLanguage);
		}
	}

	/* end BLOCKSPRO ONLY */

	/**
	 * Processes action requests.
	 *
	 * @access private
	 * @throws HttpException
	 */
	private function _processActionRequest()
	{
		if ($this->request->getMode() == HttpRequestMode::Action)
		{
			$actionPath = $this->request->getActionPath();

			// See if there is a first segment.
			if (isset($actionPath[0]))
			{
				$controller = $actionPath[0];
				$action = isset($actionPath[1]) ? $actionPath[1] : '';

				// Check for a valid controller
				$class = __NAMESPACE__.'\\'.ucfirst($controller).'Controller';
				if (class_exists($class))
				{
					$route = $controller.'/'.$action;
					$this->runController($route);
					$this->end();
				}
			}

			throw new HttpException(404);
		}
	}

	/**
	 * Creates a controller instance based on a route.
	 */
	public function createController($route)
	{
		if (($route=trim($route,'/')) === '')
			$route = $this->defaultController;

		$routeParts = explode('/', $route);
		$controllerId = ucfirst(array_shift($routeParts));
		$action = implode('/', $routeParts);

		$class = __NAMESPACE__.'\\'.$controllerId.'Controller';

		if (class_exists($class))
		{
			return array(
				Blocks::createComponent($class, $controllerId),
				$this->parseActionParams($action),
			);
		}
	}

	/**
	 * Processes resource requests.
	 *
	 * @access private
	 * @throws HttpException
	 */
	private function _processResourceRequest()
	{
		if ($this->request->getMode() == HttpRequestMode::Resource)
		{
			// Get the path segments, except for the first one which we already know is "resources"
			$segs = array_slice(array_merge($this->request->getPathSegments()), 1);

			// If this is a system JS resource request, prepend either 'compressed/' or 'uncompressed/'
			if (isset($segs[0]) && $segs[0] == 'js')
			{
				if (blx()->config->useCompressedJs)
				{
					array_splice($segs, 1, 0, 'compressed');
				}
			}

			$rootFolderUrl = null;
			$rootFolderPath = $this->path->getResourcesPath();
			$relativeResourcePath = implode('/', $segs);

			// Check app/resources folder first.
			if (IOHelper::fileExists($rootFolderPath.$relativeResourcePath))
			{
				$rootFolderUrl = UrlHelper::getUrl($this->config->resourceTrigger.'/');
			}
			else
			{
				// See if the first segment is a plugin handle.
				if (isset($segs[0]))
				{
					$rootFolderPath = $this->path->getPluginsPath().$segs[0].'/resources/';
					$relativeResourcePath = implode('/', array_splice($segs, 1));

					// Looks like it belongs to a plugin.
					if (IOHelper::fileExists($rootFolderPath.$relativeResourcePath))
					{
						$rootFolderUrl = UrlHelper::getUrl($this->config->resourceTrigger.$segs[0]);
					}
				}
			}

			// Couldn't find a match, so 404
			if (!$rootFolderUrl)
				throw new HttpException(404);

			$resourceProcessor = new ResourceProcessor($rootFolderPath, $rootFolderUrl, $relativeResourcePath);
			$resourceProcessor->processResourceRequest();

			exit(1);
		}
	}

	/**
	 * Validates the system config.
	 *
	 * @access private
	 * @return mixed
	 * @throws Exception|HttpException
	 */
	private function _validateConfig()
	{
		$messages = array();

		$databaseServerName = $this->config->getDbItem('server');
		$databaseAuthName = $this->config->getDbItem('user');
		$databaseName = $this->config->getDbItem('database');
		$databasePort = $this->config->getDbItem('port');
		$databaseCharset = $this->config->getDbItem('charset');
		$databaseCollation = $this->config->getDbItem('collation');

		if (StringHelper::isNullOrEmpty($databaseServerName))
			$messages[] = Blocks::t('The database server name isn’t set in your db config file.');

		if (StringHelper::isNullOrEmpty($databaseAuthName))
			$messages[] = Blocks::t('The database user name isn’t set in your db config file.');

		if (StringHelper::isNullOrEmpty($databaseName))
			$messages[] = Blocks::t('The database name isn’t set in your db config file.');

		if (StringHelper::isNullOrEmpty($databasePort))
			$messages[] = Blocks::t('The database port isn’t set in your db config file.');

		if (StringHelper::isNullOrEmpty($databaseCharset))
			$messages[] = Blocks::t('The database charset isn’t set in your db config file.');

		if (StringHelper::isNullOrEmpty($databaseCollation))
			$messages[] = Blocks::t('The database collation isn’t set in your db config file.');

		if (!empty($messages))
			throw new Exception(Blocks::t('Database configuration errors: {errors}', array('errors' => implode(PHP_EOL, $messages))));

		try
		{
			$connection = $this->db;
			if (!$connection)
				$messages[] = Blocks::t('There is a problem connecting to the database with the credentials supplied in your db config file.');
		}
		catch (\Exception $e)
		{
			Blocks::log($e->getMessage());
			$messages[] = Blocks::t('There is a problem connecting to the database with the credentials supplied in your db config file.');
		}

		if (!empty($messages))
			throw new Exception(Blocks::t('Database configuration errors: {errors}', array('errors' => implode(PHP_EOL, $messages))));
	}

	/**
	 * Adds the DbLogRoute class to the log router.
	 */
	public function _addDbLogRoute()
	{
		$route = array('class' => 'Blocks\\DbLogRoute');
		$this->log->addRoute($route);
	}

	/**
	 * Determines if we're in the middle of a manual update, and a DB update is needed.
	 *
	 * @return bool
	 */
	public function isDbUpdateNeeded()
	{
		if (Blocks::getBuild() !== Blocks::getStoredBuild() || Blocks::getVersion() !== Blocks::getStoredVersion())
		{
			return true;
		}
		else
			return false;
	}

	/**
	 * Determines if @@@productDisplay@@@ is installed by checking if the info table exists.
	 *
	 * @return bool
	 */
	public function isInstalled()
	{
		if (!isset($this->_isInstalled))
		{
			$infoTable = $this->db->getSchema()->getTable('{{info}}');
			$this->_isInstalled = (bool)$infoTable;
		}

		return $this->_isInstalled;
	}

	/**
	 * Sets the isInstalled state.
	 *
	 * @param bool $isInstalled
	 */
	public function setInstalledStatus($isInstalled)
	{
		$this->_isInstalled = (bool)$isInstalled;
	}

	/**
	 * Gets the viewPath for the incoming request.
	 * We can't use setViewPath() because our view path depends on the request type, which is initialized after web application, so we override getViewPath();
	 *
	 * @return mixed
	 */
	public function getViewPath()
	{
		if (!isset($this->_templatePath))
		{
			if (strpos(get_class($this->request), 'HttpRequest') !== false)
			{
				$this->_templatePath = $this->path->getTemplatesPath();
			}
			else
			{
				// in the case of an exception, our custom classes are not loaded.
				$this->_templatePath = BLOCKS_SITE_TEMPLATES_PATH;
			}
		}

		return $this->_templatePath;
	}

	/**
	 * Sets the template path for the app.
	 *
	 * @param $path
	 */
	public function setViewPath($path)
	{
		$this->_templatePath = $path;
	}

	/**
	 * Returns the CP templates path.
	 *
	 * @return string
	 */
	public function getSystemViewPath()
	{
		return $this->path->getCpTemplatesPath();
	}

	/**
	 * Formats an exception into JSON before returning it to the client.
	 *
	 * @param array $data
	 */
	public function returnAjaxException($data)
	{
		$exceptionArr['error'] = $data['message'];

		if (blx()->config->devMode)
		{
			$exceptionArr['trace']  = $data['trace'];
			$exceptionArr['traces'] = $data['traces'];
			$exceptionArr['file']   = $data['file'];
			$exceptionArr['line']   = $data['line'];
			$exceptionArr['type']   = $data['type'];
		}

		JsonHelper::sendJsonHeaders();
		echo JsonHelper::encode($exceptionArr);
		$this->end();
	}

	/**
	 * Formats a PHP error into JSON before returning it to the client.
	 *
	 * @param integer $code error code
	 * @param string $message error message
	 * @param string $file error file
	 * @param string $line error line
	 */
	public function returnAjaxError($code, $message, $file, $line)
	{
		if(blx()->config->devMode == true)
		{
			$outputTrace = '';
			$trace = debug_backtrace();

			// skip the first 3 stacks as they do not tell the error position
			if(count($trace) > 3)
				$trace = array_slice($trace, 3);

			foreach($trace as $i => $t)
			{
				if (!isset($t['file']))
					$t['file'] = 'unknown';

				if (!isset($t['line']))
					$t['line'] = 0;

				if (!isset($t['function']))
					$t['function'] = 'unknown';

				$outputTrace .= "#$i {$t['file']}({$t['line']}): ";

				if (isset($t['object']) && is_object($t['object']))
					$outputTrace .= get_class($t['object']).'->';

				$outputTrace .= "{$t['function']}()\n";
			}

			$errorArr = array(
				'error' => $code.' : '.$message,
				'trace' => $outputTrace,
				'file'  => $file,
				'line'  => $line,
			);
		}
		else
		{
			$errorArr = array('error' => $message);
		}

		JsonHelper::sendJsonHeaders();
		echo JsonHelper::encode($errorArr);
		$this->end();
	}
}
