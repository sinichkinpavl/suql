<?php declare(strict_types = 1);
use PHPUnit\Framework\TestCase;

use core\SuQLObject;

final class SuQLObjectTest extends TestCase
{
  private $db;

  private function init()
  {
    $this->db = new SuQLObject;

    $this->db->rel(['users' => 'u'], ['user_group' => 'ug'], 'u.id = ug.user_id');
    $this->db->rel(['user_group' => 'ug'], ['groups' => 'g'], 'ug.group_id = g.id');

    $this->db->setAdapter('mysql');
  }

  public function testSelect(): void
  {
    $this->init();

    $this->db->addSelect('main');
    $this->db->getQuery('main')->addFrom('users');
    $this->db->getQuery('main')->addField('users', 'id@uid');
    $this->db->getQuery('main')->addField('users', 'name@uname');

    $this->assertEquals($this->db->getSQL('all'), 'select users.id as uid, users.name as uname from users');
    $this->assertNull($this->db->getSQL('all'));
  }

  public function testSelectDistinct(): void
  {
    $this->init();

    $this->db->addSelect('main');
    $this->db->getQuery('main')->addModifier('distinct');
    $this->db->getQuery('main')->addField('users', 'id');
    $this->db->getQuery('main')->addFrom('users');

    $this->assertEquals($this->db->getSQL('all'), 'select distinct users.id from users');
  }
}