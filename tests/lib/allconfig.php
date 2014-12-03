<?php
/**
 * Copyright (c) 2014 Morris Jobke <hey@morrisjobke.de>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace Test;

class TestAllConfig extends \Test\TestCase {

	/** @var  \OCP\IDBConnection */
	protected $connection;

	protected function getConfig($connection = null) {
		if($this->connection === null) {
			$this->connection = \OC::$server->getDatabaseConnection();
		}
		if($connection === null) {
			$connection = $this->connection;
		}
		return new \OC\AllConfig(
			$this->getMock('\OC\SystemConfig'), $connection);
	}

	public function testDeleteUserValue() {
		$config = $this->getConfig();

		// preparation - add something to the database
		$this->connection->executeUpdate(
			'INSERT INTO `*PREFIX*preferences` (`userid`, `appid`, ' .
			'`configkey`, `configvalue`) VALUES (?, ?, ?, ?)',
			array('userDelete', 'appDelete', 'keyDelete', 'valueDelete')
		);

		$config->deleteUserValue('userDelete', 'appDelete', 'keyDelete');

		$result = $this->connection->executeQuery(
				'SELECT COUNT(*) FROM `*PREFIX*preferences`'
			)->fetch();
		$actualCount = $result['COUNT(*)'];

		$this->assertEquals(0, $actualCount);
	}

	public function testSetUserValue() {
		$selectAllSQL = 'SELECT `userid`, `appid`, `configkey`, `configvalue` FROM `*PREFIX*preferences`';
		$config = $this->getConfig();

		$config->setUserValue('userSet', 'appSet', 'keySet', 'valueSet');

		$result = $this->connection->executeQuery($selectAllSQL)->fetchAll();

		$this->assertEquals(1, count($result));
		$this->assertEquals(array(
			'userid'      => 'userSet',
			'appid'       => 'appSet',
			'configkey'   => 'keySet',
			'configvalue' => 'valueSet'
		), $result[0]);

		// test if the method overwrites existing database entries
		$config->setUserValue('userSet', 'appSet', 'keySet', 'valueSet2');

		$result = $this->connection->executeQuery($selectAllSQL)->fetchAll();

		$this->assertEquals(1, count($result));
		$this->assertEquals(array(
			'userid'      => 'userSet',
			'appid'       => 'appSet',
			'configkey'   => 'keySet',
			'configvalue' => 'valueSet2'
		), $result[0]);

		// cleanup - it therefore relies on the successful execution of the previous test
		$config->deleteUserValue('userSet', 'appSet', 'keySet');
	}

	public function testSetUserValueUnchanged() {
		$resultMock = $this->getMockBuilder('\Doctrine\DBAL\Driver\Statement')
			->disableOriginalConstructor()->getMock();
		$resultMock->expects($this->once())
			->method('fetchColumn')
			->will($this->returnValue('valueSetUnchanged'));

		$connectionMock = $this->getMock('\OCP\IDBConnection');
		$connectionMock->expects($this->once())
			->method('executeQuery')
			->with($this->equalTo('SELECT `configvalue` FROM `*PREFIX*preferences` '.
					'WHERE `userid` = ? AND `appid` = ? AND `configkey` = ?'),
				$this->equalTo(array('userSetUnchanged', 'appSetUnchanged', 'keySetUnchanged')))
			->will($this->returnValue($resultMock));
		$connectionMock->expects($this->never())
			->method('executeUpdate');

		$config = $this->getConfig($connectionMock);

		$config->setUserValue('userSetUnchanged', 'appSetUnchanged', 'keySetUnchanged', 'valueSetUnchanged');
	}

	public function testGetUserValue() {
		$config = $this->getConfig();

		// setup - it therefore relies on the successful execution of the previous test
		$config->setUserValue('userGet', 'appGet', 'keyGet', 'valueGet');
		$value = $config->getUserValue('userGet', 'appGet', 'keyGet');

		$this->assertEquals('valueGet', $value);

		$result = $this->connection->executeQuery(
			'SELECT `userid`, `appid`, `configkey`, `configvalue` FROM `*PREFIX*preferences`'
		)->fetchAll();

		$this->assertEquals(1, count($result));
		$this->assertEquals(array(
			'userid'      => 'userGet',
			'appid'       => 'appGet',
			'configkey'   => 'keyGet',
			'configvalue' => 'valueGet'
		), $result[0]);

		// drop data from database - but the config option should be cached in the config object
		$this->connection->executeUpdate('DELETE FROM `*PREFIX*preferences`');

		// testing the caching mechanism
		$value = $config->getUserValue('userGet', 'appGet', 'keyGet');

		$this->assertEquals('valueGet', $value);

		$result = $this->connection->executeQuery(
			'SELECT `userid`, `appid`, `configkey`, `configvalue` FROM `*PREFIX*preferences`'
		)->fetchAll();

		$this->assertEquals(0, count($result));
	}

	public function testGetUserKeys() {
		$config = $this->getConfig();

		// preparation - add something to the database
		$data = array(
			array('userFetch', 'appFetch1', 'keyFetch1', 'value1'),
			array('userFetch', 'appFetch1', 'keyFetch2', 'value2'),
			array('userFetch', 'appFetch2', 'keyFetch3', 'value3'),
			array('userFetch', 'appFetch1', 'keyFetch4', 'value4'),
			array('userFetch', 'appFetch4', 'keyFetch1', 'value5'),
			array('userFetch', 'appFetch5', 'keyFetch1', 'value6'),
			array('userFetch2', 'appFetch', 'keyFetch1', 'value7')
		);
		foreach ($data as $entry) {
			$this->connection->executeUpdate(
				'INSERT INTO `*PREFIX*preferences` (`userid`, `appid`, ' .
				'`configkey`, `configvalue`) VALUES (?, ?, ?, ?)',
				$entry
			);
		}

		$value = $config->getUserKeys('userFetch', 'appFetch1');
		$this->assertEquals(array('keyFetch1', 'keyFetch2', 'keyFetch4'), $value);

		$value = $config->getUserKeys('userFetch2', 'appFetch');
		$this->assertEquals(array('keyFetch1'), $value);

		// cleanup
		$this->connection->executeUpdate('DELETE FROM `*PREFIX*preferences`');
	}

	public function testGetUserValueDefault() {
		$config = $this->getConfig();

		$this->assertEquals('', $config->getUserValue('userGetUnset', 'appGetUnset', 'keyGetUnset'));
		$this->assertEquals(null, $config->getUserValue('userGetUnset', 'appGetUnset', 'keyGetUnset', null));
		$this->assertEquals('foobar', $config->getUserValue('userGetUnset', 'appGetUnset', 'keyGetUnset', 'foobar'));
	}

	public function testGetUserValueForUsers() {
		$config = $this->getConfig();

		// preparation - add something to the database
		$data = array(
			array('userFetch1', 'appFetch2', 'keyFetch1', 'value1'),
			array('userFetch2', 'appFetch2', 'keyFetch1', 'value2'),
			array('userFetch3', 'appFetch2', 'keyFetch1', 3),
			array('userFetch4', 'appFetch2', 'keyFetch1', 'value4'),
			array('userFetch5', 'appFetch2', 'keyFetch1', 'value5'),
			array('userFetch6', 'appFetch2', 'keyFetch1', 'value6'),
			array('userFetch7', 'appFetch2', 'keyFetch1', 'value7')
		);
		foreach ($data as $entry) {
			$this->connection->executeUpdate(
				'INSERT INTO `*PREFIX*preferences` (`userid`, `appid`, ' .
				'`configkey`, `configvalue`) VALUES (?, ?, ?, ?)',
				$entry
			);
		}

		$value = $config->getUserValueForUsers('appFetch2', 'keyFetch1',
			array('userFetch1', 'userFetch2', 'userFetch3', 'userFetch5'));
		$this->assertEquals(array(
				'userFetch1' => 'value1',
				'userFetch2' => 'value2',
				'userFetch3' => 3,
				'userFetch5' => 'value5'
			), $value);

		$value = $config->getUserValueForUsers('appFetch2', 'keyFetch1',
			array('userFetch1', 'userFetch4', 'userFetch9'));
		$this->assertEquals(array(
			'userFetch1' => 'value1',
			'userFetch4' => 'value4'
		), $value, 'userFetch9 is an non-existent user and should not be shown.');

		// cleanup
		$this->connection->executeUpdate('DELETE FROM `*PREFIX*preferences`');
	}

	public function testDeleteAllUserValues() {
		$config = $this->getConfig();

		// preparation - add something to the database
		$data = array(
			array('userFetch3', 'appFetch1', 'keyFetch1', 'value1'),
			array('userFetch3', 'appFetch1', 'keyFetch2', 'value2'),
			array('userFetch3', 'appFetch2', 'keyFetch3', 'value3'),
			array('userFetch3', 'appFetch1', 'keyFetch4', 'value4'),
			array('userFetch3', 'appFetch4', 'keyFetch1', 'value5'),
			array('userFetch3', 'appFetch5', 'keyFetch1', 'value6'),
			array('userFetch4', 'appFetch2', 'keyFetch1', 'value7')
		);
		foreach ($data as $entry) {
			$this->connection->executeUpdate(
				'INSERT INTO `*PREFIX*preferences` (`userid`, `appid`, ' .
				'`configkey`, `configvalue`) VALUES (?, ?, ?, ?)',
				$entry
			);
		}

		$config->deleteAllUserValues('userFetch3');

		$result = $this->connection->executeQuery(
			'SELECT COUNT(*) FROM `*PREFIX*preferences`'
		)->fetch();
		$actualCount = $result['COUNT(*)'];

		$this->assertEquals(1, $actualCount, 'After removing `userFetch3` there should be exactly 1 entry left.');

		// cleanup
		$this->connection->executeUpdate('DELETE FROM `*PREFIX*preferences`');
	}

	public function testDeleteAppFromAllUsers() {
		$config = $this->getConfig();

		// preparation - add something to the database
		$data = array(
			array('userFetch5', 'appFetch1', 'keyFetch1', 'value1'),
			array('userFetch5', 'appFetch1', 'keyFetch2', 'value2'),
			array('userFetch5', 'appFetch2', 'keyFetch3', 'value3'),
			array('userFetch5', 'appFetch1', 'keyFetch4', 'value4'),
			array('userFetch5', 'appFetch4', 'keyFetch1', 'value5'),
			array('userFetch5', 'appFetch5', 'keyFetch1', 'value6'),
			array('userFetch6', 'appFetch2', 'keyFetch1', 'value7')
		);
		foreach ($data as $entry) {
			$this->connection->executeUpdate(
				'INSERT INTO `*PREFIX*preferences` (`userid`, `appid`, ' .
				'`configkey`, `configvalue`) VALUES (?, ?, ?, ?)',
				$entry
			);
		}

		$config->deleteAppFromAllUsers('appFetch1');

		$result = $this->connection->executeQuery(
			'SELECT COUNT(*) FROM `*PREFIX*preferences`'
		)->fetch();
		$actualCount = $result['COUNT(*)'];

		$this->assertEquals(4, $actualCount, 'After removing `appFetch1` there should be exactly 4 entries left.');

		$config->deleteAppFromAllUsers('appFetch2');

		$result = $this->connection->executeQuery(
			'SELECT COUNT(*) FROM `*PREFIX*preferences`'
		)->fetch();
		$actualCount = $result['COUNT(*)'];

		$this->assertEquals(2, $actualCount, 'After removing `appFetch2` there should be exactly 2 entries left.');

		// cleanup
		$this->connection->executeUpdate('DELETE FROM `*PREFIX*preferences`');
	}

}