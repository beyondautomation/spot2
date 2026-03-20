<?php

declare(strict_types=1);

namespace SpotTest;

/**
 * @package Spot
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class Insert extends \PHPUnit\Framework\TestCase
{
    private static array $entities = ['Post', 'Author', 'Event\Search', 'Event', 'NoSerial'];

    public static function setUpBeforeClass(): void
    {
        foreach (self::$entities as $entity) {
            test_spot_mapper('\SpotTest\Entity\\' . $entity)->migrate();
        }

        $authorMapper = test_spot_mapper(\SpotTest\Entity\Author::class);
        $author = $authorMapper->build([
            'id' => 1,
            'email' => 'example@example.com',
            'password' => 't00r',
            'is_admin' => false,
        ]);
        $result = $authorMapper->insert($author);

        if (!$result) {
            throw new \Exception('Unable to create author: ' . var_export($author->data(), true));
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$entities as $entity) {
            test_spot_mapper('\SpotTest\Entity\\' . $entity)->dropTable();
        }
    }

    public function testInsertPostEntity(): void
    {
        $post = new \SpotTest\Entity\Post();
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);
        $post->title = 'Test Post';
        $post->body = "<p>This is a really awesome super-duper post.</p><p>It's really quite lovely.</p>";
        $post->date_created = new \DateTime();
        $post->author_id = 1;

        $result = $mapper->insert($post);

        $this->assertTrue($result !== false);
        $this->assertTrue($post->id !== null);
        $this->assertTrue(! $post->isModified());
    }

    public function testInsertPostEntitySequencesAreCorrect(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);

        $post = new Entity\Post();
        $post->title = 'Test Post';
        $post->body = "<p>This is a really awesome super-duper post.</p><p>It's really quite lovely.</p>";
        $post->date_created = new \DateTime();
        $post->author_id = 1;

        $mapper->insert($post);

        $post2 = new Entity\Post();
        $post2->title = 'Test Post';
        $post2->body = "<p>This is a really awesome super-duper post.</p><p>It's really quite lovely.</p>";
        $post2->date_created = new \DateTime();
        $post2->author_id = 1;

        $mapper->insert($post2);

        // Ensure sequence is incrementing number
        $this->assertNotEquals($post->id, $post2->id);
    }

    public function testInsertPostArray(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);
        $post = [
            'title' => 'Test Post',
            'author_id' => 1,
            'body' => "<p>This is a really awesome super-duper post.</p><p>It's really quite lovely.</p>",
            'date_created' => new \DateTime(),
        ];
        $result = $mapper->insert($post); // returns inserted id

        $this->assertTrue($result !== false);
    }

    public function testCreateInsertsEntity(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);
        $post = [
            'title' => 'Test Post 101',
            'author_id' => 1,
            'body' => "<p>Test Post 101</p><p>It's really quite lovely.</p>",
            'date_created' => new \DateTime(),
        ];
        $result = $mapper->create($post);

        $this->assertTrue($result !== false);
    }

    public function testBuildReturnsEntityUnsaved(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);
        $post = [
            'title' => 'Test Post 100',
            'author_id' => 1,
            'body' => '<p>Test Post 100</p>',
            'date_created' => new \DateTime(),
        ];
        $result = $mapper->build($post);

        $this->assertInstanceOf(\SpotTest\Entity\Post::class, $result);
        $this->assertTrue($result->isNew());
        $this->assertNull($result->id);
    }

    public function testCreateReturnsEntity(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);
        $post = [
            'title' => 'Test Post 101',
            'author_id' => 1,
            'body' => '<p>Test Post 101</p>',
            'date_created' => new \DateTime(),
        ];
        $result = $mapper->create($post);

        $this->assertInstanceOf(\SpotTest\Entity\Post::class, $result);
        $this->assertFalse($result->isNew());
    }

    public function testInsertNewEntitySavesWithIdAlreadySet(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);
        $post = new \SpotTest\Entity\Post([
            'id' => 2001,
            'title' => 'Test Post 2001',
            'author_id' => 1,
            'body' => '<p>Test Post 2001</p>',
        ]);
        $mapper->insert($post);
        $entity = $mapper->get($post->id);

        $this->assertInstanceOf(\SpotTest\Entity\Post::class, $entity);
        $this->assertFalse($entity->isNew());
    }

    public function testInsertEventRunsValidation(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Event::class);
        $event = new \SpotTest\Entity\Event([
            'title' => 'Test Event 1',
            'description' => 'Test Description',
            'date_start' => new \DateTime('+1 day'),
        ]);
        $result = $mapper->insert($event);

        $this->assertFalse($result);
        $this->assertContains('Type is required', $event->errors('type'));
    }

    public function testSaveEventRunsAfterInsertHook(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Event::class);
        $event = new \SpotTest\Entity\Event([
            'title' => 'Test Event 1',
            'description' => 'Test Description',
            'type' => 'free',
            'date_start' => new \DateTime('+1 day'),
        ]);

        $result = $mapper->save($event);

        $this->assertTrue($result !== false);
    }

    public function testInsertEventRunsDateValidation(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Event::class);
        $event = new \SpotTest\Entity\Event([
            'title' => 'Test Event 1',
            'description' => 'Test Description',
            'type' => 'vip',
            'date_start' => new \DateTime('-1 day'),
        ]);
        $result = $mapper->insert($event);
        $dsErrors = $event->errors('date_start');

        $this->assertFalse($result);
        $this->assertStringContainsString('Date Start must be date after', $dsErrors[0]);
    }

    public function testInsertEventRunsTypeOptionsValidation(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Event::class);
        $event = new \SpotTest\Entity\Event([
            'title' => 'Test Event 1',
            'description' => 'Test Description',
            'type' => 'invalid_value',
            'date_start' => new \DateTime('+1 day'),
        ]);
        $result = $mapper->insert($event);

        $this->assertFalse($result);
        $this->assertEquals(['Type contains invalid value'], $event->errors('type'));
    }

    public function testCreateWithErrorsThrowsException(): void
    {
        $this->expectException(\Spot\Exception::class);
        $mapper = test_spot_mapper(\SpotTest\Entity\Event::class);
        $mapper->create([
            'title' => 'Test Event 1',
            'description' => 'Test Description',
            'date_start' => new \DateTime('+1 day'),
        ]);
    }

    public function testInsertWithoutAutoIncrement(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\NoSerial::class);
        $entity = $mapper->build([
            'id' => 101,
            'data' => 'Testing insert',
        ]);
        $result = $mapper->insert($entity);

        $this->assertEquals(101, $result);
    }

    public function testInsertWithoutAutoIncrementWithoutPKValueHasValidationError(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\NoSerial::class);
        $entity = $mapper->build([
            'data' => 'Testing insert',
        ]);
        $result = $mapper->insert($entity);

        $this->assertEquals(false, $result);
        $this->assertEquals(1, count($entity->errors('id')));
    }
}
