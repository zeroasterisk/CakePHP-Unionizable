<?php
/**
 *
 *
 */

App::uses('Model', 'Model');
App::uses('ModelBehavior', 'Model');
App::uses('UnionizableBehavior', 'Unionize.Model/Behavior');

/**
 * Mock model
 */
class UnionizablePost extends CakeTestModel {
	public $alias = 'Post';
	public $useTable = 'posts';
	public $actsAs = array(
		'Unionize.Unionizable',
		'Search.Searchable',
	);
	public $findMethods = ['unionize' => true];
	public $belongsTo = ['Author'];
}

/**
 * Mock model
 */
class UnionizableUser extends CakeTestModel {
	public $alias = 'User';
	public $useTable = 'users';
	public $actsAs = array(
		'Unionize.Unionizable',
		'Search.Searchable',
	);
}

/**
 * UnionizableBehavior Test Case
 *
 */
class UnionizableBehaviorTest extends CakeTestCase {

	/**
	 * Fixtures
	 *
	 * @var array
	 */
	public $fixtures = array(
		'core.post',
		'core.author',
		'core.user',
	);

	/**
	 * setUp method
	 *
	 * @return void
	 */
	public function setUp() {
		parent::setUp();
		$this->Unionizable = new UnionizableBehavior();
		$this->Post = ClassRegistry::init('UnionizablePost');
		$this->User = ClassRegistry::init('UnionizableUser');
	}

	/**
	 * tearDown method
	 *
	 * @return void
	 */
	public function tearDown() {
		unset($this->Unionizable);
		unset($this->Post);
		unset($this->User);
		parent::tearDown();
	}

	/**
	 * test findSum or really find('sum', ...)
	 * a test of the customFind method setup in the Behavior
	 */
	public function testGetSetClearUnionConditions() {
		$this->assertEqual(
			$this->Post->unionizeGetConditions(),
			[]
		);
		$this->assertTrue(
			$this->Post->unionizeSetConditions(['title LIKE' => '%a%'])
		);
		$this->assertTrue(
			$this->Post->unionizeSetConditions(['title LIKE' => '%b%', 'created >' => '2001-01-01 01:01:01'])
		);
		$this->assertEqual(
			$this->Post->unionizeGetConditions(),
			[
				['title LIKE' => '%a%'],
				['title LIKE' => '%b%', 'created >' => '2001-01-01 01:01:01']
			]
		);
		$this->assertTrue(
			$this->Post->unionizeClearConditions()
		);
		$this->assertEqual(
			$this->Post->unionizeGetConditions(),
			[]
		);
	}

	public function testUnionizeFindCount() {
		$this->Post->unionizeSetConditions(['title LIKE' => '%second%']);
		$this->Post->unionizeSetConditions(['title LIKE' => '%third%', 'created >' => '2001-01-01 01:01:01']);
		$this->assertEqual(
			$this->Post->unionizeFind('count', ['recursive' => -1]),
			(
				$this->Post->find('count', ['recursive' => -1, 'conditions' => ['title LIKE' => '%second%']]) +
				$this->Post->find('count', ['recursive' => -1, 'conditions' => ['title LIKE' => '%third%', 'created >' => '2001-01-01 01:01:01']])
			)
		);
	}

	public function testUnionizeFindAll() {
		$this->Post->unionizeClearConditions();
		$this->Post->unionizeSetConditions(['title LIKE' => '%second%']);
		$this->Post->unionizeSetConditions(['title LIKE' => '%third%', 'created >' => '2001-01-01 01:01:01']);
		$this->assertEqual(
			$this->Post->unionizeFind('all', ['recursive' => -1]),
			array_merge(
				$this->Post->find('all', ['recursive' => -1, 'conditions' => ['title LIKE' => '%second%']]),
				$this->Post->find('all', ['recursive' => -1, 'conditions' => ['title LIKE' => '%third%', 'created >' => '2001-01-01 01:01:01']])
			)
		);
	}

