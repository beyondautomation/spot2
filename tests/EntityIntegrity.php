<?php

declare(strict_types=1);

namespace SpotTest;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SpotTest\Entity\Article;
use SpotTest\Entity\Author;
use SpotTest\Entity\Category;
use SpotTest\Entity\CustomPkChild;
use SpotTest\Entity\CustomPkParent;

/**
 * Entity integrity tests.
 *
 * Covers type conversion safety, PK handling, validation, and data lifecycle.
 */
#[CoversNothing]
#[Group('integrity')]
class EntityIntegrity extends TestCase
{
    /** @var array<class-string> */
    private static array $entities = [
        CustomPkParent::class,
        CustomPkChild::class,
        Article::class,
        Category::class,
    ];

    public static function setUpBeforeClass(): void
    {
        foreach (self::$entities as $entity) {
            test_spot_mapper($entity)->migrate();
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$entities as $entity) {
            test_spot_mapper($entity)->dropTable();
        }
    }

    // -------------------------------------------------------------------------
    // 1. DateTimeImmutable coerced for DBAL4
    // -------------------------------------------------------------------------

    public function testDateTimeImmutableCoercedTransparently(): void
    {
        $mapper    = test_spot_mapper(\SpotTest\Entity\Post::class);
        $immutable = new \DateTimeImmutable('2025-01-15 10:00:00');

        try {
            $post = $mapper->create([
                'title'        => 'DateTimeImmutable Test',
                'body'         => 'test body',
                'author_id'    => 1,
                'date_created' => $immutable,
            ]);
            $this->assertNotFalse($post, 'Insert with DateTimeImmutable must succeed');
        } catch (\Throwable $throwable) {
            $this->fail('Insert with DateTimeImmutable threw: ' . $throwable->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // 2. HasMany::save() uses primaryKey() not ->id
    // -------------------------------------------------------------------------

    public function testHasManySaveUsesCorrectPrimaryKeyNotHardcodedId(): void
    {
        $parentMapper = test_spot_mapper(CustomPkParent::class);
        $parent       = $parentMapper->create(['title' => 'PK Parent']);

        $this->assertNotFalse($parent, 'Parent insert failed');
        $this->assertNotNull($parent->post_id, 'post_id must be set after insert');

        $child = new CustomPkChild(['parent_id' => $parent->post_id, 'body' => 'child 1']);
        $parent->relation('children', new \Spot\Entity\Collection([$child]));

        try {
            $parentMapper->saveHasRelations($parent, []);
            $this->assertTrue(true, 'saveHasRelations completed without error');
        } catch (\Throwable $throwable) {
            $this->fail('saveHasRelations threw with non-standard PK: ' . $throwable->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // 3. Insert round-trip
    // -------------------------------------------------------------------------

    public function testInsertRoundTrip(): void
    {
        $mapper = test_spot_mapper(Category::class);
        $entity = $mapper->create(['name' => 'Round Trip Category']);

        $this->assertNotFalse($entity);

        $loaded = $mapper->get($entity->id);
        $this->assertNotFalse($loaded);
        $this->assertEquals('Round Trip Category', $loaded->name);
        $this->assertEquals($entity->id, $loaded->id);
    }

    // -------------------------------------------------------------------------
    // 4. Update only writes modified fields
    // -------------------------------------------------------------------------

    public function testUpdateOnlyWritesModifiedFields(): void
    {
        $mapper = test_spot_mapper(Category::class);
        $entity = $mapper->create(['name' => 'Original Name']);

        $entity->name = 'Updated Name';
        $this->assertTrue($entity->isModified('name'));

        $result = $mapper->update($entity);
        $this->assertNotFalse($result);

        $loaded = $mapper->get($entity->id);
        $this->assertEquals('Updated Name', $loaded->name);
    }

    // -------------------------------------------------------------------------
    // 5. isModified() state transitions
    // -------------------------------------------------------------------------

    public function testIsModifiedStateAfterSave(): void
    {
        $mapper = test_spot_mapper(Category::class);
        $entity = $mapper->create(['name' => 'Modification Test']);

        $this->assertFalse($entity->isModified(), 'After insert, entity must not be modified');

        $entity->name = 'Changed';
        $this->assertTrue($entity->isModified(), 'After field change, entity must be modified');
        $this->assertTrue($entity->isModified('name'), 'Specific field must be marked modified');

        $mapper->update($entity);
        $this->assertFalse($entity->isModified(), 'After update, entity must not be modified');
    }

    // -------------------------------------------------------------------------
    // 6. isNew() transitions through insert
    // -------------------------------------------------------------------------

    public function testIsNewTransitionsThroughInsert(): void
    {
        $mapper = test_spot_mapper(Category::class);
        $entity = new Category(['name' => 'New Entity']);

        $this->assertTrue($entity->isNew(), 'Before insert, entity must be new');

        $mapper->insert($entity);
        $this->assertFalse($entity->isNew(), 'After insert, entity must not be new');
    }

    // -------------------------------------------------------------------------
    // 7. Validation errors cleared between attempts
    // -------------------------------------------------------------------------

    public function testValidationErrorsCanBeClearedAndRetried(): void
    {
        $mapper = test_spot_mapper(Category::class);
        $entity = new Category([]);

        $result = $mapper->insert($entity);
        $this->assertFalse($result, 'Insert with missing required field must fail');
        $this->assertTrue($entity->hasErrors(), 'Errors must be set after failed validation');

        $entity->name = 'Fixed Name';
        $entity->errors([]);

        $result = $mapper->insert($entity);
        $this->assertNotFalse($result, 'Insert after fixing errors must succeed');
        $this->assertFalse($entity->hasErrors(), 'No errors after successful insert');
    }

    // -------------------------------------------------------------------------
    // 8. toArray() never contains RELATION_NULL sentinel
    // -------------------------------------------------------------------------

    public function testToArrayNeverContainsRelationNullSentinel(): void
    {
        $articleMapper = test_spot_mapper(Article::class);
        $articles      = $articleMapper->all()->with(['category']);

        foreach ($articles as $article) {
            $json = (string) json_encode($article->toArray());
            $this->assertStringNotContainsString(
                '__SPOT_RELATION_NULL__',
                $json,
                "toArray() for article {$article->id} must not contain sentinel",
            );
        }
    }

    // -------------------------------------------------------------------------
    // 9. Unique constraint validation catches duplicates
    // -------------------------------------------------------------------------

    public function testUniqueValidationCatchesDuplicate(): void
    {
        $mapper = test_spot_mapper(Author::class);
        $email  = 'unique-' . uniqid() . '@test.com';

        $mapper->create(['email' => $email, 'password' => 'pw', 'is_admin' => false]);

        $duplicate = new Author(['email' => $email, 'password' => 'pw2', 'is_admin' => false]);
        $result    = $mapper->insert($duplicate);

        $this->assertFalse($result, 'Duplicate unique field must fail insert');
        $this->assertTrue($duplicate->hasErrors('email'), 'email error must be set');
    }

    // -------------------------------------------------------------------------
    // 10. Required field validation blocks insert
    // -------------------------------------------------------------------------

    public function testRequiredFieldValidationBlocksInsert(): void
    {
        $mapper = test_spot_mapper(Category::class);
        $entity = new Category([]);

        $result = $mapper->insert($entity);
        $this->assertFalse($result, 'Missing required field must block insert');
        $this->assertTrue($entity->hasErrors('name'), 'name error must be set');
    }

    // -------------------------------------------------------------------------
    // 11. Upsert: insert then update on duplicate
    // -------------------------------------------------------------------------

    public function testUpsertInsertsFirstThenUpdatesOnDuplicate(): void
    {
        $mapper = test_spot_mapper(Category::class);
        $name   = 'Upsert-' . uniqid();

        $entity = $mapper->upsert(['name' => $name], ['name' => $name]);
        $this->assertNotNull($entity->id, 'First upsert must insert and assign PK');

        $updated = $mapper->upsert(['name' => $name], ['name' => $name]);
        $this->assertEquals($entity->id, $updated->id, 'Second upsert on same key must update, not duplicate');
    }

    // -------------------------------------------------------------------------
    // 12. Transaction rollback on exception
    // -------------------------------------------------------------------------

    public function testTransactionRollsBackOnException(): void
    {
        $mapper      = test_spot_mapper(Category::class);
        $countBefore = $mapper->all()->count();

        try {
            $mapper->transaction(function ($m): void {
                $m->create(['name' => 'Transaction Test']);

                throw new \RuntimeException('Deliberate rollback');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertEquals(
            $countBefore,
            $mapper->all()->count(),
            'Row count must be unchanged after rolled-back transaction',
        );
    }
}
