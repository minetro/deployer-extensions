<?php declare(strict_types = 1);

namespace Contributte\Deployer;

use Contributte\Deployer\Config\Config;
use Contributte\Deployer\Config\Section;
use Contributte\Deployer\Exceptions\DeployException;
use Contributte\Deployer\Logging\StdOutLogger;
use Deployment\Deployer;
use Deployment\FtpServer;
use Deployment\Logger;
use Deployment\Preprocessor;
use Deployment\Server;
use Deployment\SshServer;

class Runner
{

	/** @var Logger */
	private $logger;

	/**
	 * @param Config $config
	 *
	 * @throws \Exception
	 */
	public function run(Config $config): void
	{
		// Create logger
		$logFile = $config->getLogFile();
		$this->logger = $logFile !== null ? new Logger($logFile) : new StdOutLogger();
		$this->logger->useColors = $config->useColors();

		// Create temp dir
		if (!is_dir($tempDir = (string) $config->getTempDir())) {
			$this->logger->log(sprintf('Creating temporary directory %s', $tempDir));
			@mkdir($tempDir, 0777, true);
		}

		// Start time
		$time = time();
		$this->logger->log('Started at ' . date('[Y/m/d H:i]'));

		// Get sections and get sections names
		$sections = $config->getSections();
		$sectionNames = array_map(
			function (Section $s) {
				return $s->getName();
			}, $sections
		);

		// Show info
		$this->logger->log(sprintf('Found sections: %d (%s)', count($sectionNames), implode(',', $sectionNames)));

		// Process all sections
		foreach ($sections as $section) {
			// Show info
			$this->logger->log(sprintf('\nDeploying section [%s]', $section->getName()));

			// Create deployer
			$deployment = $this->createDeployer($config, $section);
			$deployment->tempDir = $tempDir;

			// Detect mode -> generate
			if ($config->getMode() === 'generate') {
				$this->logger->log('Scanning files');
				$localFiles = $deployment->collectPaths();
				$this->logger->log('Saved ' . $deployment->writeDeploymentFile($localFiles));
				continue;
			}

			// Show info
			if ($deployment->testMode) {
				$this->logger->log('Test mode');
			} else {
				$this->logger->log('Live mode');
			}

			if (!$deployment->allowDelete) {
				$this->logger->log('Deleting disabled');
			}

			// Deploy
			$deployment->deploy();
		}

		// Show elapsed time
		$time = time() - $time;
		$this->logger->log(sprintf('\nFinished at %s (in %s seconds)', date('[Y/m/d H:i]'), $time), 'lime');
	}

	/**
	 * @param Config $config
	 * @param Section $section
	 *
	 * @return Deployer
	 * @throws \Exception
	 */
	public function createDeployer(Config $config, Section $section): Deployer
	{
		// Validate section remote
		if (!(bool) parse_url((string) $section->getRemote())) {
			throw new DeployException("Missing or invalid 'remote' URL in config.");
		}

		// Create *Server
		$server = $this->createServer($section);

		// Permissions
		$server->filePermissions = $section->getFilePermissions();
		$server->dirPermissions = $section->getDirPermissions();

		// Create deployer
		$deployment = new Deployer($server, (string) $section->getLocal(), $this->logger);

		// Set-up preprocessing
		if ($section->isPreprocess() === true) {
			$masks = $section->getPreprocessMasks();
			$deployment->preprocessMasks = $masks === [] ? ['*.js', '*.css'] : $masks;
			$preprocessor = new Preprocessor($this->logger);
			$deployment->addFilter('js', [$preprocessor, 'expandApacheImports']);
			$deployment->addFilter('js', [$preprocessor, 'compressJs'], true);
			$deployment->addFilter('css', [$preprocessor, 'expandApacheImports']);
			$deployment->addFilter('css', [$preprocessor, 'expandCssImports']);
			$deployment->addFilter('css', [$preprocessor, 'compressCss'], true);
		}

		// Merge ignore masks
		$deployment->ignoreMasks = array_merge(
			['*.bak', '.svn', '.git*', 'Thumbs.db', '.DS_Store', '.idea'],
			$section->getIgnoreMasks()
		);

		// Basic settings
		$deployFile = (string) $section->getDeployFile();
		$deployment->deploymentFile = $deployFile === '' ? $deployment->deploymentFile : $deployFile;
		$deployment->allowDelete = $section->isAllowDelete();
		$deployment->toPurge = $section->getPurges();
		$deployment->testMode = $section->isTestMode();

		// Before callbacks
		$deployment->runBefore[] = function (Server $server, Logger $logger, Deployer $deployer) use ($config, $section): void {
			foreach ($section->getBeforeCallbacks() as $bc) {
				if(is_callable($bc)) {
				    call_user_func_array($bc, [$config, $section, $server, $logger, $deployer]);
				} else {
					$logger->log('Before callback \'' . get_class($bc[0]) . '::' . $bc[1] . '\' not exists.', 'red');
				}
			}
		};

		// After callbacks
		$deployment->runAfter[] = function (Server $server, Logger $logger, Deployer $deployer) use ($config, $section): void {
			foreach ($section->getAfterCallbacks() as $ac) {
				if(is_callable($ac)) {
					call_user_func_array($ac, [$config, $section, $server, $logger, $deployer]);
				} else {
					$logger->log('After callback \'' . get_class($ac[0]) . '::' . $ac[1] . '\' not exists.', 'red');
				}
			}
		};

		return $deployment;
	}

	/**
	* @param Section $section
	*
	* @return Server
	* @throws \Exception
	*/
	protected function createServer(Section $section): Server
	{
		return parse_url((string) $section->getRemote(), PHP_URL_SCHEME) === 'sftp'
			? new SshServer((string) $section->getRemote())
			: new FtpServer((string) $section->getRemote(), $section->isPassiveMode());
	}

}
