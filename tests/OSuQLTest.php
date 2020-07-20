<?php declare(strict_types = 1);
use PHPUnit\Framework\TestCase;

final class OSuQLTest extends TestCase
{
  private $db;

  private function init()
  {
    $this->db = new OSuQL;

    $this->db->rel(['users' => 'u'], ['user_group' => 'ug'], 'u.id = ug.user_id');
    $this->db->rel(['user_group' => 'ug'], ['groups' => 'g'], 'ug.group_id = g.id');

    $this->db->setAdapter('mysql');
  }

  public function testSelect(): void
  {
    $this->init();

    $this->db->select()
                ->users()
                  ->field('id')
                  ->field('name');

    $this->assertEquals($this->db->getSQL(), 'select users.id, users.name from users');
    $this->assertNull($this->db->getSQL());

    $this->db->select()
                ->users()
                  ->field('*');

    $this->assertEquals($this->db->getSQL(), 'select users.* from users');
    $this->assertNull($this->db->getSQL());

    $this->db->select()
                ->users();

    $this->assertEquals($this->db->getSQL(), 'select * from users');
    $this->assertNull($this->db->getSQL());

    $this->db->select()
                ->users()
                  ->field(['id' => 'uid'])
                  ->field('name@uname');

    $this->assertEquals($this->db->getSQL(), 'select users.id as uid, users.name as uname from users');
    $this->assertNull($this->db->getSQL());
  }

  public function testSelectWhere(): void
  {
    $this->init();

    $this->db->select()
                ->users()
                  ->field(['id' => 'uid'])
                  ->field(['name' => 'uname'])
                ->where('uid % 2 = 0');

    $this->assertEquals($this->db->getSQL(), 'select users.id as uid, users.name as uname from users where users.id % 2 = 0');
    $this->assertNull($this->db->getSQL());

    $this->db->query('users_belong_to_any_group')
                ->select()
                  ->user_group('distinct')
                    ->field('user_id');
    $this->db->query()
              ->select()
                ->users()
                  ->field('id@uid')
                  ->field('name')
                ->where('uid not in @users_belong_to_any_group');

    $this->assertEquals($this->db->getSQL(), 'select users.id as uid, users.name from users where users.id not in (select distinct user_group.user_id from user_group)');
    $this->assertNull($this->db->getSQL());
  }

  public function testSelectLimit(): void
  {
    $this->init();

    $this->db->select()
                ->users()
                  ->field('*')
                ->offset(0)
                ->limit(2);

    $this->assertEquals($this->db->getSQL(), 'select users.* from users limit 2');
    $this->assertNull($this->db->getSQL());
  }

  public function testSelectDistinct(): void
  {
    $this->init();

    $this->db->select()
                ->users('distinct')
                  ->field('name');

    $this->assertEquals($this->db->getSQL(), 'select distinct users.name from users');
    $this->assertNull($this->db->getSQL());
  }

  public function testSelectJoin(): void
  {
    $this->init();

    $this->db->select()
                ->users()
                ->user_group()
                ->groups()
                  ->field(['id' => 'gid'])
                  ->field(['name' => 'gname']);

    $this->assertEquals($this->db->getSQL(),
      'select '.
        'groups.id as gid, '.
        'groups.name as gname '.
      'from users '.
      'inner join user_group on users.id = user_group.user_id '.
      'inner join groups on user_group.group_id = groups.id'
    );
    $this->assertNull($this->db->getSQL());
  }

  public function testSelectGroup(): void
  {
    $this->init();

    $this->db->select()
                ->users()
                ->user_group()
                ->groups()
                  ->field('name@gname')
                  ->field('name@count')->group()->count()
                ->where("gname = 'admin'");

    $this->assertEquals($this->db->getSQL(),
      'select '.
        'groups.name as gname, '.
        'count(groups.name) as count '.
      'from users '.
      'inner join user_group on users.id = user_group.user_id '.
      'inner join groups on user_group.group_id = groups.id '.
      'where groups.name = \'admin\' '.
      'group by groups.name'
    );
    $this->assertNull($this->db->getSQL());
  }

  public function testNestedQueries(): void
  {
    $this->init();

    $this->db->query('allGroupCount')
                ->select()
                  ->users()
                  ->user_group()
                  ->groups()
                    ->field('name@gname')
                    ->field('name@count')->group()->count();
    $this->db->query()
                ->select()
                  ->allGroupCount()
                    ->field('gname')
                    ->field('count')
                  ->where("gname = 'admin'");

    $this->assertEquals($this->db->getSQL(),
      'select '.
        'allGroupCount.gname, '.
        'allGroupCount.count '.
      'from ('.
        'select '.
          'groups.name as gname, '.
          'count(groups.name) as count '.
        'from users '.
        'inner join user_group on users.id = user_group.user_id '.
        'inner join groups on user_group.group_id = groups.id '.
        'group by groups.name'.
      ') allGroupCount '.
      'where gname = \'admin\''
    );
    $this->assertNull($this->db->getSQL());
  }

  public function testSorting(): void
  {
    $this->init();

    $this->db->select()
                ->users()
                ->user_group()
                ->groups()
                  ->field('name@gname')
                  ->field('name@count')->group()->count()->asc();

    $this->assertEquals($this->db->getSQL(),
      'select '.
        'groups.name as gname, '.
        'count(groups.name) as count '.
      'from users '.
      'inner join user_group on users.id = user_group.user_id '.
      'inner join groups on user_group.group_id = groups.id '.
      'group by groups.name '.
      'order by count asc'
    );
    $this->assertNull($this->db->getSQL());
  }

  public function testUnion(): void
  {
    $this->init();

    $this->db->query('firstRegisration')
                ->select()
                  ->users()
                    ->field('registration@reg_interval')->min();
    $this->db->query('lastRegisration')
                ->select()
                  ->users()
                    ->field('registration@reg_interval')->max();
    $this->db->query()
                ->union('@firstRegisration')
                ->union('@lastRegisration');

    $this->assertEquals($this->db->getSQL(),
      '(select min(users.registration) as reg_interval from users) '.
        'union '.
      '(select max(users.registration) as reg_interval from users)'
    );
    $this->assertNull($this->db->getSQL());
  }
}
