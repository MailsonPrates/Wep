<?php

/**
 * @version 1.0.0
 */

namespace App\Core;

/**
 * Singleton Pattern.
 * 
 * Modern implementation.
 * https://stackoverflow.com/questions/203336/creating-the-singleton-design-pattern-in-php5
 */
class Singleton
{
    /**
     * Call this method to get singleton
     */
    public static function instance()
    {
      static $instance = false;
      if ( $instance === false ){
        // Late static binding (PHP 5.3+)
        $instance = new static();
      }

      return $instance;
    }

    /**
     * Make constructor private, so nobody can call "new Class".
     */
    private function __construct() {}

    /**
     * Make clone magic method private, so nobody can clone instance.
     */
    private function __clone() {}

    /**
     * Make sleep magic method private, so nobody can serialize instance.
     */
    public function __sleep() {}

    /**
     * Make wakeup magic method private, so nobody can unserialize instance.
     */
    public function __wakeup() {}

}