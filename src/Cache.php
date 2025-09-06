<?php

namespace App\Core;

class Cache
{
    public static $expire = 3600;

	public static function refresh($expire = 3600) 
    {
		self::$expire = $expire;

		$files = glob(DIR_CACHE . '/cache.*');

		if ($files) {
			foreach ($files as $file) {
				$filename = basename($file);

				$time = substr(strrchr($file, '.'), 1);

				if ($time < time()) {
					self::delete(substr($filename, 6, strrpos($filename, '.') - 6));
				}
			}
		}
	}

	public static function get(string $key) 
    {
		$files = glob(DIR_CACHE . '/cache.' . basename($key) . '.*');

		if ($files) {
			$file = $files[0];

			$handle = @fopen($file, 'r');

			if ($handle) {
				flock($handle, LOCK_SH);

				$size = 0;

				$size = @filesize($file);

				if ($size > 0) {
					$data = fread($handle, $size);
				} else {
					$data = '';
				}

				flock($handle, LOCK_UN);

				fclose($handle);

				return json_decode($data, true);
			}
		}

		return false;
	}

	public static function set($key, $value, $expire=null) 
    {
		self::delete($key);

        $expire = $expire ?: self::$expire;

		$file = DIR_CACHE . '/cache.' . basename($key) . '.' . ($expire === false ? 'no_expire' : (time() + $expire));

		$handle = fopen($file, 'w');

		flock($handle, LOCK_EX);

		fwrite($handle, json_encode($value));

		fflush($handle);

		flock($handle, LOCK_UN);

		fclose($handle);
	}

	public static function delete($key) 
    {
		$files = glob(DIR_CACHE . '/cache.' . basename($key) . '.*');

		if ($files) {
			foreach ($files as $file) {
				if (!@unlink($file)) {
					clearstatcache(false, $file);
				}
			}
		}
	}
}