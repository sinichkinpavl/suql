<?php
namespace Helper;

class SuQLObjectReader {
  public static function getAllTheQueryList($osuql) {
    return array_keys($osuql['queries']);
  }

  public static function &getQuery(&$osuql, $name) {
    if (isset($osuql['queries'][$name]))
      $queryObject = &$osuql['queries'][$name];
    else
      $queryObject = null;

    return $queryObject;
  }
}