	public function testUnionizeFindAllDuplicates() {
		// first + third & third
		$this->Post->unionizeClearConditions();
		$this->Post->unionizeSetConditions(['title LIKE' => '%i%']);
		$this->Post->unionizeSetConditions(['title LIKE' => '%third%']);
		$this->assertEqual(
			$this->Post->unionizeFind('all', [
				'fields' => ['Post.id', 'Post.title', 'Author.user'],
				'order' => ['Post.id' => 'asc'],
			]),
			$this->Post->find('all', [
				'conditions' => ['Post.id <>' => 2],
				'fields' => ['Post.id', 'Post.title', 'Author.user'],
				'order' => ['Post.id' => 'asc'],
			])
		);
	}

	public function testUnionizeFindCountDuplicates() {
		// first + third & third
		$this->Post->unionizeClearConditions();
		$this->Post->unionizeSetConditions(['title LIKE' => '%i%']);
		$this->Post->unionizeSetConditions(['title LIKE' => '%third%']);

		$this->assertEqual(
			$this->Post->find('count', ['conditions' => ['Post.id <>' => 2]]),
			2
		);
		$this->assertEqual(
			$this->Post->unionizeFind('count', []),
			$this->Post->find('count', ['conditions' => ['Post.id <>' => 2]])
		);
	}

	/*
	 * TODO: NOT yet working...
	 *   idea: could transalte all fields from select into placeholders
	 *         then could sort by whatever placeholders matches
	 *         might also allow us to more accuratly translate back into real fields afterwards
	 *
	public function testUnionizeFindAllOrderByAssociation() {
		// first + third & third
		$this->Post->unionizeClearConditions();
		$this->Post->unionizeSetConditions(['title LIKE' => '%i%']);
		$this->Post->unionizeSetConditions(['title LIKE' => '%third%']);
		$this->assertEqual(
			$this->Post->unionizeFind('all', [
				'fields' => ['Post.id', 'Post.title', 'Author.user'],
				'order' => ['Author.user' => 'asc'],
			]),
			$this->Post->find('all', [
				'conditions' => ['Post.id <>' => 2],
				'fields' => ['Post.id', 'Post.title', 'Author.user'],
				'order' => ['Author.user' => 'asc'],
			])
		);
	}
	*/

	public function testUnionizeGetQueryBasic() {
		$db = $this->Post->getDataSource()->getSchemaName();
		$this->Post->getDataSource(['title' => 'foobar']);
		$this->Post->unionizeSetConditions(['title' => 'foobar']);
		$sql = $this->Post->unionizeGetQuery('all', [
			'fields' => ['id'],
			'recursive' => -1,
			'order' => false,
			'limit' => false,
		]);
		$this->assertEqual(
			trim(trim(trim($sql), ')(')),
			"SELECT `Post`.`id` FROM `{$db}`.`posts` AS `Post`   WHERE `title` = 'foobar'"
		);
		$this->Post->unionizeSetConditions(['title' => 'junk&stuff']);
		$sql = $this->Post->unionizeGetQuery('all', [
			'fields' => ['id'],
			'recursive' => -1,
			'order' => false,
			'limit' => false,
		]);
		$this->assertEqual(
			$sql,
			"(

SELECT `Post`.`id` FROM `{$db}`.`posts` AS `Post`   WHERE `title` = 'foobar'

) UNION (

SELECT `Post`.`id` FROM `{$db}`.`posts` AS `Post`   WHERE `title` = 'junk&stuff'

) "
		);
	}

	public function testUnionizeGetQueryOrdered() {
		$db = $this->Post->getDataSource()->getSchemaName();
		$this->Post->getDataSource(['title' => 'foobar']);
		$this->Post->unionizeSetConditions(['title' => 'foobar']);
		$this->Post->unionizeSetConditions(['title' => 'junk&stuff']);
		$sql = $this->Post->unionizeGetQuery('all', [
			'fields' => ['id', 'title'],
			'recursive' => -1,
			'order' => ['Post.title' => 'asc', 'Post.id' => 'desc'],
			'limit' => false,
		]);
		$this->assertEqual(
			$sql,
			"(

SELECT `Post`.`id`, `Post`.`title` FROM `{$db}`.`posts` AS `Post`   WHERE `title` = 'foobar'

) UNION (

SELECT `Post`.`id`, `Post`.`title` FROM `sp_test`.`posts` AS `Post`   WHERE `title` = 'junk&stuff'

)   ORDER BY `title` asc, `id` desc"
		);
	}

}

