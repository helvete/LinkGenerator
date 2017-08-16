<?php

namespace helvete\Tools;

use Nette;
use Nette\Application\IPresenterFactory;
use Nette\Application\IRouter;
use Nette\Application\UI;
use Nette\Application\UI\PresenterComponentReflection;
use Nette\Application\Request;

/**
 * Link generator library
 */
class LinkGenerator extends Nette\Object
{
	/** @var IRouter */
	private $router;

	/** @var Nette\Http\Url */
	private $refUrl;

	/** @var IPresenterFactory */
	private $presenterFactory;


	/**
	 * Class construct
	 *
	 * @param  IRouter				$router
	 * @param  Nette\Http\Url		$refUrl
	 * @param  IPresenterFactory	$presenterFactory
	 * @return string
	 */
	public function __construct(
		IRouter $router,
		Nette\Http\Url $refUrl,
		IPresenterFactory $presenterFactory = null
	) {
		$this->router = $router;
		$this->refUrl = $refUrl;
		$this->presenterFactory = $presenterFactory;
	}


	/**
	 * Generates URL to presenter.
	 * Destination in format "[[[module:]presenter:]action] [#fragment]"
	 *
	 * @param  string	$dest
	 * @param  array	$params
	 * @return string
	 */
	public function link($dest, array $params = array())
	{
		if (!preg_match('~^([\w:]+):(\w*+)(#.*)?()\z~', $dest, $m)) {
			throw new UI\InvalidLinkException("Invalid link destination '$dest'.");
		}
		list(, $presenter, $action, $frag) = $m;

		try {
			$class = $this->presenterFactory
				? $this->presenterFactory->getPresenterClass($presenter)
				: null;
		} catch (InvalidPresenterException $e) {
			throw new UI\InvalidLinkException($e->getMessage(), null, $e);
		}

		if (is_subclass_of($class, 'Nette\Application\UI\Presenter')) {
			if ($action === '') {
				$action = UI\Presenter::DEFAULT_ACTION;
			}
			if (method_exists($class, $method = $class::formatActionMethod($action))
				|| method_exists($class, $method = $class::formatRenderMethod($action))
			) {
				self::argsToParams($class, $method, $params);
			}
		}

		if ($action !== '') {
			$params[UI\Presenter::ACTION_KEY] = $action;
		}

		$url = $this->router->constructUrl(
			new Request($presenter, null, $params),
			$this->refUrl
		);

		if ($url === null) {
			unset($params[UI\Presenter::ACTION_KEY]);
			$params = urldecode(http_build_query($params, null, ', '));
			throw new UI\InvalidLinkException("No route for $dest($params)");
		}

		return $url . $frag;
	}


	/**
	 * Convert list of arguments to named parameters.
	 *
	 * @param  string  class name
	 * @param  string  method name
	 * @param  array   arguments
	 * @param  array   supplemental arguments
	 * @return void
	 * @throws InvalidLinkException
	 */
	static public function argsToParams($class, $method, &$args,
		$supplemental = array()
	) {
		$i = 0;
		$rm = new \ReflectionMethod($class, $method);
		foreach ($rm->getParameters() as $param) {
			$name = $param->getName();
			if (array_key_exists($i, $args)) {
				$args[$name] = $args[$i];
				unset($args[$i]);
				$i++;

			} elseif (array_key_exists($name, $args)) {
				// continue with process

			} elseif (array_key_exists($name, $supplemental)) {
				$args[$name] = $supplemental[$name];

			} else {
				continue;
			}

			if ($args[$name] === null) {
				continue;
			}

			$def = $param->isDefaultValueAvailable() && $param->isOptional()
				? $param->getDefaultValue()
				: null; // see PHP bug #62988
			$type = $param->isArray()
				? 'array'
				: gettype($def);
			if (!PresenterComponentReflection::convertType($args[$name], $type)) {
				throw new UI\InvalidLinkException("Invalid value for parameter "
					. "'$name' in method $class::$method(), expected "
					. ($type === 'NULL' ? 'scalar' : $type) . ".");
			}

			if ($args[$name] === $def
				|| ($def === null
					&& is_scalar($args[$name])
					&& (string)$args[$name] === '')
			) {
				$args[$name] = null; // value transmit is unnecessary
			}
		}

		if (array_key_exists($i, $args)) {
			$method = $rm->getName();
			throw new UI\InvalidLinkException(
				"Passed more parameters than method $class::$method() expects.");
		}
	}
}
