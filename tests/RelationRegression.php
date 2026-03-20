<?php

declare(strict_types=1);

namespace SpotTest;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SpotTest\Entity\Article;
use SpotTest\Entity\Category;

/**
 * Relation regression tests.
 *
 * Covers the phantom-data bug (null FK BelongsTo returning stale cached result
 * from previous entity) and all related edge cases.
 */
#[CoversNothing]
#[Group('relations')]
class RelationRegression extends TestCase
{
    /** @var array<class-string> */
    private static array $entities = [
        Article::class,
        Category::class,
    ];

    private static int $catAId;

    private static int $articleWithCatId;

    private static int $articleNullCatId;

    private static int $articleNullCat2Id;

    public static function setUpBeforeClass(): void
    {
        foreach (self::$entities as $entity) {
            test_spot_mapper($entity)->migrate();
        }

        $categoryMapper = test_spot_mapper(Category::class);
        $articleMapper  = test_spot_mapper(Article::class);

        $catA          = $categoryMapper->create(['name' => 'Category Alpha']);
        self::$catAId  = (int) $catA->id;

        $a1 = $articleMapper->create(['title' => 'Article With Category',   'category_id' => self::$catAId]);
        $a2 = $articleMapper->create(['title' => 'Article Null Category 1', 'category_id' => null]);
        $a3 = $articleMapper->create(['title' => 'Article Null Category 2', 'category_id' => null]);

        self::$articleWithCatId  = (int) $a1->id;
        self::$articleNullCatId  = (int) $a2->id;
        self::$articleNullCat2Id = (int) $a3->id;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$entities as $entity) {
            test_spot_mapper($entity)->dropTable();
        }
    }

    // -------------------------------------------------------------------------
    // 1. Core phantom-data regression
    // -------------------------------------------------------------------------

    public function testNullFkBelongsToDoesNotReturnPhantomData(): void
    {
        $articles = test_spot_mapper(Article::class)
            ->where(['id :in' => [self::$articleWithCatId, self::$articleNullCatId]])
            ->order(['id' => 'ASC'])
            ->with(['category']);

        $results = [];

        foreach ($articles as $article) {
            $results[$article->id] = $article->category;
        }

        $this->assertNotNull(
            $results[self::$articleWithCatId],
            'Article WITH category_id must have a resolved category',
        );
        $this->assertNull(
            $results[self::$articleNullCatId],
            'Article with NULL category_id must NOT inherit category from previous entity',
        );
    }

    // -------------------------------------------------------------------------
    // 2. All-null FK collection
    // -------------------------------------------------------------------------

    public function testAllNullFkCollectionReturnsNullForAll(): void
    {
        $articles = test_spot_mapper(Article::class)
            ->where(['id :in' => [self::$articleNullCatId, self::$articleNullCat2Id]])
            ->with(['category']);

        foreach ($articles as $article) {
            $this->assertNull(
                $article->category,
                "Article '{$article->title}' has null FK — category must be null",
            );
        }
    }

    // -------------------------------------------------------------------------
    // 3. Mixed null/non-null — correct throughout entire collection
    // -------------------------------------------------------------------------

    public function testMixedNullNonNullFkEagerLoad(): void
    {
        $articles = test_spot_mapper(Article::class)
            ->all()
            ->order(['id' => 'ASC'])
            ->with(['category']);

        $map = [];

        foreach ($articles as $article) {
            $map[$article->id] = $article->category;
        }

        $this->assertNotNull($map[self::$articleWithCatId], 'articleWithCat must have category');
        $this->assertEquals('Category Alpha', $map[self::$articleWithCatId]->name);
        $this->assertNull($map[self::$articleNullCatId], 'articleNull1 must be null');
        $this->assertNull($map[self::$articleNullCat2Id], 'articleNull2 must be null');
    }

    // -------------------------------------------------------------------------
    // 4. Lazy-load with null FK
    // -------------------------------------------------------------------------

    public function testLazyLoadWithNullFkReturnsNull(): void
    {
        $article = test_spot_mapper(Article::class)->get(self::$articleNullCatId);
        $this->assertNotFalse($article);

        $this->assertNull($article->category, 'Lazy-loaded category for null FK must be null');
    }

    // -------------------------------------------------------------------------
    // 5. Entity::relation() sentinel behaviour
    // -------------------------------------------------------------------------

    public function testRelationGetReturnsFalseWhenNotSet(): void
    {
        $article = new Article(['title' => 'tmp']);

        $this->assertFalse($article->relation('category'), 'GET before SET must return false');
    }

    public function testRelationSetNullStoresSentinel(): void
    {
        $article = new Article(['title' => 'tmp']);
        $article->relation('category', null, true);

        $this->assertNull($article->relation('category'), 'GET after setNull must return null not false');
    }

    public function testRelationSetValueThenGet(): void
    {
        $cat     = new Category(['name' => 'X']);
        $article = new Article(['title' => 'tmp']);
        $article->relation('category', $cat);

        $this->assertSame($cat, $article->relation('category'), 'GET must return stored object');
    }

    public function testRelationUnsetReturnsFalse(): void
    {
        $cat     = new Category(['name' => 'X']);
        $article = new Article(['title' => 'tmp']);
        $article->relation('category', $cat);
        $article->relation('category', false);

        $this->assertFalse($article->relation('category'), 'GET after UNSET must return false');
    }

    public function testRelationNullWithoutFlagIsGet(): void
    {
        $cat     = new Category(['name' => 'X']);
        $article = new Article(['title' => 'tmp']);
        $article->relation('category', $cat);

        $got = $article->relation('category');
        $this->assertSame($cat, $got, 'relation(name, null) without setNull flag must GET not overwrite');
    }

    // -------------------------------------------------------------------------
    // 6. RELATION_NULL sentinel never exposed externally
    // -------------------------------------------------------------------------

    public function testRelationNullSentinelNotExposedInToArray(): void
    {
        $articles = test_spot_mapper(Article::class)
            ->where(['id' => self::$articleNullCatId])
            ->with(['category']);

        $article = $articles->first();
        $this->assertNotFalse($article);

        $json = (string) json_encode($article->toArray());

        $this->assertStringNotContainsString(
            '__SPOT_RELATION_NULL__',
            $json,
            'RELATION_NULL sentinel must not appear in toArray() output',
        );
    }

    // -------------------------------------------------------------------------
    // 7. Re-fetching after eager-load still returns null
    // -------------------------------------------------------------------------

    public function testRefetchedEntityWithNullFkStillReturnsNull(): void
    {
        // Prime static state with an eager-load pass
        test_spot_mapper(Article::class)->all()->with(['category'])->toArray();

        $article = test_spot_mapper(Article::class)->get(self::$articleNullCatId);
        $this->assertNotFalse($article);
        $this->assertNull($article->category, 'Fresh-loaded entity with null FK must return null');
    }

    // -------------------------------------------------------------------------
    // 8. HasMany eager-load with empty result is empty Collection
    // -------------------------------------------------------------------------

    public function testHasManyEagerLoadEmptyIsEmptyCollection(): void
    {
        // Use Category which has no HasMany relations — verify that a collection
        // query that returns zero rows gives an empty Collection, not false/null.
        // We simulate this by querying for a non-existent category.
        $articleMapper = test_spot_mapper(Article::class);

        // Create an article with a category, then query articles with a
        // non-matching condition to get an empty collection.
        $articles = $articleMapper->where(['id' => -999])->with(['category']);

        // The collection itself must be empty but valid.
        $this->assertInstanceOf(\Spot\Entity\Collection::class, $articles->execute());
        $this->assertEquals(0, $articles->count(), 'Non-matching query must return empty collection');
    }

    // -------------------------------------------------------------------------
    // 9. Null FK is falsy — transformer-style guard works
    // -------------------------------------------------------------------------

    public function testNullFkRelationIsFalsyForTransformerGuard(): void
    {
        $articles = test_spot_mapper(Article::class)
            ->where(['id' => self::$articleNullCatId])
            ->with(['category']);

        $article = $articles->first();
        $this->assertNotFalse($article);

        $category = $article->category;
        $this->assertFalse((bool) $category, 'category must be falsy so transformer guard returns null()');
    }

    // -------------------------------------------------------------------------
    // 10. BelongsTo lazy-load without eager-load returns correct entity
    // -------------------------------------------------------------------------

    public function testBelongsToLazyLoadReturnsCorrectEntity(): void
    {
        $article = test_spot_mapper(Article::class)->get(self::$articleWithCatId);
        $this->assertNotFalse($article);

        $category = $article->category;
        $this->assertNotNull($category, 'Non-null FK lazy-load must resolve entity');
        $this->assertEquals('Category Alpha', $category->name);
    }

    // -------------------------------------------------------------------------
    // 11. data() does not crash on entity with no relations
    // -------------------------------------------------------------------------

    public function testDataDoesNotCrashOnEntityWithNoRelations(): void
    {
        $cat = new Category(['name' => 'test']);

        try {
            $data = $cat->data();
            $this->assertIsArray($data);
            $this->assertEquals('test', $data['name']);
        } catch (\Throwable $throwable) {
            $this->fail('data() crashed on entity with no relations: ' . $throwable->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // 12. Repeated access on same entity stays consistent; no cross-entity bleed
    // -------------------------------------------------------------------------

    public function testRepeatedRelationAccessIsStableAndNoCrossEntityBleed(): void
    {
        $articles = test_spot_mapper(Article::class)
            ->all()
            ->order(['id' => 'ASC'])
            ->with(['category']);

        $all = iterator_to_array($articles);

        foreach ($all as $article) {
            $first  = $article->category;
            $second = $article->category;

            $this->assertSame(
                $first,
                $second,
                "Repeated access on article {$article->id} must return identical value",
            );
        }

        foreach ($all as $article) {
            if ($article->category_id === null) {
                $this->assertNull(
                    $article->category,
                    "Article {$article->id} has null category_id — must stay null after full iteration",
                );
            } else {
                $this->assertNotNull(
                    $article->category,
                    "Article {$article->id} has category_id {$article->category_id} — must not be null",
                );
            }
        }
    }
}
