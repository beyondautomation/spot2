<?php

declare(strict_types=1);

namespace SpotTest;

/**
 * @package Spot
 */
class RelationsEagerLoading extends \PHPUnit\Framework\TestCase
{
    private static $entities = ['PostTag', 'Post\Comment', 'Post', 'Tag', 'Author', 'Event\Search', 'Event'];

    public static function setUpBeforeClass(): void
    {
        foreach (self::$entities as $entity) {
            test_spot_mapper('\SpotTest\Entity\\' . $entity)->migrate();
        }

        // Fixtures for this test suite

        // Author
        $authorMapper = test_spot_mapper('SpotTest\Entity\Author');
        $author = $authorMapper->create([
            'email'    => 'test@test.com',
            'password' => 'password',
            'is_admin' => false,
        ]);

        // Posts
        $posts = [];
        $postsCount = 3;
        $mapper = test_spot_mapper('SpotTest\Entity\Post');
        for ($i = 1; $i <= $postsCount; $i++) {
            $posts[] = $mapper->create([
                'title'     => "Eager Loading Test Post $i",
                'body'      => "Eager Loading Test Post Content Here $i",
                'author_id' => $author->id,
            ]);
        }

        // 3 comments for each post
        foreach ($posts as $post) {
            $comments = [];
            $commentCount = 3;
            $commentMapper = test_spot_mapper('SpotTest\Entity\Post\Comment');
            for ($i = 1; $i <= $commentCount; $i++) {
                $comments[] = $commentMapper->create([
                    'post_id' => $post->id,
                    'name'    => 'Testy McTester',
                    'email'   => 'test@test.com',
                    'body'    => "This is a test comment $i. Yay!",
                ]);
            }
        }

        // Create some tags
        $tags = [];
        $tagCount = 3;
        $tagMapper = test_spot_mapper('SpotTest\Entity\Tag');
        for ($i = 1; $i <= $tagCount; $i++) {
            $tags[] = $tagMapper->create([
                'name' => "Tag {$i}",
            ]);
        }

        // Insert all tags for current post
        $postTagMapper = test_spot_mapper('SpotTest\Entity\PostTag');

        foreach ($posts as $post) {
            foreach ($tags as $tag) {
                $posttag_id = $postTagMapper->create([
                    'post_id' => $post->id,
                    'tag_id'  => $tag->id,
                ]);
            }
        }

        // Event
        $eventMapper = test_spot_mapper('SpotTest\Entity\Event');
        $event = $eventMapper->create([
            'title'         => 'Eager Load Test Event',
            'description'   => 'some test eager loading description',
            'type'          => 'free',
            'date_start'    => new \DateTime('+1 second'),
        ]);
        $event2 = $eventMapper->create([
            'title'         => 'Eager Load Test Event 2',
            'description'   => 'some test eager loading description 2',
            'type'          => 'free',
            'date_start'    => new \DateTime('+1 second'),
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$entities as $entity) {
            test_spot_mapper('\SpotTest\Entity\\' . $entity)->dropTable();
        }
    }

    public function testEagerLoadHasMany()
    {
        $mapper = test_spot_mapper('\SpotTest\Entity\Post');



        $posts = $mapper->all()->with('comments');

        foreach ($posts as $post) {
            foreach ($post->comments as $comment) {
                // Do nothing - just had to iterate to execute the queries
                $this->assertEquals($post->id, $comment->post_id);
            }
        }

    }

    public function testEagerLoadHasManyCounts()
    {
        $mapper = test_spot_mapper('\SpotTest\Entity\Post');



        $posts = $mapper->all()->order(['date_created' => 'DESC'])->with(['comments']);

        foreach ($posts as $post) {
            $this->assertEquals(3, count($post->comments));
        }

    }

    public function testEagerLoadBelongsTo()
    {
        $mapper = test_spot_mapper('\SpotTest\Entity\Post');



        $posts = $mapper->all()->with('author');

        foreach ($posts as $post) {
            $this->assertEquals($post->author_id, $post->author->id);
        }

    }

    public function testEagerLoadHasOne()
    {
        $mapper = test_spot_mapper('SpotTest\Entity\Event');



        $events = $mapper->all()->with('search');

        foreach ($events as $event) {
            $this->assertEquals($event->id, $event->search->event_id);
        }

    }

    public function testEagerLoadHasManyThrough()
    {
        $mapper = test_spot_mapper('SpotTest\Entity\Post');



        $posts = $mapper->all()->with('tags');

        foreach ($posts as $post) {
            foreach ($post->tags as $tags) {
                // Do nothing - just had to iterate to execute the queries
            }
            $this->assertEquals(3, count($post->tags));
        }

        // (1 query more than other relations, for the join table)
    }

    public function testEagerLoadHasManyThroughToArray()
    {
        $mapper = test_spot_mapper('SpotTest\Entity\Post');
        $post = $mapper->all()->with('tags')->first();
        $result = $post->toArray();

        $this->assertTrue(is_array($result['tags']));
    }

    public function testEagerLoadHasManyThroughToArrayShouldNotLoadRelation()
    {
        $mapper = test_spot_mapper('SpotTest\Entity\Post');
        $post = $mapper->all()->first();

        $result = $post->toArray();

        $this->assertFalse(isset($result['tags']));
    }

    public function testEagerLoadBelongsToArray()
    {
        $mapper = test_spot_mapper('\SpotTest\Entity\Post');
        $posts = $mapper->all()->with('author')->first();

        $result = $posts->toArray();

        $this->assertTrue(is_array($result['author']));
    }

    public function testEagerLoadBelongsToArrayShouldNotLoadRelation()
    {
        $mapper = test_spot_mapper('\SpotTest\Entity\Post');
        $posts = $mapper->all()->first();

        $result = $posts->toArray();

        $this->assertFalse(isset($result['author']));
    }

    public function testEagerLoadHasOneToArray()
    {
        $mapper = test_spot_mapper('SpotTest\Entity\Event');
        $events = $mapper->all()->with('search')->first();

        $result = $events->toArray();

        $this->assertTrue(is_array($result['search']));
    }

    public function testEagerLoadHasOneToArrayShouldNotLoadRelation()
    {
        $mapper = test_spot_mapper('SpotTest\Entity\Event');
        $events = $mapper->all()->first();

        $result = $events->toArray();

        $this->assertFalse(isset($result['search']));
    }

    public function testEagerLoadingEntityDepthIsLimitedToOneLevel()
    {
        // Retrieve a post
        $post_mapper = test_spot_mapper('\SpotTest\Entity\Post');
        $post = $post_mapper->get(1);

        // And its comments
        $comments = $post->comments->execute();
        $post->relation('comments', $comments);

        $comment_mapper = test_spot_mapper('\SpotTest\Entity\Post\Comment');
        $comment = $comment_mapper->create([
            'post_id' => 1,
            'name'    => 'Testy McTester',
            'email'   => 'test@test.com',
            'body'    => 'This is a test comment 4. Yay!',
        ]);
        $comment->relation('post', $post);
        $comments->add($comment);

        $result = $post->toArray();
        $this->assertFalse(isset($result['comments'][0]['post']['comments']));
        $this->assertCount(4, $result['comments']);
    }
}
