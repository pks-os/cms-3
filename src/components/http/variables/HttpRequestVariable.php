<?php
namespace Blocks;

/**
 * Request functions
 */
class HttpRequestVariable
{
	/**
	 * Returns whether this is a secure connection.
	 *
	 * @return bool
	 */
	public function secure()
	{
		return blx()->request->isSecureConnection();
	}

	/**
	 * Returns a variable from either the query string or the post data.
	 *
	 * @param string $name
	 * @param string $default
	 * @return mixed
	 */
	public function param($name, $default = null)
	{
		return blx()->request->getParam($name, $default);
	}

	/**
	 * Returns a variable from the query string.
	 *
	 * @param string $name
	 * @param string $default
	 * @return mixed
	 */
	public function query($name, $default = null)
	{
		return blx()->request->getQuery($name, $default);
	}

	/**
	 * Returns a value from post data.
	 *
	 * @param string $name
	 * @param string $default
	 * @return mixed
	 */
	public function post($name, $default = null)
	{
		return blx()->request->getPost($name, $default);
	}

	/**
	 * Returns all URL segments.
	 *
	 * @return array
	 */
	public function segments()
	{
		return blx()->request->getPathSegments();
	}

	/**
	 * Returns a specific URL segment.
	 *
	 * @param int $num
	 * @param string $default
	 * @return string
	 */
	public function segment($num, $default = null)
	{
		return blx()->request->getPathSegment($num, $default);
	}

	/**
	 * Returns the last URL segment.
	 *
	 * @return string
	 */
	public function lastSegment()
	{
		$segments = blx()->request->getPathSegments();

		if ($segments)
		{
			return $segments[count($segments)-1];
		}
	}

	/**
	 * Returns the server name.
	 *
	 * @return string
	 */
	public function servername()
	{
		return blx()->request->getServerName();
	}
}
