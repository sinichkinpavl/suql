<?php
use core\SuQLSpecialSymbols;
use core\SuQLName;
use Helper\CArray;
use Helper\CString;

class TestBuilder
{
  private $osuql = null;
  private $sql = [];

  const SELECT_TEMPLATE = "#select##from##join##where##group##having##order##limit#";
  const REGEX_SUB_QUERY = '/{:v:}(?<name>\w+)/msi';

  function __construct($osuql)
  {
    $this->osuql = $osuql;
  }

  public function getSql($queryList)
  {
    if (empty($this->sql)) return null;

    $sqlList = CArray::slice_by_keys($this->sql, $queryList);

    return count($queryList) === 1 && count($sqlList) === 1
            ? reset($sqlList)
            : $sqlList;
  }

  public function run($queryList)
  {
    if (!$this->osuql)
      return;

    $fullQueryList = $this->osuql->getFullQueryList();

    foreach ($fullQueryList as $query) {
      $this->sql[$query] = trim($this->buildQuery($query));
    }

    foreach ($queryList as $query) {
      $this->sql[$query] = $this->composeQuery($query);
    }
  }

  private function buildQuery($query)
  {
    $queryType = $this->osuql->getQuery($query)->getType();
    $handler = 'build'.ucfirst($queryType).'Query';
    return method_exists($this, $handler)
            ? $this->$handler($query)
            : null;
  }

  private function buildSelectQuery($query)
  {
    $this->applyModifier($query);

    $selectTemplate = self::SELECT_TEMPLATE;

    $selectTemplate = str_replace('#select#', $this->buildSelect($query), $selectTemplate);
    $selectTemplate = str_replace('#from#'  , $this->buildFrom($query),   $selectTemplate);
    $selectTemplate = str_replace('#join#'  , $this->buildJoin($query),   $selectTemplate);
    $selectTemplate = str_replace('#group#' , $this->buildGroup($query),  $selectTemplate);
    $selectTemplate = str_replace('#where#' , $this->buildWhere($query),  $selectTemplate);
    $selectTemplate = str_replace('#having#', $this->buildHaving($query), $selectTemplate);
    $selectTemplate = str_replace('#order#' , $this->buildOrder($query),  $selectTemplate);
    $selectTemplate = str_replace('#limit#' , $this->buildLimit($query),  $selectTemplate);

    return $selectTemplate;
  }

  private function buildUnionQuery($query)
  {
    return $this->osuql->getQuery($query)->getSuql();
  }

  private function composeQuery($query)
  {
    if (!isset($this->sql[$query]))
      return '';
    $suql = $this->sql[$query];

    $subQueries = (new SuQLRegExp(self::REGEX_SUB_QUERY))->match_all($suql);
    if (empty($subQueries['name']))
      return $suql;
    else {
      foreach ($subQueries['name'] as $subQuery)
        $suql = str_replace(SuQLSpecialSymbols::$prefix_declare_variable . $subQuery, '('.$this->composeQuery($subQuery).')', $suql);

      return $suql;
    }
  }

  public function applyModifier($query)
  {
    $oselect = $this->osuql->getQuery($query);
    foreach ($oselect->getSelect() as $field => $ofield) {
      if ($ofield->hasModifier()) {
        foreach ($ofield->getModifierList() as $name => $params) {
          $modifier_handler = "mod_$name";
          if (method_exists(SQLModifier::class, $modifier_handler))
            SQLModifier::$modifier_handler($ofield, $params);
        }
      }
    }
  }

  protected function buildSelect($query)
  {
    $oselect = $this->osuql->getQuery($query);

    $selectList = [];
    foreach ($oselect->getSelect() as $field => $ofield) {
      if ($ofield->visible()) {
        $fieldName = new SuQLName($ofield->getField(), $ofield->getAlias());
        $selectList[] = $fieldName->format('%n as %a');
      }
    }

    $selectList = empty($selectList) ? '*' : implode(', ', $selectList);
    $modifier = $oselect->hasModifier() ? $oselect->getModifier() : '';

    return CString::stripDoubleSpaces("select $modifier $selectList");
  }

  protected function buildFrom($query)
  {
    $from = $this->osuql->getQuery($query)->getFrom();

    if (!$from)
      return '';

    return $this->osuql->hasQuery($from)
            ? ' from '.$this->nestedQuery($from)
            : " from $from";
  }

  protected function buildJoin($query)
  {
    $join = $this->osuql->getQuery($query)->getJoin();

    if (empty($join))
      return '';

    $joinList = [];
    foreach ($join as $ojoin) {
      $table = $ojoin->getTable();
      $type = $ojoin->getType();
      $on = $ojoin->getOn();

      $table = $this->osuql->hasQuery($table)
                ? $this->nestedQuery($table)
                : $table;

      $joinList[] = "$type join $table on $on";
    }

    $joinList = ' ' . implode(' ', $joinList);

    return $joinList;
  }

  protected function buildGroup($query)
  {
    $group = $this->osuql->getQuery($query)->getGroup();

    if (empty($group))
      return '';

    $group = implode(', ', $group);

    return " group by $group";
  }

  protected function buildWhere($query)
  {
    $whereList = $this->osuql->getQuery($query)->getWhere();

    if (empty($whereList))
      return '';

    $fieldList = $this->osuql->getQuery($query)->getFieldList();

    foreach ($whereList as &$where) {
      $where = str_replace(array_values($fieldList), array_keys($fieldList), $where);
    }
    unset($where);

    $whereList = implode(' and ', $whereList);

    return " where $whereList";
  }

  protected function buildHaving($query)
  {
    $having = $this->osuql->getQuery($query)->getHaving();

    if (empty($having))
      return '';

    $having = implode(' and ', $having);
    return " having $having";
  }

  protected function buildOrder($query)
  {
    $order = $this->osuql->getQuery($query)->getOrder();

    if (empty($order))
      return '';

    $orderList = [];
    foreach ($order as $oorder) {
      $field = $oorder->getField();
      $direction = $oorder->getDirection();
      $orderList[] = "$field $direction";
    }

    $orderList = implode(', ', $orderList);

    return " order by $orderList";
  }

  protected function buildLimit($query)
  {
    $bound = [];
    $oselect = $this->osuql->getQuery($query);

    if ($oselect->hasOffset()) $bound[] = $oselect->getOffset();
    if ($oselect->hasLimit()) $bound[] = $oselect->getLimit();

    if (empty($bound))
      return '';

    $bound = implode(', ', $bound);

    return " limit $bound";
  }

  private function nestedQuery($table) {
    return SuQLSpecialSymbols::$prefix_declare_variable . "$table $table";
  }
}
