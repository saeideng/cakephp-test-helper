<?php

namespace TestHelper\Controller\Component;

use Cake\Controller\Component;
use Cake\Core\Plugin;
use Cake\Core\PluginInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;

class PluginsComponent extends Component {

	/**
	 * @return string[]
	 */
	public function hooks(): array {
		return PluginInterface::VALID_HOOKS;
	}

	/**
	 * @param string[] $pluginNames
	 * @return array
	 */
	public function check(array $pluginNames): array {
		$result = [
		];

		foreach ($pluginNames as $pluginName) {
			$result[$pluginName] = $this->checkPlugin($pluginName);
		}

		return $result;
	}

	/**
	 * @param string $pluginName
	 *
	 * @return array
	 */
	protected function checkPlugin(string $pluginName): array {
		$result = [];

		$configPath = Plugin::configPath($pluginName);
		$result['bootstrapExists'] = $this->bootstrapExists($configPath);

		$classPath = Plugin::classPath($pluginName);
		$result['consoleExists'] = $this->consoleExists($classPath);

		$pluginClassPath = $classPath . 'Plugin.php';
		$pluginClassExists = file_exists($pluginClassPath);

		$result['routesExists'] = $this->routesExists($configPath, $pluginClassExists ? $pluginClassPath : null);
		$result['middlewareExists'] = $pluginClassExists && $this->middlewareExists($pluginClassPath);

		$result['pluginClass'] = $pluginClassPath;
		$result['pluginClassExists'] = $pluginClassExists;
		$result += $this->addPluginConfig($pluginClassPath, $pluginClassExists);

		return $result;
	}

	/**
	 * @param string $pluginClassPath
	 * @param bool $pluginClassExists
	 *
	 * @return array
	 */
	protected function addPluginConfig(string $pluginClassPath, bool $pluginClassExists): array {
		$result = [];

		$parts = $this->hooks();
		if (!$pluginClassExists) {
			foreach ($parts as $part) {
				$result[$part . 'Enabled'] = null;
			}

			return $result;
		}

		$pluginContent = file_get_contents($pluginClassPath);
		foreach ($parts as $part) {
			preg_match('#protected \$' . $part . 'Enabled\s*=\s*(\w+);#', $pluginContent, $matches);
			$enabled = null;
			if ($matches) {
				$enabled = trim($matches[1]) === 'false' ? false : true;
			}

			$result[$part . 'Enabled'] = $enabled;
		}

		return $result;
	}

	/**
	 * @param string $configPath
	 *
	 * @return bool
	 */
	protected function bootstrapExists(string $configPath): bool {
		if (!file_exists($configPath . 'bootstrap.php')) {
			return false;
		}

		$fileContent = file_get_contents($configPath . 'bootstrap.php');

		return trim($fileContent) !== '<?php';
	}

	/**
	 * @param string $configPath
	 * @param string|null $classPath
	 *
	 * @return bool
	 */
	protected function routesExists(string $configPath, ?string $classPath): bool {
		$fileExists = file_exists($configPath . 'routes.php');
		if (!$fileExists && !$classPath) {
			return false;
		}

		if ($fileExists) {
			$fileContent = file_get_contents($configPath . 'routes.php');

			if (trim($fileContent) !== '<?php') {
				return true;
			}
		}

		if ($classPath) {
			$pluginContent = file_get_contents($classPath);
			if (preg_match('#public function routes\(RouteBuilder \$routes#', $pluginContent)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $classPath
	 *
	 * @return bool
	 */
	protected function consoleExists(string $classPath): bool {
		$dirs = [
			'Command',
			'Shell',
		];
		foreach ($dirs as $dir) {
			if (!is_dir($classPath . $dir)) {
				continue;
			}

			$directoryIterator = new RecursiveDirectoryIterator($classPath . $dir);
			$recursiveIterator = new RecursiveIteratorIterator($directoryIterator);
			$regexIterator = new RegexIterator($recursiveIterator, '/\.php$/i', RecursiveRegexIterator::GET_MATCH);

			foreach ($regexIterator as $match) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $pluginClassPath
	 *
	 * @return bool
	 */
	protected function middlewareExists(string $pluginClassPath): bool {
		$pluginContent = file_get_contents($pluginClassPath);

		return (bool)preg_match('#public function middleware\(MiddlewareQueue \$middleware#', $pluginContent);
	}

	/**
	 * @param string $plugin
	 * @param string|null $content
	 * @param array $result
	 *
	 * @return string
	 */
	public function adjustPluginClass(string $plugin, ?string $content, array $result): string {
		if (!$content) {
			$content = <<<TXT
<?php

namespace $plugin;

use Cake\Core\BasePlugin;

class Plugin extends BasePlugin {
}

TXT;
		}

		$parts = $this->hooks();
		foreach ($parts as $part) {
			if ($result[$part . 'Exists'] && $result[$part . 'Enabled'] === false) {
				$content = preg_replace('#protected \$' . $part . 'Enabled = false;#', 'protected $' . $part . 'Enabled = true;', $content);
			}
			if (!$result[$part . 'Exists'] && $result[$part . 'Enabled'] === null) {
				$content = $this->addProperty($content, $part, $result);
			}
		}

		return $content;
	}

	/**
	 * @param string $content
	 * @param string $part
	 * @param array $result
	 *
	 * @return string
	 */
	protected function addProperty(string $content, string $part, array $result): string {
		$pieces = explode(PHP_EOL, $content);

		$pos = null;
		foreach ($pieces as $i => $piece) {
			if (strpos($piece, 'class Plugin extends BasePlugin') === false) {
				continue;
			}

			$pos = $i;
		}

		if ($pos) {
			if (trim($pieces[$pos + 1]) === '{') {
				$pos++;
			}

			// Now set pointer to after this class start
			$pos++;

			$add = [
				'	/**',
				'	 * @var bool',
				'	 */',
				'	protected $' . $part . 'Enabled = ' . ($result[$part . 'Exists'] ? 'true' : 'false') . ';',
			];
			if (trim($pieces[$pos + 1]) !== '{') {
				array_unshift($add, '');
			}

			array_splice($pieces, $pos, 0, $add);
		}

		return implode(PHP_EOL, $pieces);
	}

}
