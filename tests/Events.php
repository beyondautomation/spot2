<?php

declare(strict_types=1);

namespace SpotTest;

/**
 * @package Spot
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class Events extends \PHPUnit\Framework\TestCase
{
    private static array $entities = ['PostTag', 'Post\Comment', 'Post', 'Tag', 'Author'];

    public static function setUpBeforeClass(): void
    {
        foreach (self::$entities as $entity) {
            test_spot_mapper('\SpotTest\Entity\\' . $entity)->migrate();
        }

        // Insert blog dummy data
        for ($i = 1; $i <= 3; $i++) {
            $tag_id = test_spot_mapper(\SpotTest\Entity\Tag::class)->insert([
                'name' => "Title {$i}",
            ]);
        }

        for ($i = 1; $i <= 4; $i++) {
            $author_id = test_spot_mapper(\SpotTest\Entity\Author::class)->insert([
                'email' => $i.'user@somewhere.com',
                'password' => 'securepassword',
            ]);
        }

        $postMapper = test_spot_mapper(\SpotTest\Entity\Post::class);
        for ($i = 1; $i <= 10; $i++) {
            $post = $postMapper->build([
                'title' => ($i % 2 !== 0 ? 'odd' : 'even'). '_title',
                'body' => '<p>' . $i  . '_body</p>',
                'status' => $i,
                'date_created' => new \DateTime(),
                'author_id' => random_int(1, 3),
            ]);
            $result = $postMapper->insert($post);

            if (!$result) {
                throw new \Exception('Unable to create post: ' . var_export($post->data(), true));
            }

            for ($j = 1; $j <= 2; $j++) {
                test_spot_mapper(\SpotTest\Entity\Post\Comment::class)->insert([
                    'post_id' => $post->id,
                    'name' => ($j % 2 !== 0 ? 'odd' : 'even'). '_title',
                    'email' => 'bob@somewhere.com',
                    'body' => ($j % 2 !== 0 ? 'odd' : 'even'). '_comment_body',
                ]);
            }

            for ($j = 1; $j <= $i % 3; $j++) {
                $posttag_id = test_spot_mapper(\SpotTest\Entity\PostTag::class)->insert([
                    'post_id' => $post->id,
                    'tag_id' => $j,
                ]);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$entities as $entity) {
            test_spot_mapper('\SpotTest\Entity\\' . $entity)->dropTable();
        }
    }

    protected function setUp(): void
    {
        Entity\Post::$events = [];
    }

    public function testSaveHooks(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);

        $post = new \SpotTest\Entity\Post([
            'title' => 'A title',
            'body' => '<p>body</p>',
            'status' => 1,
            'author_id' => 1,
        ]);

        $hooks = [];

        $testcase = $this;

        $eventEmitter = $mapper->eventEmitter();
        $eventEmitter->on('beforeSave', function ($post, $mapper) use (&$hooks, $testcase): void {
            $testcase->assertEquals($hooks, []);
            $hooks[] = 'called beforeSave';
        });

        $eventEmitter->on('afterSave', function ($post, $mapper, $result) use (&$hooks, $testcase): void {
            $testcase->assertEquals($hooks, ['called beforeSave']);
            $testcase->assertInstanceOf(\SpotTest\Entity\Post::class, $post);
            $testcase->assertInstanceOf(\Spot\Mapper::class, $mapper);
            $hooks[] = 'called afterSave';
        });

        $this->assertEquals($hooks, []);

        $mapper->save($post);

        $this->assertEquals(['called beforeSave', 'called afterSave'], $hooks);

        $eventEmitter->removeAllListeners('afterSave');
        $eventEmitter->removeAllListeners('beforeSave');

        $mapper->save($post);

        // Verify that hooks were deregistered (not called again)
        $this->assertEquals(['called beforeSave', 'called afterSave'], $hooks);
    }

    public function testInsertHooks(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);

        $post = new \SpotTest\Entity\Post([
            'title' => 'A title',
            'body' => '<p>body</p>',
            'status' => 1,
            'author_id' => 1,
            'date_created' => new \DateTime(),
        ]);

        $hooks = [];

        $testcase = $this;

        $eventEmitter = $mapper->eventEmitter();
        $eventEmitter->on('beforeInsert', function ($post, $mapper) use (&$hooks, $testcase): void {
            $testcase->assertEquals($hooks, []);
            $hooks[] = 'called beforeInsert';
        });

        $eventEmitter->on('afterInsert', function ($post, $mapper, $result) use (&$hooks, $testcase): void {
            $testcase->assertEquals($hooks, ['called beforeInsert']);
            $hooks[] = 'called afterInsert';
        });

        $this->assertEquals($hooks, []);

        $mapper->save($post);

        $this->assertEquals($hooks, ['called beforeInsert', 'called afterInsert']);

        $eventEmitter->removeAllListeners('beforeInsert');
        $eventEmitter->removeAllListeners('afterInsert');
    }

    public function testInsertHooksUpdatesProperty(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);
        $post = new \SpotTest\Entity\Post([
            'title' => 'A title',
            'body' => '<p>body</p>',
            'status' => 1,
            'author_id' => 4,
            'date_created' => new \DateTime(),
        ]);

        $eventEmitter = $mapper->eventEmitter();
        $eventEmitter->on('beforeInsert', function ($post, $mapper): void {
            $post->status = 2;
        });
        $mapper->save($post);
        $post = $mapper->first(['author_id' => 4]);
        $this->assertEquals(2, $post->status);

        $eventEmitter->removeAllListeners('beforeInsert');
    }

    public function testUpdateHooks(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);

        $post = new \SpotTest\Entity\Post([
            'title' => 'A title',
            'body' => '<p>body</p>',
            'status' => 1,
            'author_id' => 1,
            'date_created' => new \DateTime(),
        ]);
        $mapper->save($post);

        $hooks = [];

        $testcase = $this;

        $eventEmitter = $mapper->eventEmitter();
        $eventEmitter->on('beforeInsert', function ($post, $mapper) use ($testcase): void {
            $testcase->assertTrue(false);
        });

        $eventEmitter->on('beforeUpdate', function ($post, $mapper) use (&$hooks, $testcase): void {
            $testcase->assertEquals($hooks, []);
            $hooks[] = 'called beforeUpdate';
        });

        $eventEmitter->on('afterUpdate', function ($post, $mapper, $result) use (&$hooks, $testcase): void {
            $testcase->assertEquals($hooks, ['called beforeUpdate']);
            $hooks[] = 'called afterUpdate';
        });

        $this->assertEquals($hooks, []);

        $mapper->save($post);

        $this->assertEquals($hooks, ['called beforeUpdate', 'called afterUpdate']);

        $eventEmitter->removeAllListeners('beforeInsert');
        $eventEmitter->removeAllListeners('beforeUpdate');
        $eventEmitter->removeAllListeners('afterUpdate');
    }

    public function testUpdateHookUpdatesProperly(): void
    {
        $author_id = __LINE__;
        test_spot_mapper(\SpotTest\Entity\Author::class)->insert([
            'id' => $author_id,
            'email' => $author_id.'user@somewhere.com',
            'password' => 'securepassword',
        ]);

        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);

        $post = new \SpotTest\Entity\Post([
            'title' => 'A title',
            'body' => '<p>body</p>',
            'status' => 1,
            'author_id' => $author_id,
            'date_created' => new \DateTime(),
        ]);
        $mapper->save($post);
        $this->assertEquals(1, $post->status);

        $eventEmitter = $mapper->eventEmitter();
        $eventEmitter->on('beforeUpdate', function ($post, $mapper): void {
            $post->status = 9;
        });
        $mapper->save($post);
        $post = $mapper->first(['author_id' => $author_id]);
        $this->assertEquals(9, $post->status);

        $eventEmitter->removeAllListeners('beforeUpdate');
    }

    public function testDeleteHooks(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);

        $post = new \SpotTest\Entity\Post([
            'title' => 'A title',
            'body' => '<p>body</p>',
            'status' => 1,
            'author_id' => 1,
            'date_created' => new \DateTime(),
        ]);
        $mapper->save($post);

        $hooks = [];

        $testcase = $this;

        $eventEmitter = $mapper->eventEmitter();
        $eventEmitter->on('beforeDelete', function ($post, $mapper) use (&$hooks, $testcase): void {
            $testcase->assertEquals($hooks, []);
            $hooks[] = 'called beforeDelete';
        });

        $eventEmitter->on('afterDelete', function ($post, $mapper, $result) use (&$hooks, $testcase): void {
            $testcase->assertEquals($hooks, ['called beforeDelete']);
            $hooks[] = 'called afterDelete';
        });

        $this->assertEquals($hooks, []);

        $mapper->delete($post);

        $this->assertEquals($hooks, ['called beforeDelete', 'called afterDelete']);

        $eventEmitter->removeAllListeners('beforeDelete');
        $eventEmitter->removeAllListeners('afterDelete');
    }

    public function testDeleteHooksForArrayConditions(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);

        $post = new \SpotTest\Entity\Post([
            'title' => 'A title',
            'body' => '<p>body</p>',
            'status' => 1,
            'author_id' => 1,
            'date_created' => new \DateTime(),
        ]);
        $mapper->save($post);

        $entityHooks = [];
        $arrayHooks = [];

        $testcase = $this;

        $eventEmitter = $mapper->eventEmitter();
        $eventEmitter->on('beforeDelete', function ($conditions, $mapper) use (&$entityHooks): void {
            $entityHooks[] = 'called beforeDelete';
        });
        $eventEmitter->on('beforeDeleteConditions', function ($conditions, $mapper) use (&$arrayHooks, $testcase): void {
            $testcase->assertEquals($arrayHooks, []);
            $arrayHooks[] = 'called beforeDeleteConditions';
        });

        $eventEmitter->on('afterDelete', function ($conditions, $mapper, $result) use (&$entityHooks): void {
            $entityHooks[] = 'called afterDelete';
        });
        $eventEmitter->on('afterDeleteConditions', function ($conditions, $mapper, $result) use (&$arrayHooks, $testcase): void {
            $testcase->assertEquals($arrayHooks, ['called beforeDeleteConditions']);
            $arrayHooks[] = 'called afterDeleteConditions';
        });

        $this->assertEquals($entityHooks, []);
        $this->assertEquals($arrayHooks, []);

        $mapper->delete([
            $post->primaryKeyField() => $post->primaryKey(),
        ]);

        $this->assertEquals($entityHooks, []);
        $this->assertEquals($arrayHooks, ['called beforeDeleteConditions', 'called afterDeleteConditions']);

        $eventEmitter->removeAllListeners('beforeDelete');
        $eventEmitter->removeAllListeners('beforeDeleteConditions');
        $eventEmitter->removeAllListeners('afterDelete');
        $eventEmitter->removeAllListeners('afterDeleteConditions');
    }

    public function testEntityHooks(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);
        $eventEmitter = $mapper->eventEmitter();
        $post = new \SpotTest\Entity\Post([
            'title' => 'A title',
            'body' => '<p>body</p>',
            'status' => 1,
            'author_id' => 1,
            'date_created' => new \DateTime(),
        ]);

        $i = $post->status;

        \SpotTest\Entity\Post::$events = [
            'beforeSave' => ['mock_save_hook'],
        ];
        $mapper->loadEvents();

        $mapper->save($post);

        $this->assertEquals($i + 1, $post->status);
        $eventEmitter->removeAllListeners('beforeSave');

        \SpotTest\Entity\Post::$events = [
            'beforeSave' => ['mock_save_hook', 'mock_save_hook'],
        ];
        $mapper->loadEvents();

        $i = $post->status;

        $mapper->save($post);

        $this->assertEquals($i + 2, $post->status);

        $eventEmitter->removeAllListeners('beforeSave');
    }

    public function testWithHooks(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);
        $eventEmitter = $mapper->eventEmitter();
        $testcase = $this;

        $hooks = [];

        $eventEmitter->on('beforeWith', function ($mapper, $collection, $with) use (&$hooks, $testcase): void {
            $testcase->assertEquals(\SpotTest\Entity\Post::class, $mapper->entity());
            $testcase->assertInstanceOf(\Spot\Entity\Collection::class, $collection);
            $testcase->assertEquals(['comments'], $with);
            $testcase->assertInstanceOf(\Spot\Mapper::class, $mapper);
            $hooks[] = 'Called beforeWith';
        });

        $eventEmitter->on('loadWith', function ($mapper, $collection, $relationName) use (&$hooks, $testcase): void {
            $testcase->assertEquals(\SpotTest\Entity\Post::class, $mapper->entity());
            $testcase->assertInstanceOf(\Spot\Entity\Collection::class, $collection);
            $testcase->assertInstanceOf(\Spot\Mapper::class, $mapper);
            $testcase->assertEquals('comments', $relationName);
            $hooks[] = 'Called loadWith';
        });

        $eventEmitter->on('afterWith', function ($mapper, $collection, $with) use (&$hooks, $testcase): void {
            $testcase->assertEquals(\SpotTest\Entity\Post::class, $mapper->entity());
            $testcase->assertInstanceOf(\Spot\Entity\Collection::class, $collection);
            $testcase->assertEquals(['comments'], $with);
            $testcase->assertInstanceOf(\Spot\Mapper::class, $mapper);
            $hooks[] = 'Called afterWith';
        });

        $mapper->all()->with('comments')->execute();

        $this->assertEquals(['Called beforeWith', 'Called loadWith', 'Called afterWith'], $hooks);
        $eventEmitter->removeAllListeners();
    }

    public function testWithAssignmentHooks(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);
        $eventEmitter = $mapper->eventEmitter();

        $eventEmitter->on('loadWith', function ($mapper, $collection, $relationName): false {
            foreach ($collection as $post) {
                $comments = [];
                $comments[] = new \SpotTest\Entity\Post\Comment([
                    'post_id' => $post->id,
                    'name'    => 'Chester Tester',
                    'email'   => 'chester@tester.com',
                    'body'    => 'Some body content here that Chester made!',
                ]);

                $post->relation($relationName, new \Spot\Entity\Collection($comments));
            }

            return false;
        });

        $posts = $mapper->all()->with('comments')->execute();

        foreach ($posts as $post) {
            $this->assertEquals(1, $post->comments->count());
        }

        $eventEmitter->removeAllListeners();
    }

    public function testHookReturnsFalse(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);
        $post = new \SpotTest\Entity\Post([
            'title' => 'A title',
            'body' => '<p>body</p>',
            'status' => 1,
            'author_id' => 1,
            'date_created' => new \DateTime(),
        ]);

        $hooks = [];

        $eventEmitter = $mapper->eventEmitter();
        $eventEmitter->on('beforeSave', function ($post, $mapper) use (&$hooks): false {
            $hooks[] = 'called beforeSave';

            return false;
        });

        $eventEmitter->on('afterSave', function ($post, $mapper, $result) use (&$hooks): void {
            $hooks[] = 'called afterSave';
        });

        $mapper->save($post);

        $this->assertEquals($hooks, ['called beforeSave']);

        $eventEmitter->removeAllListeners('afterSave');
    }

    public function testAfterSaveEvent(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);
        $eventEmitter = $mapper->eventEmitter();
        $post = new \SpotTest\Entity\Post([
            'title' => 'A title',
            'body' => '<p>body</p>',
            'status' => 1,
            'author_id' => 1,
            'date_created' => new \DateTime(),
        ]);

        $eventEmitter->removeAllListeners('beforeSave');
        $eventEmitter->removeAllListeners('afterSave');

        \SpotTest\Entity\Post::$events = [
            'afterSave' => ['mock_save_hook'],
        ];
        $mapper->loadEvents();

        $mapper->save($post);

        $this->assertEquals(2, $post->status);

        $eventEmitter->removeAllListeners('afterSave');
    }

    public function testValidationEvents(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);
        $eventEmitter = $mapper->eventEmitter();
        $post = new \SpotTest\Entity\Post([
            'title' => 'A title',
            'body' => '<p>body</p>',
            'status' => 1,
            'author_id' => 1,
            'date_created' => new \DateTime(),
        ]);

        $hooks = [];
        $eventEmitter = $mapper->eventEmitter();
        $eventEmitter->on('beforeValidate', function ($post, $mapper, $validator) use (&$hooks): void {
            $hooks[] = 'called beforeValidate';
        });
        $eventEmitter->on('afterValidate', function ($post, $mapper, $validator) use (&$hooks): void {
            $hooks[] = 'called afterValidate';
        });

        $mapper->validate($post);

        $this->assertEquals(['called beforeValidate', 'called afterValidate'], $hooks);

        $eventEmitter->removeAllListeners();
    }

    public function testBeforeValidateEventStopsValidation(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);
        $eventEmitter = $mapper->eventEmitter();
        $post = new \SpotTest\Entity\Post([
            'title' => 'A title',
            'body' => '<p>body</p>',
            'status' => 1,
            'author_id' => 1,
            'date_created' => new \DateTime(),
        ]);

        $hooks = [];
        $eventEmitter = $mapper->eventEmitter();
        $eventEmitter->on('beforeValidate', function ($post, $mapper, $validator) use (&$hooks): false {
            $hooks[] = 'called beforeValidate';

            return false; // Should stop validation
        });
        $eventEmitter->on('afterValidate', function ($post, $mapper, $validator) use (&$hooks): void {
            $hooks[] = 'called afterValidate';
        });

        $mapper->validate($post);

        $this->assertEquals(['called beforeValidate'], $hooks);

        $eventEmitter->removeAllListeners();
    }

    public function testSaveEventsTriggeredOnCreate(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);

        $hooks = [];
        $eventEmitter = $mapper->eventEmitter();
        $eventEmitter->on('beforeSave', function ($post, $mapper) use (&$hooks): void {
            $hooks[] = 'before';
        });
        $eventEmitter->on('afterSave', function ($post, $mapper) use (&$hooks): void {
            $hooks[] = 'after';
        });

        $mapper->create([
            'title' => 'A title',
            'body' => '<p>body</p>',
            'status' => 1,
            'author_id' => 1,
            'date_created' => new \DateTime(),
        ]);

        $this->assertEquals(['before', 'after'], $hooks);
        $eventEmitter->removeAllListeners();
    }

    public function testLoadEventCallOnGet(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);

        $hooks = [];
        $eventEmitter = $mapper->eventEmitter();

        $eventEmitter->on('afterLoad', function ($post, $mapper) use (&$hooks): void {
            $hooks[] = 'after';
        });

        $mapper->create([
            'title' => 'A title',
            'body' => '<p>body</p>',
            'status' => 1,
            'author_id' => 1,
            'date_created' => new \DateTime(),
        ]);

        $this->assertEquals(['after'], $hooks);
        $eventEmitter->removeAllListeners();
    }

    public function testSaveEventsTriggeredOnUpdate(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Post::class);
        $eventEmitter = $mapper->eventEmitter();

        $hooks = [];
        $eventEmitter = $mapper->eventEmitter();
        $eventEmitter->on('beforeSave', function ($post, $mapper) use (&$hooks): void {
            $hooks[] = 'before';
        });
        $eventEmitter->on('afterSave', function ($post, $mapper) use (&$hooks): void {
            $hooks[] = 'after';
        });

        $post = $mapper->create([
            'title' => 'A title',
            'body' => '<p>body</p>',
            'status' => 1,
            'author_id' => 1,
            'date_created' => new \DateTime(),
        ]);

        $post->status = 2;

        $mapper->update($post);

        $this->assertEquals(['before', 'after', 'before', 'after'], $hooks);
        $eventEmitter->removeAllListeners();
    }
}
