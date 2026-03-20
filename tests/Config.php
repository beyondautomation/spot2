<?php

declare(strict_types=1);

namespace SpotTest;

/**
 * @package Spot
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class Config extends \PHPUnit\Framework\TestCase
{
    public function testAddConnectionSqlite(): void
    {
        $cfg = new \Spot\Config();
        $dsnp = $cfg->parseDsn('sqlite::memory:');
        $this->assertEquals('pdo_sqlite', $dsnp['driver']);

        $adapter = $cfg->addConnection('test_sqlite', 'sqlite::memory:');
        $this->assertInstanceOf(\Doctrine\DBAL\Connection::class, $adapter);
    }

    public function testAddSqliteConnectionWithDSNString(): void
    {
        $cfg = new \Spot\Config();
        $adapter = $cfg->addConnection('test_sqlite', 'sqlite::memory:');
        $this->assertInstanceOf(\Doctrine\DBAL\Connection::class, $adapter);
    }

    public function testAddConnectionWithDSNString(): void
    {
        $cfg = new \Spot\Config();
        $adapter = $cfg->addConnection('test_mysql', 'mysql://test:password@localhost/test');
        $this->assertInstanceOf(\Doctrine\DBAL\Connection::class, $adapter);
    }

    public function testConfigCanSerialize(): void
    {
        $cfg = new \Spot\Config();
        $cfg->addConnection('test_mysql', 'mysql://test:password@localhost/test');

        $this->assertIsString(serialize($cfg));
    }

    public function testConfigCanUnserialize(): void
    {
        $cfg = new \Spot\Config();
        $cfg->addConnection('test_mysql', 'mysql://test:password@localhost/test');

        $this->assertInstanceOf(\Spot\Config::class, unserialize(serialize($cfg)));
    }

    public function testAddConnectionWithArray(): void
    {
        $cfg = new \Spot\Config();
        $dbalArray = [
            'dbname' => 'spot_test',
            'user' => 'test',
            'password' => 'password',
            'host' => 'localhost',
            'driver' => 'pdo_mysql',
        ];
        $adapter = $cfg->addConnection('test_array', $dbalArray);
        $this->assertInstanceOf(\Doctrine\DBAL\Connection::class, $adapter);
    }

    public function testAddConnectionWithExistingDBALConnection(): void
    {
        $cfg = new \Spot\Config();
        $dbalArray = [
            'dbname' => 'spot_test',
            'user' => 'test',
            'password' => 'password',
            'host' => 'localhost',
            'driver' => 'pdo_mysql',
        ];

        $config = new \Doctrine\DBAL\Configuration();
        $connection = \Doctrine\DBAL\DriverManager::getConnection($dbalArray, $config);

        $adapter = $cfg->addConnection('test_dbalconnection', $connection);
        $this->assertInstanceOf(\Doctrine\DBAL\Connection::class, $adapter);
    }
}
